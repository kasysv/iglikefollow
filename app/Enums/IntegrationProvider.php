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

    /*
     * ⭐ 新訂單的 LINE 通知。
     *
     * ⛔ 與 `LinePay` **完全分開**，⛔ 不共用任何 credential。兩者都叫「LINE」
     * 但是兩個不同的產品、不同的主控台、不同的金鑰：LINE Pay 是金流，
     * 這個是 Messaging API 的推播。混用會讓一次改動同時影響收款與通知。
     */
    case LineOrderNotification = 'line_order_notification';

    public function label(): string
    {
        return match ($this) {
            self::EcpayPayment => '綠界付款',
            self::LinePay => 'LINE Pay',
            self::EcpayInvoice => '綠界電子發票',
            self::TheMostPanel => 'TheMostPanel 履約',
            self::LineOrderNotification => 'LINE 新訂單通知',
        };
    }

    /** 這個 provider 的公開識別碼欄位標籤；null 代表只有 secret。 */
    public function identifierLabel(): ?string
    {
        return match ($this) {
            self::EcpayPayment, self::EcpayInvoice => 'MerchantID',
            self::LinePay => 'Channel ID',
            /*
             * ⛔ 接收 ID 是**公開識別碼**，不是 secret：它是 U／C／R 開頭的
             * userId／groupId／roomId。放在 identifier 欄位（明文）而不是
             * credentials，因為 Owner 需要看得到自己填了哪一個對象——
             * 一個看不見的收件人設定，填錯了也不會有人發現。
             */
            self::LineOrderNotification => '接收 ID',
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
            /*
             * ⛔ **只存 Channel Access Token**。
             *
             * Push Message 只需要這一個；Channel Secret 只在 webhook 驗簽時
             * 才有用途，而本輪不新增 webhook。⛔ 存一個現在用不到的 secret，
             * 只是多一個會外洩的東西。
             */
            self::LineOrderNotification => ['ChannelAccessToken'],
            self::TheMostPanel => ['ApiKey'],
        };
    }

    /** 給人看的 secret 欄位名稱。 */
    public function secretLabel(string $key): string
    {
        return match ($key) {
            'ChannelSecret' => 'Channel Secret',
            'ChannelAccessToken' => 'Channel Access Token',
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
            /*
             * ⛔ LINE Messaging API 沒有本專案要用的 sandbox。提供一個假的
             * sandbox 等於發明一個不存在的安全測試環境，遲早有人拿它送真實訊息。
             */
            self::TheMostPanel, self::LineOrderNotification => [IntegrationEnvironment::Production],
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
