<?php

namespace App\Actions\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;

/**
 * Claim the right to start one payment for this order.
 *
 * The name matters: this does not merely *find* an attempt, it takes exclusive
 * ownership of the act of paying. Under the order's row lock it either promotes
 * an unused attempt to `pending` or creates one already `pending`, so a second
 * request arriving at the same moment finds an in-flight payment and is turned
 * away rather than starting its own.
 *
 * ⛔ An earlier version created a *new* attempt whenever it found a pending one.
 * Two browser tabs, or one impatient customer, would each get an attempt and
 * each reach a provider — two live payment sessions for one order, and a real
 * chance of being charged twice. The test that covered it asserted the two ids
 * differed, which described the bug rather than catching it.
 *
 * ⛔ The claim commits before any HTTP happens. Holding a database transaction
 * open across a network call would pin the row for as long as the provider
 * takes to answer, which is exactly when it is slowest.
 */
class ResolvePaymentAttempt
{
    /**
     * @return PaymentAttempt|null null when this order must not start a payment now
     */
    public function handle(Order $order, string $provider): ?PaymentAttempt
    {
        return DB::transaction(function () use ($order, $provider) {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            // ⛔ 已付款的訂單永遠不得再開始付款。
            if ($locked->order_status === OrderStatus::Paid) {
                return null;
            }

            /*
             * ⛔ 只要有任何一筆「進行中」或「結果不明」的嘗試，就不得再開始，
             * 而且不分 provider。
             *
             * 進行中代表 provider 那邊已經有一個活著的付款；結果不明代表錢
             * 可能已經扣了。這兩種情況再開一筆，都是在請客人付第二次。
             */
            $blocking = $locked->paymentAttempts()
                ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::ReconciliationRequired])
                ->exists();

            if ($blocking) {
                return null;
            }

            // 同一 provider 還沒用過的那筆可以直接接手，⛔ 不必累積空紀錄。
            $reusable = $locked->paymentAttempts()
                ->where('provider', $provider)
                ->where('status', PaymentStatus::Initiated)
                ->latest('id')
                ->first();

            $attempt = $reusable ?? $locked->paymentAttempts()->create([
                'provider' => $provider,
                'reference' => PaymentAttempt::newReference(),
                'status' => PaymentStatus::Initiated,
                'amount' => (int) $locked->total_amount,
                'currency' => $locked->currency ?: 'TWD',
            ]);

            /*
             * ⭐ 這一步就是 claim：在同一個 lock 內把它推進 pending。
             *
             * 之後任何並行請求都會看到這筆 pending 而被上面的檢查擋下，
             * 所以「同時兩次送出」最多只有一個能真正呼叫 provider。
             */
            $attempt->forceFill(['status' => PaymentStatus::Pending])->save();

            return $attempt->fresh();
        });
    }
}
