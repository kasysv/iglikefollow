<?php

namespace App\Services\Payments;

use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use App\Services\Integrations\LiveIntegration;

/**
 * One answer to "may this payment channel take money right now".
 *
 * The registry asks this before handing out an adapter, but the registry is not
 * the only way in: the ECPay callback is a public route, and both gateways and
 * the LINE client can be resolved from the container directly. A guard that
 * only covers the ordinary controller path is a guard around the front door of
 * a building with several.
 *
 * ⛔ M4C 之後這裡不再讀 `PAYMENTS_SANDBOX_ENABLED`。營運事實只有一個來源:
 * Owner 在後台開啟的 production `integration_settings.is_enabled`。舊旗標留在
 * config 只為相容既有 .env,值一律被忽略——留著一個能否決 Owner 的旗標,結果
 * 就是 Owner 按了開關卻沒反應,然後有人回來改 `.env`。那是 Owner 明確要求
 * 消除的東西。
 *
 * ⛔ 類別名稱保留「Sandbox」是刻意的取捨:改名會擴大這一輪的 diff,而
 * 這一輪要證明的是行為改變。名稱已不精確,PHPDoc 說明取代它。
 */
class SandboxGuard
{
    /**
     * 這個環境可不可以對外送出付款請求。
     *
     * ⛔ 這是技術邊界,不是營運開關:`local`／`testing` 永遠不得外呼,
     * `staging` 與 `production` 都可以。單獨成立不代表可以收款——還要
     * Owner 開了對應通道。
     */
    public static function enabled(): bool
    {
        return LiveIntegration::outboundAllowed();
    }

    /**
     * 這個付款通道現在可以真的送出請求嗎?
     *
     * ⛔ 環境與 Owner 開關兩者都必須成立。回 false 的呼叫端必須誠實拒絕,
     * 不得退回 Fake、不得假裝成功。
     */
    public static function channelEnabled(IntegrationProvider $provider): bool
    {
        return LiveIntegration::setting($provider) !== null;
    }

    /** 可用的正式設定;⛔ 否則 null,呼叫端 fail closed。 */
    public static function setting(IntegrationProvider $provider): ?IntegrationSetting
    {
        return LiveIntegration::setting($provider);
    }
}
