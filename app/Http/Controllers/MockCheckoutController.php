<?php

namespace App\Http\Controllers;

use App\Actions\Orders\MarkPaymentPending;
use App\Actions\Orders\RecordPaymentResult;
use App\Actions\Payments\StartCheckout;
use App\Enums\PaymentStatus;
use App\Http\Requests\CheckoutRequest;
use App\Models\Order;
use App\Support\CheckoutSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The local mock checkout.
 *
 * ⛔ Validation and order creation are no longer here: they live in
 * CheckoutRequest and StartCheckout, shared with the real payment providers.
 * What remains is the one thing that is genuinely mock-only — pretending a
 * provider reported a result.
 */
class MockCheckoutController extends Controller
{
    /** @deprecated 改用 CheckoutRequest 的常數；保留供既有測試引用。 */
    public const INVOICE_KINDS = CheckoutRequest::INVOICE_KINDS;

    /** @deprecated 改用 CheckoutRequest 的常數；保留供既有測試引用。 */
    public const PERSONAL_MODES = CheckoutRequest::PERSONAL_MODES;

    public function __construct(
        private readonly CheckoutSession $checkout,
        private readonly StartCheckout $startCheckout,
        private readonly RecordPaymentResult $recordPayment,
        private readonly MarkPaymentPending $markPending,
    ) {}

    public function store(CheckoutRequest $request): View|RedirectResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        // ⛔ 驗證規則與建單流程都改由共用的 CheckoutRequest／StartCheckout 提供。
        // 兩套價格、商品、發票與個資驗證一定會漂移，而漂移的那一套沒有人在測。
        $result = $this->startCheckout->handle($request, $request->validated());

        if ($result === null) {
            return redirect()->route('checkout');
        }

        $order = $result['order'];

        // 訂單成立後才清除選購資料。
        // ⛔ 重新整理或重送會因為 checkout_token 已存在而拿回同一張訂單，不會建第二張。
        $this->checkout->forget($request);

        // Fake 付款結果：僅供 local／testing 觀察生命週期。
        // ⛔ 不呼叫綠界／LINE Pay，也不代表付款真的發生。
        $this->applyFakeResult($request, $order);

        return view('storefront.mock-success', ['order' => $order->fresh(['items', 'paymentAttempts', 'events'])]);
    }

    /**
     * Simulate what a payment provider would later report.
     *
     * Real payment status may only arrive through a verified server-to-server
     * callback, so this exists purely to exercise the lifecycle locally; the
     * outcome is chosen by a test-only field and defaults to success.
     */
    private function applyFakeResult(Request $request, Order $order): void
    {
        $attempt = $order->paymentAttempts()
            ->whereIn('status', [PaymentStatus::Initiated, PaymentStatus::Pending])
            ->latest('id')
            ->first();

        if ($attempt === null) {
            return;
        }

        $outcome = PaymentStatus::tryFrom((string) $request->input('fake_payment_result'))
            ?? PaymentStatus::Succeeded;

        /*
         * 「付款中」是一個獨立的轉換，不是一種結果。
         *
         * ⛔ 不可交給 RecordPaymentResult：那會寫入 completed_at 並建立一筆
         * 付款失敗事件，把還在進行中的付款誤記成已失敗。
         */
        if (! $outcome->isTerminal()) {
            $this->markPending->handle($attempt, 'FAKE-'.$attempt->reference);

            return;
        }

        $this->recordPayment->handle(
            $attempt,
            $outcome,
            providerReference: 'FAKE-'.$attempt->reference,
            failureCode: $outcome === PaymentStatus::Failed ? 'FAKE_DECLINED' : null,
            failureMessage: $outcome === PaymentStatus::Failed ? '模擬付款失敗' : null,
        );
    }

    // 發票摘要與遮罩改由 Order model 提供，⛔ 讓後台與 mock success 用同一份邏輯。
}
