<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;

/**
 * Apply a payment outcome to an attempt, and promote the order if it succeeded.
 *
 * This is the only path that may mark an order paid. A browser returning from
 * a payment page proves nothing — only a verified server-to-server result
 * reaches here — and the promotion happens under a row lock so two callbacks
 * arriving together cannot both fulfil the same order.
 */
class RecordPaymentResult
{
    public function handle(
        PaymentAttempt $attempt,
        PaymentStatus $status,
        ?string $providerReference = null,
        ?string $failureCode = null,
        ?string $failureMessage = null,
    ): PaymentAttempt {
        return DB::transaction(function () use ($attempt, $status, $providerReference, $failureCode, $failureMessage) {
            // 鎖住這筆嘗試；⛔ 並行的重複通知必須排隊而不是同時進來。
            $locked = PaymentAttempt::query()
                ->whereKey($attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // 已經有結果的嘗試不再改寫：重複通知在這裡就停下來。
            if (! $locked->status->isOpen()) {
                return $locked;
            }

            $locked->forceFill([
                'status' => $status,
                'provider_reference' => $providerReference ?? $locked->provider_reference,
                // ⛔ 只存遮罩後的代碼與訊息，不存完整 provider payload。
                'failure_code' => $failureCode,
                'failure_message' => $failureMessage,
                'completed_at' => now(),
            ])->save();

            $order = $locked->order()->lockForUpdate()->firstOrFail();

            if ($status === PaymentStatus::Succeeded) {
                $this->promoteToPaid($order, $locked);
            } else {
                $this->recordUnsuccessful($order, $locked, $status);
            }

            return $locked->fresh();
        });
    }

    /**
     * Move the order to paid exactly once.
     *
     * The order_paid event carries a unique key, so if a second successful
     * notification ever slipped past the attempt-level guard the database
     * would still refuse to create a second fulfilment seam.
     */
    private function promoteToPaid(Order $order, PaymentAttempt $attempt): void
    {
        if ($order->order_status === OrderStatus::Paid) {
            return;
        }

        $order->forceFill([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'paid_at' => now(),
        ])->save();

        $order->events()->create([
            'type' => OrderEvent::TYPE_ORDER_PAID,
            'unique_key' => OrderEvent::TYPE_ORDER_PAID,
            'summary' => '付款成功，訂單轉為已付款。',
        ]);

        // M4A 的交接點。⛔ 本輪只發事件，不呼叫 TheMostPanel。
        OrderPaid::dispatch($order->fresh());
    }

    /**
     * Record a failure, cancellation or expiry.
     *
     * The order itself stays pending_payment so the customer can retry; only
     * the attempt carries the outcome. An order that has already been paid is
     * never downgraded by a late failure for an earlier attempt.
     */
    private function recordUnsuccessful(Order $order, PaymentAttempt $attempt, PaymentStatus $status): void
    {
        if ($order->order_status === OrderStatus::Paid) {
            return;
        }

        $order->forceFill(['payment_status' => $status])->save();

        $order->events()->create([
            'type' => match ($status) {
                PaymentStatus::Canceled => OrderEvent::TYPE_PAYMENT_CANCELED,
                PaymentStatus::Expired => OrderEvent::TYPE_PAYMENT_EXPIRED,
                default => OrderEvent::TYPE_PAYMENT_FAILED,
            },
            'summary' => '付款未完成（'.$status->label().'）。訂單保留，可重新付款。',
        ]);
    }
}
