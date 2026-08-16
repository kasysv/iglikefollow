<?php

namespace App\Http\Controllers;

use App\Actions\Orders\CreatePendingOrder;
use App\Actions\Orders\RecordPaymentResult;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\ServiceVariant;
use App\Support\CheckoutSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MockCheckoutController extends Controller
{
    public const INVOICE_KINDS = ['personal', 'business'];

    public const PERSONAL_MODES = ['email', 'mobile_barcode', 'donation'];

    public function __construct(
        private readonly CheckoutSession $checkout,
        private readonly CreatePendingOrder $createOrder,
        private readonly RecordPaymentResult $recordPayment,
    ) {}

    public function store(Request $request): View|RedirectResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        // 商品一律取自 server-side session，⛔ 不接受表單送來的 variant／quantity。
        // 這裡會重查 published allowlist、重驗數量並重新計價。
        $selection = $this->checkout->resolve($request);
        $token = $this->checkout->token($request);

        if ($selection === null || $token === null) {
            return redirect()->route('checkout');
        }

        $validated = $request->validate($this->rules($request), $this->messages());

        $order = $this->orderFor($selection, $validated, $token);

        // 訂單成立後才清除選購資料。
        // ⛔ 重新整理或重送會因為 checkout_token 已存在而拿回同一張訂單，不會建第二張。
        $this->checkout->forget($request);

        // Fake 付款結果：僅供 local／testing 觀察生命週期。
        // ⛔ 不呼叫綠界／LINE Pay，也不代表付款真的發生。
        $this->applyFakeResult($request, $order);

        return view('storefront.mock-success', ['order' => $order->fresh(['items', 'paymentAttempts', 'events'])]);
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

        $this->recordPayment->handle(
            $attempt,
            $outcome,
            providerReference: 'FAKE-'.$attempt->reference,
            failureCode: $outcome === PaymentStatus::Failed ? 'FAKE_DECLINED' : null,
            failureMessage: $outcome === PaymentStatus::Failed ? '模擬付款失敗' : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(Request $request): array
    {
        // 條件驗證一律在後端重做，⛔ Alpine 的顯示／隱藏不具任何安全意義。
        // Rule::requiredIf 的 closure 不會收到參數，因此直接讀 request。
        $kind = $request->input('invoice_kind');
        $mode = $request->input('personal_invoice_mode');

        $isPersonal = fn (): bool => $kind === 'personal';
        $isBusiness = fn (): bool => $kind === 'business';
        $personalMode = fn (string $wanted): callable => fn (): bool => $kind === 'personal' && $mode === $wanted;

        return [
            // ⛔ variant 與 quantity 不在此驗證：它們來自 session，不接受表單覆寫。
            'target' => ['required', 'string', 'max:255'],
            'payment' => ['required', Rule::in(['line-pay', 'ecpay'])],

            // 聯絡資料：只收通知所需的最小欄位，⛔ 不要求姓名、地址或會員帳號。
            'customer_email' => ['required', 'email', 'max:80'],
            'customer_phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-() ]{6,20}$/'],

            'invoice_kind' => ['required', Rule::in(self::INVOICE_KINDS)],
            // 公司模式固定為無紙化 Email 電子載具。若仍送來「有值的」個人載具模式，
            // 代表前端狀態與發票類型矛盾，⛔ 必須明確失敗而非默默忽略。
            // 空字串則視為未填（欄位 disabled 時瀏覽器本來就不會送出）。
            'personal_invoice_mode' => [
                Rule::requiredIf($isPersonal),
                Rule::prohibitedIf($isBusiness() && filled($mode)),
                Rule::excludeIf(! $isPersonal()),
                Rule::in(self::PERSONAL_MODES),
            ],

            // 手機條碼：`/` 加 7 碼大寫英數或 + - .
            // 切換模式後殘留的舊值一律排除，⛔ 不可跟著送出並被存成載具。
            'carrier_number' => [
                Rule::requiredIf($personalMode('mobile_barcode')),
                Rule::excludeIf(! $personalMode('mobile_barcode')()),
                'regex:/^\/[0-9A-Z+\-.]{7}$/',
            ],

            'love_code' => [
                Rule::requiredIf($personalMode('donation')),
                Rule::excludeIf(! $personalMode('donation')()),
                'regex:/^[0-9]{3,7}$/',
            ],

            // 公司統編：本輪只驗 8 碼格式，⛔ 不臆測公司是否真實存在。
            'buyer_tax_id' => [
                Rule::requiredIf($isBusiness),
                Rule::excludeIf(! $isBusiness()),
                'regex:/^[0-9]{8}$/',
            ],
            'buyer_name' => [
                Rule::requiredIf($isBusiness),
                Rule::excludeIf(! $isBusiness()),
                'string',
                'max:60',
            ],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'customer_email.required' => '請填寫 Email，訂單與電子發票通知會寄到這裡。',
            'customer_email.email' => 'Email 格式不正確。',
            'customer_phone.regex' => '手機號碼格式不正確，只能包含數字與 + - ( ) 空白。',
            'invoice_kind.required' => '請選擇發票類型。',
            'personal_invoice_mode.required' => '請選擇電子發票的存放方式。',
            'personal_invoice_mode.prohibited' => '公司統編發票固定寄送至 Email，不可選擇個人載具或捐贈。',
            'carrier_number.required' => '請填寫手機條碼。',
            'carrier_number.regex' => '手機條碼格式為 / 加 7 碼大寫英數字，例如 /ABC1234。',
            'love_code.required' => '請填寫捐贈碼。',
            'love_code.regex' => '捐贈碼為 3 至 7 位數字。',
            'buyer_tax_id.required' => '請填寫公司統一編號。',
            'buyer_tax_id.regex' => '統一編號必須是 8 位數字。',
            'buyer_name.required' => '請填寫公司或行號的登記名稱。',
        ];
    }

    // 發票摘要與遮罩改由 Order model 提供，⛔ 讓後台與 mock success 用同一份邏輯。
}
