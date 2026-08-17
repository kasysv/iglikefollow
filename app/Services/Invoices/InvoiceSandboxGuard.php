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

    /** ⛔ 端點只能來自 config allowlist，且必須是 HTTPS。 */
    public static function issueEndpoint(): ?string
    {
        return self::allowlisted((string) config('integrations.endpoints.ecpay_invoice.sandbox'));
    }

    public static function queryEndpoint(): ?string
    {
        return self::allowlisted((string) config('integrations.endpoints.ecpay_invoice_query.sandbox'));
    }

    /**
     * ⛔ 主機必須精確等於綠界的 stage 發票主機。
     *
     * 只比對「開頭」或「包含」都會被 `einvoice-stage.ecpay.com.tw.evil.example.com`
     * 這種近似域名騙過——那對人眼很像，對 parse_url() 則是完全不同的主機。
     */
    private static function allowlisted(string $endpoint): ?string
    {
        if ($endpoint === '') {
            return null;
        }

        $parts = parse_url($endpoint);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return null;
        }

        return strtolower($parts['host'] ?? '') === 'einvoice-stage.ecpay.com.tw'
            ? $endpoint
            : null;
    }
}
