<?php

namespace App\Services\Invoices;

use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use App\Services\Integrations\LiveIntegration;
use App\Services\Integrations\ProviderEndpoints;

/**
 * One answer to "may we issue an invoice at all".
 *
 * Three things must all hold, and each is checked here rather than scattered
 * across the adapter, the client and the action — a guard with several copies
 * is a guard with several chances to be forgotten.
 *
 * ⛔ M4C 之後這裡不再讀 `INVOICE_SANDBOX_ENABLED` 或 `INVOICE_GATEWAY`。
 * 發票要不要開,只由 Owner 在後台切換 production
 * `integration_settings.is_enabled` 決定。
 *
 * ⛔ 發票開關與付款開關是分開的兩件事,而且順序有意義:發票關閉時付款仍然
 * 可以成功,只是不送開票請求。反過來把它們綁在一起,等於「不想開發票」就
 * 收不到錢。
 *
 * ⛔ 類別名稱保留「Sandbox」是刻意的取捨:改名會擴大這一輪的 diff,而這一輪
 * 要證明的是行為改變。名稱已不精確,由這段說明取代。
 */
class InvoiceSandboxGuard
{
    /**
     * Owner 是否已開啟發票通道,且這個環境可以外呼。
     *
     * ⛔ 不含端點檢查:端點是 `setting()` 的條件之一,但 readiness 要能分開
     * 回報「Owner 開了」與「端點不符白名單」,合成一個布林值就分不出來了。
     */
    public static function enabled(): bool
    {
        return LiveIntegration::outboundAllowed()
            && LiveIntegration::enabledByOwner(IntegrationProvider::EcpayInvoice);
    }

    /**
     * The usable credential set, or null.
     *
     * ⛔ "There is a row in the database" is not enough: it must be enabled by
     * the Owner, fully configured, and the *issue* endpoint must be present in
     * the version-controlled allowlist. A half-configured provider fails at
     * their end with a confusing error instead of here with a clear one.
     *
     * ⛔ 查詢端點刻意不在這裡檢查,而是在真的要查的時候才檢查。
     *
     * 開立發票是顧客付了錢就該拿到的東西;查詢只是「結果不明時的復原手段」。
     * 把兩者綁在一起,等於因為一個復原路徑的設定錯誤,就讓所有已付款的訂單
     * 都開不出發票——那個設定錯誤與開立這件事無關。查詢端點不合法時,正確的
     * 行為是「開立照送一次、查詢 0 次、結果維持不明並等人處理」,而不是
     * 「一張都不開」。
     */
    public static function setting(): ?IntegrationSetting
    {
        if (self::issueEndpoint() === null) {
            return null;
        }

        return LiveIntegration::setting(IntegrationProvider::EcpayInvoice);
    }

    /**
     * ⛔ 端點必須與版本控制中的白名單完全一致;同主機不同 path 也拒絕。
     *
     * 整串比對而不是逐段解析:每多一段就多一個忘記檢查的機會——query、
     * fragment、userinfo、port 少查一個就是一個缺口。開立發票不是一個
     * 應該「不小心連到」的操作。
     */
    public static function issueEndpoint(): ?string
    {
        return ProviderEndpoints::ecpayInvoiceIssue();
    }

    public static function queryEndpoint(): ?string
    {
        return ProviderEndpoints::ecpayInvoiceQuery();
    }
}
