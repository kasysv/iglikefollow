<?php

namespace App\Enums;

/**
 * The external services this site can hold credentials for.
 *
 * A closed set rather than a free-text column: the provider decides which
 * credential fields are accepted and which environments exist, so an
 * unrecognised value has no defined meaning and must not be storable.
 *
 * ⛔ ECPay payment and ECPay invoice are deliberately separate. They are
 * different merchant accounts with different keys, and treating them as one
 * would mean saving payment credentials over invoice credentials.
 */
enum IntegrationProvider: string
{
    case EcpayPayment = 'ecpay_payment';
    case LinePay = 'line_pay';
    case EcpayInvoice = 'ecpay_invoice';
    case TheMostPanel = 'themostpanel';

    public function label(): string
    {
        return match ($this) {
            self::EcpayPayment => '綠界付款',
            self::LinePay => 'LINE Pay',
            self::EcpayInvoice => '綠界電子發票',
            self::TheMostPanel => 'TheMostPanel 履約',
        };
    }

    /** 這個 provider 的公開識別碼欄位標籤；null 代表只有 secret。 */
    public function identifierLabel(): ?string
    {
        return match ($this) {
            self::EcpayPayment, self::EcpayInvoice => 'MerchantID',
            self::LinePay => 'Channel ID',
            self::TheMostPanel => null,
        };
    }

    /**
     * The secret field names this provider accepts.
     *
     * ⛔ This is an allowlist, not documentation: anything outside it is
     * rejected rather than stored, so a typo or a forged payload cannot quietly
     * add a field nobody validates.
     *
     * @return list<string>
     */
    public function secretKeys(): array
    {
        return match ($this) {
            self::EcpayPayment, self::EcpayInvoice => ['HashKey', 'HashIV'],
            self::LinePay => ['ChannelSecret'],
            self::TheMostPanel => ['ApiKey'],
        };
    }

    /** 給人看的 secret 欄位名稱。 */
    public function secretLabel(string $key): string
    {
        return match ($key) {
            'ChannelSecret' => 'Channel Secret',
            'ApiKey' => 'API Key',
            default => $key,
        };
    }

    /**
     * Which environments this provider genuinely offers.
     *
     * ⛔ TheMostPanel has no proven sandbox. Offering one would invent a safe
     * testing environment that does not exist, and someone would eventually
     * trust it with a real request.
     *
     * @return list<IntegrationEnvironment>
     */
    public function environments(): array
    {
        return match ($this) {
            self::TheMostPanel => [IntegrationEnvironment::Production],
            default => [IntegrationEnvironment::Sandbox, IntegrationEnvironment::Production],
        };
    }

    public function supports(IntegrationEnvironment $environment): bool
    {
        return in_array($environment, $this->environments(), true);
    }

    /** 後台顯示的限制說明；null 代表沒有額外限制。 */
    public function restrictionNote(): ?string
    {
        return match ($this) {
            /*
             * ⛔ 這裡輸入的就是正式帳戶的 key。
             *
             * ⛔ M4C-ADMIN-SMM-CATALOG-SYNC-A 之後,舊文案「僅供唯讀查詢使用,
             * 不會啟用自動派單」已與現行 runtime 矛盾:儲存 key 之後,
             * Owner 既可用「同步 SMM 服務」按鈕讀取服務清單,也可用下方的
             * 自動派單總開關實際啟用派單——兩者是各自獨立的開關,不是同一個
             * 決定,所以這裡只提醒金鑰本身的用途範圍,不再宣稱哪個功能被鎖死。
             */
            self::TheMostPanel => '這裡輸入的是正式帳戶金鑰；'
                .'用於服務目錄查詢與自動派單，實際是否派單仍由下方總開關決定。',
            default => null,
        };
    }
}
