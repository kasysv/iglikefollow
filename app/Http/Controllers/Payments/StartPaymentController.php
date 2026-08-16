<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\StartCheckout;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Payments\PaymentGatewayRegistry;
use App\Support\CheckoutSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Send a customer to a payment provider.
 *
 * The order exists before the provider is contacted, and it is created by the
 * same authoritative path the mock uses. ⛔ Nothing here marks anything paid:
 * starting a payment and completing one are different events, and only a
 * verified server-to-server result may do the second.
 */
class StartPaymentController extends Controller
{
    public function __construct(
        private readonly StartCheckout $startCheckout,
        private readonly PaymentGatewayRegistry $registry,
        private readonly CheckoutSession $checkout,
    ) {}

    public function store(CheckoutRequest $request): View|RedirectResponse
    {
        $validated = $request->validated();
        $gateway = $this->registry->for($validated['payment']);

        if ($gateway === null) {
            // ⛔ 沒開啟或沒有可用憑證就誠實擋下，不退回 Fake 假裝成功。
            return redirect()->route('checkout')
                ->withErrors(['payment' => '這個付款方式目前無法使用，請稍後再試或改選其他方式。']);
        }

        $result = $this->startCheckout->handle($request, $validated);

        if ($result === null) {
            return redirect()->route('checkout');
        }

        $order = $result['order'];
        $attempt = $this->openAttemptFor($order);

        if ($attempt === null) {
            // 這張訂單已經沒有可付款的嘗試（多半已付款）。
            return redirect()->route('payments.status', ['reference' => $order->reference]);
        }

        $initiation = $gateway->initiate($attempt);

        if ($initiation->isFailed()) {
            return redirect()->route('checkout')->withErrors([
                'payment' => $initiation->reason?->message() ?? '付款服務暫時無法使用。',
            ]);
        }

        // 走到這裡才算真的把客人交給 provider，選購資料可以清掉了。
        $this->checkout->forget($request);

        if ($initiation->isRedirect()) {
            // URL 已在 adapter 內以白名單驗證過。
            return redirect()->away($initiation->redirectUrl);
        }

        // ⛔ 用本站自動送出的表單，不把已簽章的欄位放進 query string：
        // 那會留在瀏覽器歷史、referrer 與沿途每一個 proxy log 裡。
        return view('payments.redirect', [
            'endpoint' => $initiation->endpoint,
            'fields' => $initiation->fields,
        ]);
    }

    /** 這張訂單目前還能付款的那一筆嘗試。 */
    private function openAttemptFor(Order $order): ?PaymentAttempt
    {
        return $order->paymentAttempts()
            ->whereIn('status', [PaymentStatus::Initiated, PaymentStatus::Pending])
            ->latest('id')
            ->first();
    }
}
