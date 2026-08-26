<?php

namespace App\Services\Integrations;

/**
 * The exact URLs this site may ever contact, as literal constants.
 *
 * ⛔ 端點不是營運開關,是 SSRF 邊界。一個「可以在後台輸入的網址」等於這台
 * 伺服器會帶著我們的 credential 去連任何有人填進去的主機。所以它們寫在版本
 * 控制裡,改動必須經過 review——而 Owner 的開關不需要。
 *
 * ⛔ 比對整串,不解析、不正規化。逐段比對 host／path／scheme 看起來更嚴謹,
 * 實際上每多一段就多一個忘記檢查的機會:query、fragment、userinfo、port
 * 少查一個就是一個缺口。合法值只有這幾個,直接比對整串最難寫錯。
 *
 * 官方依據(2026-08-24 核對):
 *   https://developers.ecpay.com.tw/16449/   綠界全方位金流 V5
 *   https://developers.ecpay.com.tw/22040/   綠界 B2C 發票開立
 *   https://developers.ecpay.com.tw/22108/   綠界 B2C 發票查詢
 *   https://developers-pay.line.me/online-api-v4  LINE Pay Online API v4
 */
class ProviderEndpoints
{
    /** 綠界全方位金流 V5 收銀台。 */
    public const ECPAY_PAYMENT = 'https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5';

    /** LINE Pay Online API v4 base;⛔ 這是 API base,不是給人看的付款頁。 */
    public const LINE_PAY_API = 'https://api-pay.line.me';

    /** 綠界 B2C 電子發票開立。 */
    public const ECPAY_INVOICE_ISSUE = 'https://einvoice.ecpay.com.tw/B2CInvoice/Issue';

    /** 綠界 B2C 電子發票查詢;⛔ 唯讀,只用來確認是否已開出。 */
    public const ECPAY_INVOICE_QUERY = 'https://einvoice.ecpay.com.tw/B2CInvoice/GetIssue';

    /**
     * TheMostPanel 派單 API(R1)。
     *
     * ⛔ staging 與 production 用同一個正式端點、同一列 Owner credential、
     * 同一個 Owner 總開關;不再依 APP_ENV 拼接 config key——那種寫法意味著
     * 一個新環境名稱就是一個沒人 review 過的端點來源。
     */
    public const THEMOSTPANEL_DISPATCH = 'https://themostpanel.com/api/v2';

    /**
     * LINE Messaging API 的 Push Message 端點。
     *
     * ⛔ 固定在版本控制中，⛔ 後台、DB 與 env 都不可改。這個請求會帶著
     * Channel Access Token 送出——一個可被設定改寫的 URL，等於把金鑰送去
     * 任何人指定的主機。
     *
     * ⛔ 與 `LINE_PAY_API` 是**兩個不同的服務**，只是名字都有 LINE。
     *
     * 官方：https://developers.line.biz/en/reference/messaging-api/#send-push-message
     */
    public const LINE_PUSH_MESSAGE = 'https://api.line.me/v2/bot/message/push';

    /**
     * 可以把付款中的客人導去的主機。
     *
     * ⛔ 這是 allowlist,因為這個網址來自 HTTP 回應,而我們要把一個正在付款的
     * 客人送過去。少了這道檢查,一個被偽造的回應就讓結帳變成 open redirect
     * ——而且發生在客人正準備輸入卡號的那一刻。
     *
     * ⛔ 只有付款頁主機。`api-pay.line.me` 是 API 端點,不是任何人應該被送去
     * 的地方;把它列進來,等於允許把客人導向一個會回 JSON 的網址。
     */
    private const ALLOWED_REDIRECT_HOSTS = [
        'web-pay.line.me',
    ];

    /**
     * 設定值必須與白名單完全一致,否則 null。
     *
     * ⛔ 回 null 代表呼叫端必須 fail closed,不得改用預設值繼續。
     */
    public static function exact(string $configured, string $expected): ?string
    {
        return $configured === $expected ? $configured : null;
    }

    /** 綠界付款端點;⛔ 不符白名單即 null。 */
    public static function ecpayPayment(): ?string
    {
        return self::exact(
            (string) config('integrations.endpoints.ecpay_payment.production'),
            self::ECPAY_PAYMENT,
        );
    }

    /** LINE Pay API base;⛔ 不符白名單即 null。 */
    public static function linePayApi(): ?string
    {
        return self::exact(
            (string) config('integrations.endpoints.line_pay.production'),
            self::LINE_PAY_API,
        );
    }

    public static function ecpayInvoiceIssue(): ?string
    {
        return self::exact(
            (string) config('integrations.endpoints.ecpay_invoice.production'),
            self::ECPAY_INVOICE_ISSUE,
        );
    }

    public static function ecpayInvoiceQuery(): ?string
    {
        return self::exact(
            (string) config('integrations.endpoints.ecpay_invoice_query.production'),
            self::ECPAY_INVOICE_QUERY,
        );
    }

    /** TheMostPanel 派單端點;⛔ 不符白名單即 null,呼叫端 fail closed。 */
    public static function theMostPanelDispatch(): ?string
    {
        return self::exact(
            (string) config('integrations.endpoints.themostpanel.production'),
            self::THEMOSTPANEL_DISPATCH,
        );
    }

    /**
     * LINE 推播端點；⛔ 不符即 null，呼叫端必須 fail closed。
     *
     * ⛔ 只有 production 一列：LINE Messaging API 沒有本專案要用的 sandbox，
     * ⛔ 也不依 APP_ENV 拼接 config key——一個新環境名稱不該憑空成為一個
     * 沒人 review 過的端點來源。
     */
    public static function linePushMessage(): ?string
    {
        return self::exact(
            (string) config('integrations.endpoints.line_order_notification.production'),
            self::LINE_PUSH_MESSAGE,
        );
    }

    /**
     * 可以把客人導去這個網址嗎?
     *
     * ⛔ 只接受 HTTPS、白名單主機、且不含 userinfo 或非標準 port。
     * `https://web-pay.line.me@evil.example/` 的 host 是 `evil.example`,
     * parse_url 會正確拆開——但只比對字串前綴的寫法會被它騙過去。
     */
    public static function redirectIsAllowed(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return false;
        }

        if (($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        // ⛔ userinfo 與自訂 port 一律拒絕:兩者都是把讀者的眼睛騙過去的手法。
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        return in_array(strtolower($parts['host'] ?? ''), self::ALLOWED_REDIRECT_HOSTS, true);
    }
}
