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
 * The one Owner-only path back from "no invoice yet" to a queued issue
 * attempt — for the narrow set of states where nothing has actually reached
 * ECPay yet.
 *
 * ⛔ Eligible only when there is no proof anything was sent: no invoice row,
 * a `pending_configuration` row with zero attempts, or a `pending` row with
 * zero attempts (a lost queue job). Everything else — `processing`, `issued`,
 * `voided`, any `failed` that already has an attempt, and
 * `reconciliation_required` — is refused here on purpose. `IssueInvoice`'s
 * own compare-and-set only ever claims a `pending` row, so this action's job
 * is solely to get an eligible row into that state and then hand off to the
 * existing, unmodified queue path — never to call ECPay itself.
 *
 * ⛔ Re-verified inside a DB transaction with the order row locked, never
 * trusting whatever the Livewire request claims was visible. Two Owners
 * clicking at once, or one Owner double-clicking, must still result in at
 * most one `IssueInvoiceForOrder` dispatch — the lock plus the eligibility
 * re-check make the second click a safe no-op.
 */
class QueueInvoiceRecoveryForOrder
{
    public const AUDIT_ACTION = 'invoice_recovery_queued';

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
             * ⛔ pending_configuration 且此刻 gateway 已就緒：原子轉成 pending，
             * 讓 IssueInvoice 的 compare-and-set 認得這一列。不改寫 attempts、
             * 不動任何已有的 issued/failure 欄位——這一分支的 row 從未有過
             * 任何嘗試。
             */
            if ($invoice->status === InvoiceStatus::PendingConfiguration) {
                $invoice->forceFill(['status' => InvoiceStatus::Pending])->save();
            }

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
     * ⛔ 只有「尚無任何嘗試」的三種安全狀態可補開；`processing`、`issued`、
     * `voided`、已有 attempt 的 `failed`，以及 `reconciliation_required`
     * 一律不合格——結果不明時必須先查詢／人工對帳，不能盲目重送。
     */
    private function isRecoverable(Invoice $invoice): bool
    {
        $hasAttempt = $invoice->attempts()->exists();

        return match ($invoice->status) {
            InvoiceStatus::PendingConfiguration, InvoiceStatus::Pending => ! $hasAttempt,
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
