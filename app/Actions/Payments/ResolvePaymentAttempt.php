<?php

namespace App\Actions\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;

/**
 * The attempt this payment should use, for this provider.
 *
 * A customer who tries ECPay, gets nowhere, and switches to LINE Pay must not
 * have their LINE payment recorded against the ECPay attempt — the provider
 * reference would belong to one system and the record to another, and no later
 * callback could be matched to it reliably.
 *
 * So an attempt is only reused when it belongs to the same provider and is
 * still safely reusable. Otherwise a new one is created and the old record is
 * kept, which is what "each payment keeps its own outcome" is supposed to mean.
 */
class ResolvePaymentAttempt
{
    /**
     * @return PaymentAttempt|null null when this order must not be paid again
     */
    public function handle(Order $order, string $provider): ?PaymentAttempt
    {
        return DB::transaction(function () use ($order, $provider) {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            // ⛔ 已付款的訂單不得再開新的付款嘗試。
            if ($locked->order_status === OrderStatus::Paid) {
                return null;
            }

            $existing = $locked->paymentAttempts()
                ->where('provider', $provider)
                ->latest('id')
                ->first();

            if ($existing !== null && $this->isReusable($existing)) {
                return $existing;
            }

            /*
             * ⛔ 進入待對帳的嘗試不得自動另開一筆。
             *
             * 那代表錢可能已經扣了。再給一次付款機會，等於邀請客人重複付款——
             * 必須由人確認前一筆到底成立與否。
             */
            if ($this->hasUncertainAttempt($locked)) {
                return null;
            }

            return $locked->paymentAttempts()->create([
                'provider' => $provider,
                'reference' => PaymentAttempt::newReference(),
                'status' => PaymentStatus::Initiated,
                'amount' => (int) $locked->total_amount,
                'currency' => $locked->currency ?: 'TWD',
            ]);
        });
    }

    /**
     * Can this attempt carry another initiation?
     *
     * ⛔ Only one that has not yet been handed to the provider. A `pending`
     * attempt already has a provider transaction behind it, so reusing it would
     * start a second payment against a reference the provider considers live.
     */
    private function isReusable(PaymentAttempt $attempt): bool
    {
        return $attempt->status === PaymentStatus::Initiated;
    }

    private function hasUncertainAttempt(Order $order): bool
    {
        return $order->paymentAttempts()
            ->where('status', PaymentStatus::ReconciliationRequired)
            ->exists();
    }
}
