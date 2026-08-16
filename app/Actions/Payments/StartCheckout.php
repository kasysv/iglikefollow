<?php

namespace App\Actions\Payments;

use App\Actions\Orders\CreatePendingOrder;
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

        return [
            'order' => $this->orderFor($selection, $validated, $token),
            'token' => $token,
        ];
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
