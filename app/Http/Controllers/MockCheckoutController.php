<?php

namespace App\Http\Controllers;

use App\Support\CheckoutSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MockCheckoutController extends Controller
{
    public const INVOICE_KINDS = ['personal', 'business'];

    public const PERSONAL_MODES = ['email', 'mobile_barcode', 'donation'];

    public function __construct(private readonly CheckoutSession $checkout) {}

    public function store(Request $request): View|RedirectResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        // 商品一律取自 server-side session，⛔ 不接受表單送來的 variant／quantity。
        // 這裡會重查 published allowlist、重驗數量並重新計價。
        $selection = $this->checkout->resolve($request);

        if ($selection === null) {
            return redirect()->route('checkout');
        }

        $variant = $selection['variant'];
        $quantity = $selection['quantity'];

        $validated = $request->validate($this->rules($request), $this->messages());

        // 送出後即清除選購資料，⛔ 重新整理或重送不得再產生任何東西。
        $this->checkout->forget($request);

        return view('storefront.mock-success', [
            'variantLabel' => $variant->label,
            'serviceName' => $variant->service->name,
            'platformName' => $variant->service->platform->name,
            'quantity' => $quantity,
            'quantityUnit' => $variant->quantity_unit,
            // 金額一律由伺服器依「當下」單價重算，⛔ 不採用 start 當時或前端的金額。
            'mockAmount' => $variant->amountFor($quantity),
            'target' => $validated['target'],
            'paymentLabel' => $validated['payment'] === 'line-pay' ? 'LINE Pay' : '綠界付款',
            // ⛔ 只回傳遮罩後的摘要；完整 Email／手機／載具／統編不得回顯或保存。
            'invoiceSummary' => $this->invoiceSummary($validated),
            'maskedEmail' => $this->maskEmail($validated['customer_email']),
            'maskedPhone' => $this->maskTail($validated['customer_phone'] ?? null),
        ]);
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

    /**
     * A human label for what kind of invoice would be issued.
     *
     * The success page shows only this label — never the carrier number, love
     * code or tax id — because the mock has no reason to echo identifiers back.
     *
     * @param  array<string, mixed>  $validated
     */
    private function invoiceSummary(array $validated): string
    {
        if ($validated['invoice_kind'] === 'business') {
            return '公司統編電子發票（統編後 3 碼 '.substr($validated['buyer_tax_id'], -3).'）';
        }

        return match ($validated['personal_invoice_mode'] ?? 'email') {
            'mobile_barcode' => '個人電子發票（手機條碼載具）',
            'donation' => '個人電子發票（捐贈）',
            default => '個人電子發票（寄送至 Email）',
        };
    }

    private function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $head = mb_substr($name, 0, 1);
        $visible = $head === '' ? '*' : $head;

        return $visible.str_repeat('*', max(mb_strlen($name) - 1, 1)).'@'.$domain;
    }

    private function maskTail(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value);

        return $digits === '' ? null : str_repeat('*', max(strlen($digits) - 3, 0)).substr($digits, -3);
    }
}
