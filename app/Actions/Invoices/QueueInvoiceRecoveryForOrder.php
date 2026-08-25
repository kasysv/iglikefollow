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
 *
 * ⭐ 稽核與狀態轉換在同一個 transaction 內，且稽核先寫。
 *
 * ⛔ 初版把狀態轉換先 commit、之後才寫 audit：audit 失敗時 0 dispatch，看起來
 * 像安全地擋下了，但發票已經是 `pending` 且已有 attempt，資格判定從此永遠拒絕
 * ——沒有稽核、沒有 job、沒有 provider call，發票卻永久卡死，Owner 再也按不動。
 * fail closed 的定義是「什麼都沒發生」，不只是「沒有排 job」。
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

        $outcome = DB::transaction(function () use ($user, $order): string {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->isPaid()) {
                return 'blocked_unpaid';
            }

            $invoice = Invoice::query()->where('order_id', $locked->id)->lockForUpdate()->first();

            if ($invoice !== null && ! $this->isRecoverable($invoice)) {
                return 'blocked_not_eligible';
            }

            /*
             * ⭐ R1：稽核與狀態轉換在**同一個 transaction** 內，稽核先寫。
             *
             * ⛔ 初版把狀態轉換先 commit、之後才寫 audit。audit 失敗時 action
             * 回傳 blocked 且 0 dispatch，看起來像安全地擋下了——但發票已經是
             * `pending` 而且已經有 attempt，於是 `isRecoverable()` 的「pending
             * 必須 0 attempt」永遠不成立，Owner 再也按不動。沒有稽核、沒有
             * job、沒有 provider call，發票卻永久卡死。
             *
             * 順序也重要：先寫 audit 再改狀態。audit 失敗時直接拋出，整個
             * transaction（含尚未發生的狀態轉換）一起回滾，什麼都沒動過。
             *
             * ⛔ fail closed 的定義是「什麼都沒發生」，不只是「沒有排 job」。
             */
            if (! $this->recordAudit($user, $order)) {
                return 'blocked_audit_unavailable';
            }

            if ($invoice === null) {
                // 尚無 invoice row：直接排入，讓既有 job 走 CreateInvoiceForPaidOrder。
                return 'queued';
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

        /*
         * ⛔ 到這裡時上面的 DB::transaction() 早已 commit,而且稽核與狀態轉換
         * 是在同一個 transaction 內一起 commit 的——只有兩者都成功才會走到這
         * 一行。真正重要的順序保證是「先 commit 狀態轉換與稽核、才 dispatch
         * job」,而不是反過來讓 job 有機會在寫入生效前就跑。
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
     *
     * ⭐ R1：這個方法現在在 `handle()` 的 transaction 內、且在任何狀態轉換
     * **之前**被呼叫。
     *
     * ⛔ 例外仍在這裡被吃掉並轉成 `false`,而不是往外拋。這是刻意的:讓
     * `handle()` 用一個明確的 `return 'blocked_audit_unavailable'` 結束
     * closure,而不是靠例外穿過 `DB::transaction()`。兩者都會讓外界看不到任何
     * 變更——因為此刻**還沒有任何寫入發生**——但明確 return 讓「稽核不可用」
     * 是一個被處理過的結果,不是一個從底層漏出來的錯誤。
     *
     * ⛔ 順序就是保證本身:audit 若失敗,狀態轉換那幾行根本不會執行。
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
