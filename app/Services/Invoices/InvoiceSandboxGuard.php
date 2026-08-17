<?php

namespace App\Services\Invoices;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;

/**
 * One answer to "may we issue a sandbox invoice at all".
 *
 * Four things must all hold, and each is checked here rather than scattered
 * across the adapter, the client and the action — a guard with several copies
 * is a guard with several chances to be forgotten.
 *
 * ⛔ Production is refused whatever the flag says. This milestone covers the
 * stage environment only, so no config value alone may start issuing real tax
 * documents; that needs its own approval.
 */
class InvoiceSandboxGuard
{
    /** 總開關開啟、非 production，且環境確實允許。 */
    public static function enabled(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        return (bool) config('integrations.invoice.sandbox_enabled', false);
    }

    /**
     * The usable sandbox credential set, or null.
     *
     * ⛔ "There is a row in the database" is not enough: it must be enabled,
     * fully configured, and the endpoint must be present in the version-
     * controlled allowlist. A half-configured provider fails at their end with
     * a confusing error instead of here with a clear one.
     */
    public static function setting(): ?IntegrationSetting
    {
        if (! self::enabled()) {
            return null;
        }

        if (self::issueEndpoint() === null) {
            return null;
        }

        $setting = IntegrationSetting::query()
            ->where('provider', IntegrationProvider::EcpayInvoice)
            ->where('environment', IntegrationEnvironment::Sandbox)
            ->first();

        return $setting?->isUsable() ? $setting : null;
    }

    /**
     * The only two URLs this milestone may ever contact.
     *
     * ⛔ Whole URLs, not hosts. A host-only check accepts
     * `https://einvoice-stage.ecpay.com.tw/B2CInvoice/Invalid` — the same
     * trusted host, a completely different operation — and it accepts
     * `.../Issue?x=1`, `.../Issue#f` and `user@host` forms that a reader
     * skims past. Issuing is not an operation to reach by accident, so the
     * config value must match one of these strings exactly.
     */
    private const ISSUE_URL = 'https://einvoice-stage.ecpay.com.tw/B2CInvoice/Issue';

    private const QUERY_URL = 'https://einvoice-stage.ecpay.com.tw/B2CInvoice/GetIssue';

    /** ⛔ 端點必須與白名單完全一致；同主機不同 path 也拒絕。 */
    public static function issueEndpoint(): ?string
    {
        return self::allowlisted(
            (string) config('integrations.endpoints.ecpay_invoice.sandbox'),
            self::ISSUE_URL,
        );
    }

    public static function queryEndpoint(): ?string
    {
        return self::allowlisted(
            (string) config('integrations.endpoints.ecpay_invoice_query.sandbox'),
            self::QUERY_URL,
        );
    }

    /**
     * ⛔ 精確字串比對，不解析、不正規化。
     *
     * parse_url() 的逐段比對看起來更嚴謹，實際上每多一段就多一個忘記檢查的
     * 機會——query、fragment、userinfo、port 少查一個就是一個缺口。這裡只有
     * 兩個合法值，直接比對整串是最難寫錯的做法。
     */
    private static function allowlisted(string $endpoint, string $expected): ?string
    {
        return $endpoint === $expected ? $endpoint : null;
    }
}
