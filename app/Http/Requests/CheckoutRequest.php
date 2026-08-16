<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The one set of checkout rules, shared by the mock and the real providers.
 *
 * Extracted from MockCheckoutController rather than copied: two sets of price,
 * product, invoice and PII rules would drift, and the one that drifted would
 * be the one nobody was testing.
 *
 * ⛔ Product and quantity are absent on purpose. They come from the server-side
 * session and are re-verified against the published catalogue; accepting them
 * here would let a form choose what it pays for.
 */
class CheckoutRequest extends FormRequest
{
    public const INVOICE_KINDS = ['personal', 'business'];

    public const PERSONAL_MODES = ['email', 'mobile_barcode', 'donation'];

    public const PAYMENTS = ['line-pay', 'ecpay'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // 條件驗證一律在後端重做，⛔ Alpine 的顯示／隱藏不具任何安全意義。
        // Rule::requiredIf 的 closure 不會收到參數，因此直接讀 request。
        $kind = $this->input('invoice_kind');
        $mode = $this->input('personal_invoice_mode');

        $isPersonal = fn (): bool => $kind === 'personal';
        $isBusiness = fn (): bool => $kind === 'business';
        $personalMode = fn (string $wanted): callable => fn (): bool => $kind === 'personal' && $mode === $wanted;

        return [
            // ⛔ variant 與 quantity 不在此驗證：它們來自 session，不接受表單覆寫。
            'target' => ['required', 'string', 'max:255'],
            'payment' => ['required', Rule::in(self::PAYMENTS)],

            // 聯絡資料：只收通知所需的最小欄位，⛔ 不要求姓名、地址或會員帳號。
            'customer_email' => ['required', 'email', 'max:80'],
            'customer_phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-() ]{6,20}$/'],

            'invoice_kind' => ['required', Rule::in(self::INVOICE_KINDS)],
            // 公司模式固定為無紙化 Email 電子載具。若仍送來「有值的」個人載具模式，
            // 代表前端狀態與發票類型矛盾，⛔ 必須明確失敗而非默默忽略。
            'personal_invoice_mode' => [
                Rule::requiredIf($isPersonal),
                Rule::prohibitedIf($isBusiness() && filled($mode)),
                Rule::excludeIf(! $isPersonal()),
                Rule::in(self::PERSONAL_MODES),
            ],

            // 手機條碼：`/` 加 7 碼大寫英數或 + - .
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

    /**
     * @return array<string, string>
     *
     * ⛔ 逐字沿用 MockCheckoutController 原本的訊息：這是搬移，不是改寫，
     * 客人看到的文字不該因為內部重構而變動。
     */
    public function messages(): array
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
}
