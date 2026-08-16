<?php

namespace App\Actions\Payments;

use App\Actions\Orders\RecordPaymentResult;
use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Models\PaymentAttempt;

/**
 * Close a claimed attempt that never became a payment session.
 *
 * ResolvePaymentAttempt marks an attempt `pending` before the provider is
 * contacted, so that a second request cannot start a parallel payment. That
 * claim has to be released when the initiation does not happen, or the order
 * becomes unpayable: the resolver keeps refusing to start anything while an
 * attempt is pending, and the customer is left with an error and no way to try
 * again — not even with a different provider.
 *
 * ⛔ Only for outcomes where no payment session can exist: nothing was sent, or
 * the provider explicitly refused. A request that was sent and lost is a
 * different thing entirely and belongs in reconciliation, because the money may
 * have moved.
 */
class FailPaymentInitiation
{
    public function __construct(private readonly RecordPaymentResult $recordResult) {}

    public function handle(PaymentAttempt $attempt, PaymentFailureReason $reason): PaymentAttempt
    {
        // 已經有結果就不再改寫；⛔ 也不會把成功的付款降級。
        if (! $attempt->status->isOpen()) {
            return $attempt;
        }

        /*
         * 走既有的 RecordPaymentResult，⛔ 不另外手寫一套狀態轉換：
         * 它已經負責寫入 completed_at、同步訂單付款狀態並建立事件，
         * 而訂單本身維持 pending_payment，客人仍可重新付款。
         */
        return $this->recordResult->handle(
            $attempt,
            PaymentStatus::Failed,
            // ⛔ 只存本地 allowlist 的代碼與訊息，不存 provider 原文。
            failureCode: $reason->value,
            failureMessage: $reason->message(),
        );
    }
}
