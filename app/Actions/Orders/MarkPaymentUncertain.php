<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Models\OrderEvent;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;

/**
 * Park an attempt whose outcome we could not establish.
 *
 * A timeout, an unparseable response, or an amount that does not match what we
 * asked for. ⛔ None of these is a failure: the customer's card may well have
 * been charged, and the provider's records may already say so. Writing
 * "failed" would tell a charged customer their payment did not go through, and
 * a retry could take the money twice.
 *
 * So the attempt stops here and waits for a person. It is deliberately not
 * reachable through RecordPaymentResult, which exists to apply outcomes — this
 * is the absence of one.
 */
class MarkPaymentUncertain
{
    public function handle(PaymentAttempt $attempt, PaymentFailureReason $reason): PaymentAttempt
    {
        return DB::transaction(function () use ($attempt, $reason) {
            $locked = PaymentAttempt::query()
                ->whereKey($attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // ⛔ 已經有明確結果的嘗試不得被降級成「不明」：
            // 已成功的付款不能因為後續一個逾時就變成待對帳。
            if (! $locked->status->isOpen()) {
                return $locked;
            }

            $locked->forceFill([
                'status' => PaymentStatus::ReconciliationRequired,
                // ⛔ 只存本地 allowlist 的代碼與訊息。
                'failure_code' => $reason->value,
                'failure_message' => $reason->message(),
                // ⛔ 不寫 completed_at：這筆嘗試並沒有「完成」，只是無法確認。
            ])->save();

            $order = $locked->order()->lockForUpdate()->firstOrFail();

            // 已付款的訂單不受影響。
            if ($order->order_status !== OrderStatus::Paid) {
                $order->forceFill(['payment_status' => PaymentStatus::ReconciliationRequired])->save();

                $order->events()->create([
                    'type' => OrderEvent::TYPE_PAYMENT_FAILED,
                    'summary' => '付款結果無法確認（'.$reason->message().'）需人工對帳。',
                ]);
            }

            return $locked->fresh();
        });
    }
}
