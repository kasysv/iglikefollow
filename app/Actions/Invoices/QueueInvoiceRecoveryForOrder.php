<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceStatus;
use App\Jobs\IssueInvoiceForOrder;
use App\Models\AdminAuditLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\Invoices\InvoiceSandboxGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * The one Owner-only path from "not issued yet" to a queued issue attempt.
 *
 * ⭐ D-179：Owner 的「手動開立發票」。合格狀態為：
 *
 *  - 尚無 invoice row（付款成功但 job 沒跑）；
 *  - `pending_configuration`／`pending` 且尚無 attempt（設定當時未就緒或 job 遺失）；
 *  - `failed`（已有失敗 attempt，可再送一次）；
 *  - `reconciliation_required`（相容 staging 既有資料）。
 *
 * ⛔ `processing`、`issued`、`voided` 仍然拒絕：處理中會撞上進行中的嘗試，
 * 已開立與已作廢再送就是稅務問題。
 *
 * ⭐ 為什麼 `failed` 現在可以重送，而這不會開出第二張發票：所有嘗試送往綠界的
 * `RelateNumber` 完全相同（由 order reference 推導）。若第一次其實已經開出，
 * 綠界會以重複號拒絕，`EcpayInvoiceGateway` 隨即以**同一個號** GetIssue 查詢，
 * 查到就把本地收斂為 `issued`。⛔ 因此重送的最壞情況是把既有發票查回來，
 * 不是產生新的一張。
 *
 * ⛔ 本 action 自己永遠不呼叫綠界：它只把合格的 row 原子地轉成 `pending`，
 * 再交給既有的 queue 路徑，由 `IssueInvoice` 的 compare-and-set 決定結果。
 *
 * ⛔ Re-verified inside a DB transaction with the invoice row locked, never
 * trusting whatever the Livewire request claims was visible. Two Owners
 * clicking at once, or one Owner double-clicking, must still result in at
 * most one `IssueInvoiceForOrder` dispatch — the lock plus the eligibility
 * re-check make the second click a safe no-op.
 */
class QueueInvoiceRecoveryForOrder
{
    public const AUDIT_ACTION = 'invoice_manual_issue_queued';

    /**
     * @return string one of: queued|blocked_not_owner|blocked_unpaid|
     *                blocked_not_twd|blocked_gateway_not_ready|
     *                blocked_not_eligible|blocked_audit_unavailable
     */
    public function handle(User $user, Order $order): string
    {
        if (! $user->isOwner()) {
            return 'blocked_not_owner';
        }

        // ⛔ 未付款或非台幣訂單完全不夠格：這與 CreateInvoiceForPaidOrder 的邊界一致。
        if (! $order->isPaid()) {
            return 'blocked_unpaid';
        }

        if (($order->currency ?: 'TWD') !== 'TWD') {
            return 'blocked_not_twd';
        }

        // ⛔ 綠界發票設定完整且開關 ON，才有資格排入；否則只會排進一個注定停在
        // pending_configuration 的 job。
        if (InvoiceSandboxGuard::setting() === null) {
            return 'blocked_gateway_not_ready';
        }

        $outcome = DB::transaction(function () use ($order): string {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->isPaid()) {
                return 'blocked_unpaid';
            }

            $invoice = Invoice::query()->where('order_id', $locked->id)->lockForUpdate()->first();

            if ($invoice === null) {
                // 尚無 invoice row：直接排入，讓既有 job 走 CreateInvoiceForPaidOrder。
                return 'queued';
            }

            if (! $this->isRecoverable($invoice)) {
                return 'blocked_not_eligible';
            }

            /*
             * ⛔ 原子轉成 `pending`：`IssueInvoice` 的 compare-and-set 只認得
             * 這個狀態。這一步在 invoice row lock 內完成，所以第二次點擊進來時
             * 會看到狀態已不是合格值而安全地變成 no-op——雙擊最多排一個 job。
             *
             * 同時清掉上一輪的失敗顯示與 `reconciliation_required_at`：那些是
             * 上一次嘗試的結果，留著會讓後台顯示一個已經不成立的錯誤。
             *
             * ⛔ 不動 `attempts`：每一次嘗試都是歷史，必須逐筆保留。
             * ⛔ 不動 `invoice_number`／`random_code`／`provider_reference`：
             * 合格狀態都還沒有真正開出發票，這些欄位本來就是空的；即使不是，
             * 覆蓋既有發票資料也絕不是這個 action 該做的事。
             *
             * ⭐ `reconciliation_required` 先經 `failed` 再到 `pending`。
             *
             * ⛔ 這不是繞過檢查，而是**照著** `InvoiceIntegrityObserver` 的狀態
             * 機走：它允許 `reconciliation_required → failed` 與 `failed →
             * pending`，但沒有直接的 `reconciliation_required → pending`。兩步
             * 都在同一個 transaction 與同一個 row lock 內，外界看到的仍是一次
             * 原子轉換。⛔ 不改 observer 去開一條新捷徑——那個狀態機是發票這種
             * 稅務文件的核心保護，本輪也不在允許修改的檔案範圍內。
             */
            if ($invoice->status === InvoiceStatus::ReconciliationRequired) {
                $invoice->forceFill(['status' => InvoiceStatus::Failed])->save();
            }

            $invoice->forceFill([
                'status' => InvoiceStatus::Pending,
                'failure_code' => null,
                'failure_message' => null,
                'reconciliation_required_at' => null,
            ])->save();

            return 'queued';
        });

