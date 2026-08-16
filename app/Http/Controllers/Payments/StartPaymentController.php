<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\ResolvePaymentAttempt;
use App\Actions\Payments\StartCheckout;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
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
        private readonly ResolvePaymentAttempt $resolveAttempt,
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

        // ⛔ 依「這次選的 provider」取得或建立嘗試，不是拿最新的任何一筆：
        // 用 LINE adapter 去處理 provider=ecpay 的嘗試，會讓交易編號屬於一個
        // 系統、紀錄屬於另一個，之後任何 callback 都對不回來。
        $attempt = $this->resolveAttempt->handle($order, $gateway->provider());

        if ($attempt === null) {
            // 已付款，或有待對帳的嘗試——兩種情況都不該再開始付款。
            return redirect()->route('payments.status', ['reference' => $order->reference]);
        }

        // 最後一道：adapter 只能處理屬於自己的嘗試。
        if ($attempt->provider !== $gateway->provider()) {
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
}
