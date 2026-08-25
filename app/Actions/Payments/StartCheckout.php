<?php

namespace App\Actions\Payments;

use App\Actions\Orders\CreatePendingOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\ServiceVariant;
use App\Support\CheckoutSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

/**
 * The single authoritative path from "customer submitted checkout" to "order".
 *
 * Both the local mock and the real providers come through here, so the order
 * that a payment is raised against is built the same way in every case. The
 * sequence is fixed and server-side throughout:
 *
 *   re-verify the product against the published catalogue
 *     → re-verify the quantity
 *       → recompute the whole-dollar amount
 *         → create (or find) the one pending order and attempt
 *
 * ⛔ Nothing the browser submitted about price, amount, product, order status
 * or provider reference is read. The form supplies contact and invoice details
 * and nothing else.
 */
class StartCheckout
{
    public function __construct(
        private readonly CheckoutSession $checkout,
        private readonly CreatePendingOrder $createOrder,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{order: Order, token: string}|null null when the selection is gone or invalid
     */
    public function handle(Request $request, array $validated): ?array
    {
        // 商品一律取自 server-side session，⛔ 不接受表單送來的 variant／quantity。
        // resolve() 會重查 published allowlist、重驗數量並重新計價。
        $selection = $this->checkout->resolve($request);
        $token = $this->checkout->token($request);

        if ($selection === null || $token === null) {
            return null;
        }

        /*
         * ⭐ 這次是不是「重新結帳」？
         *
         * 上一張訂單的付款全部收斂為失敗／取消／逾期時，客人這一次有效送出是
         * 一次全新的結帳：必須有自己的 order、reference、token 與 attempt，
         * 舊訂單原樣保留為歷史。輪替 token 就是分界——`orderFor()` 只認 token。
         *
         * ⛔ 輪替發生在**這裡**，不在付款失敗的當下。取消或失敗時就先換掉，
         * 等於為一個可能永遠不會回來的客人預建一張幽靈訂單。
         *
         * ⛔ 輪替後才重讀 token：拿舊 token 去查，會查回剛剛決定要留作歷史的
         * 那張訂單，整個輪替就靜默地失效了。
         */
        if ($this->shouldStartNewOrder($token)) {
            $token = $this->checkout->rotateToken($request) ?? $token;
        }

        return [
            'order' => $this->orderFor($selection, $validated, $token),
            'token' => $token,
        ];
    }

    /**
     * Does this token point at an order the customer can no longer pay?
     *
     * 只有一種情況要開新單：訂單存在、尚未付款，而且它**每一筆**付款嘗試都已
     * 經收斂為 unpaid terminal（失敗／取消／逾期）。
     *
     * ⛔ 逐條說明為什麼不是別的判斷：
     *
     *  - 沒有訂單 → 這是第一次送出，`orderFor()` 本來就會建立第一張；在這裡
     *    輪替只會把剛拿到的 token 換掉，讓同一次結帳的雙擊各自建一張單。
     *  - 已付款 → 永遠不得再開一張。客人再按也只是回到同一張已付款的訂單。
     *  - 有 `pending` → provider 那邊可能有一個活著的付款 session。開新單等於
     *    請客人付第二次。
     *  - 有 `reconciliation_required` → 錢可能已經扣了。這不是失敗，是「不知道」，
     *    必須先人工對帳。
     *  - 有 `initiated` → 那筆嘗試還沒被 claim 也還沒結束，`ResolvePaymentAttempt`
     *    會直接接手它；這張訂單還活著，不是歷史。
     *  - 有 `succeeded` 但 order 尚未 paid → 資料不一致，⛔ 一律當作不可開新單：
     *    在「可能已收款」上面再疊一張新訂單是最不可挽回的方向。
     *
     * 也就是說，判斷用的是 allowlist（只有這三個 unpaid terminal 值算數），
     * ⛔ 不是「不是 pending 就開新單」那種 denylist——之後新增任何一個狀態，
     * denylist 會預設放行，allowlist 會預設擋下。
     */
    public function shouldStartNewOrder(string $token): bool
    {
        $order = Order::where('checkout_token', $token)->first();

        if ($order === null || $order->order_status === OrderStatus::Paid) {
            return false;
        }

        // ⛔ get() 之後才 pluck：直接在 query builder 上 pluck 會拿到未經 cast
        // 的原始字串，與 PaymentStatus 逐一比對就會全部不相等而永遠回 false。
        $attempts = $order->paymentAttempts()->get()->pluck('status');

        // ⛔ 一筆嘗試都沒有的訂單不算收斂：它從來沒被付過，維持原本的沿用行為。
        if ($attempts->isEmpty()) {
            return false;
        }

        return $attempts->every(fn (PaymentStatus $status) => in_array($status, [
            PaymentStatus::Failed,
            PaymentStatus::Canceled,
            PaymentStatus::Expired,
        ], true));
    }

    /**
     * The order for this checkout, created once.
     *
     * Two parallel submissions race here: both may find nothing and both may
     * try to insert. The unique index on checkout_token means the loser gets a
     * constraint violation rather than a second order, and simply reads the
     * winner's row back.
     *
     * @param  array{variant: ServiceVariant, quantity: int}  $selection
     * @param  array<string, mixed>  $validated
     */
    private function orderFor(array $selection, array $validated, string $token): Order
    {
        $existing = Order::where('checkout_token', $token)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->createOrder->handle(
                $selection['variant'],
                $selection['quantity'],
                $validated,
                $token,
                $validated['payment'],
            );
        } catch (UniqueConstraintViolationException) {
            // 另一個 request 先建立了同一次結帳的訂單。
            return Order::where('checkout_token', $token)->firstOrFail();
        }
    }
}