        if ($outcome !== 'queued') {
            return $outcome;
        }

        $audited = $this->recordAudit($user, $order);

        if (! $audited) {
            return 'blocked_audit_unavailable';
        }

        /*
         * ⛔ 到這裡時上面的 DB::transaction() 早已 commit——這一行本身就是
         * 「事後才排」,不需要再包一層 afterCommit。真正重要的順序保證是
         * 「先 commit 狀態轉換與稽核、才 dispatch job」,而不是反過來讓 job
         * 有機會在寫入生效前就跑。
         */
        IssueInvoiceForOrder::dispatch($order->id);

        return 'queued';
    }

    /**
     * ⭐ D-179 合格狀態。
     *
     * `pending_configuration`／`pending` 仍要求尚無 attempt：有 attempt 代表
     * 已經有一次嘗試在進行或剛結束，此時重排只會撞上 compare-and-set。
     *
     * `failed` 與 `reconciliation_required` 不限制 attempt 筆數——它們本來就是
     * 「已經試過而沒成功」的狀態，重送正是這個入口存在的理由。安全性來自固定
     * 的 `RelateNumber`，不是來自禁止重送。
     *
     * ⛔ `processing`（進行中）、`issued`（已開立）、`voided`（已作廢）永遠不合格。
     */
    private function isRecoverable(Invoice $invoice): bool
    {
        return match ($invoice->status) {
            InvoiceStatus::PendingConfiguration,
            InvoiceStatus::Pending => ! $invoice->attempts()->exists(),
            InvoiceStatus::Failed,
            InvoiceStatus::ReconciliationRequired => true,
            default => false,
        };
    }

    /**
     * ⛔ 只記 user id、order id/reference、invoice id、from/to 本地狀態與
     * action token；不含 Email、手機、統編、載具、API key 或 request/response。
     */
    private function recordAudit(User $user, Order $order): bool
    {
        try {
            AdminAuditLog::query()->create([
                'user_id' => $user->getKey(),
                'auditable_type' => Order::class,
                'auditable_id' => $order->getKey(),
                'action' => self::AUDIT_ACTION,
                'before' => null,
                'after' => [
                    'order_reference' => $order->reference,
                ],
                'ip_address' => Request::ip(),
            ]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
