<?php

namespace App\Actions\Orders;

use App\Enums\PaymentStatus;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;

/**
 * Move an attempt from "created" to "in flight".
 *
 * This exists so that "the customer has been sent to the payment page" is a
 * separate, explicit transition rather than something RecordPaymentResult has
 * to tolerate. The attempt stays open: no completion time is written and no
 * failure event is created, because nothing has failed — the payment simply
 * has not finished yet.
 */
class MarkPaymentPending
{
    public function handle(PaymentAttempt $attempt, ?string $providerReference = null): PaymentAttempt
    {
        return DB::transaction(function () use ($attempt, $providerReference) {
            $locked = PaymentAttempt::query()
                ->whereKey($attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // 已經有結果的嘗試不能倒退回付款中。
            if (! $locked->status->isOpen()) {
                return $locked;
            }

            $locked->forceFill([
                'status' => PaymentStatus::Pending,
                'provider_reference' => $providerReference ?? $locked->provider_reference,
                // ⛔ 不寫 completed_at：這筆嘗試尚未結束。
            ])->save();

            // 訂單付款狀態同步為付款中；訂單本身仍是待付款。
            $order = $locked->order()->lockForUpdate()->firstOrFail();

            if (! $order->isPaid()) {
                $order->forceFill(['payment_status' => PaymentStatus::Pending])->save();
            }

            return $locked->fresh();
        });
    }
}
