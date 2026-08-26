<?php

namespace App\Services\Notifications;

use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use App\Services\Integrations\LiveIntegration;
use App\Services\Integrations\ProviderEndpoints;

/**
 * May we push a LINE notification right now?
 *
 * ⛔ 這是「可不可以外呼」的唯一判斷點，⛔ 每次呼叫都重新讀 DB。
 *
 * 為什麼不快取：queue worker 是長駐 process。如果把 Owner 的開關狀態
 * 記在記憶體裡，Owner 關掉通知之後，那個 worker 還會繼續送——直到有人
 * 想起要重啟它。既有的 `FulfillmentDispatchGate` 與
 * `TheMostPanelLiveCredentialSource` 都是同樣的理由。
 *
 * ⛔ local／testing 一律關閉（`LiveIntegration::outboundAllowed()`）：
 * 本機測試永遠不該送出一則真的 LINE 訊息給 Owner。
 */
final class LineNotificationGate
{
    /**
     * ⛔ 接收 ID 的形狀。
     *
     * ⭐ 這個 pattern 刻意**比 userId 寬**，理由是查證過官方文件後的事實：
     *
     *  - `userId` 官方明文保證是 `U[0-9a-f]{32}`
     *    （https://developers.line.biz/en/docs/messaging-api/getting-user-ids/）。
     *  - `groupId`／`roomId` 的長度與字元集 **官方沒有規範**。Messaging API
     *    reference 只說它們是 String，文件中的例子（`Ca56f94637c…`、
     *    `Ra8dbf4673c…`）看起來也是小寫十六進位，但那是範例，不是契約。
     *    官方對 `to` 的唯一要求是「把 webhook 給你的值原樣送回來」。
     *
     * ⛔ 因此這裡不硬性要求 32 碼：若照 userId 的規格去卡群組 ID，某天 LINE
     * 發出一個長度不同的 groupId，Owner 的通知會全部靜默失效，而錯的是我們
     * 自己發明的規則。
     *
     * ⛔ 但也不是「不是空的就好」：仍要求 U／C／R 開頭 ＋ 十六進位字元 ＋
     * 合理長度下限，⛔ 擋掉貼成 display name、網址或 LINE ID（@xxx）的情況
     * ——那些是真正常見的貼錯，且會把訂單內容送去未知對象。
     */
    private const TARGET_PATTERN = '/\A[UCR][0-9a-f]{16,64}\z/';

    /** Owner 開關（不管環境）。後台顯示用。 */
    public static function enabledByOwner(): bool
    {
        return LiveIntegration::enabledByOwner(IntegrationProvider::LineOrderNotification);
    }

    /**
     * The setting to send with, or null when we must not send.
     *
     * ⛔ 依序檢查：外呼環境 → Owner 開關與 credential 完整度 → 端點 → 接收 ID。
     * ⛔ 任何一項不成立就回 null，⛔ 呼叫端必須把 null 當成「不要送」，
     * 不是「用預設值送」。
     */
    public static function setting(): ?IntegrationSetting
    {
        if (ProviderEndpoints::linePushMessage() === null) {
            return null;
        }

        $setting = LiveIntegration::setting(IntegrationProvider::LineOrderNotification);

        if ($setting === null) {
            return null;
        }

        return self::targetIsValid($setting->identifier) ? $setting : null;
    }

    /**
     * Why we are not sending — a closed local token, for logging only.
     *
     * ⛔ 只回傳本站自己的 token，⛔ 不回傳缺哪一個 credential 的細節。
     */
    public static function blockedReason(): ?string
    {
        if (! LiveIntegration::outboundAllowed()) {
            return 'outbound_blocked';
        }

        if (ProviderEndpoints::linePushMessage() === null) {
            return 'endpoint_mismatch';
        }

        $row = LiveIntegration::row(IntegrationProvider::LineOrderNotification);

        if ($row === null || ! $row->isFullyConfigured()) {
            return 'not_configured';
        }

        if (! $row->is_enabled) {
            return 'disabled';
        }

        if (! self::targetIsValid($row->identifier)) {
            return 'invalid_target';
        }

        return null;
    }

    /**
     * The setting for a manual test — ignores the Owner auto-notify switch.
     *
     * ⭐ 「送測試訊息」的用途正是在**還沒開啟**自動通知時，先確認 token 與
     * 接收 ID 填對了。若測試也被開關擋住，Owner 就只能盲目開啟自動通知，
     * 拿真實訂單當測試——那是更糟的順序。
     *
     * ⛔ 但環境、端點、credential 完整度與接收 ID 形狀**一項都不放寬**：
     * 放寬的只有「自動通知開關」這一個。
     */
    public static function settingForManualTest(): ?IntegrationSetting
    {
        if (! LiveIntegration::outboundAllowed()) {
            return null;
        }

        if (ProviderEndpoints::linePushMessage() === null) {
            return null;
        }

        $row = LiveIntegration::row(IntegrationProvider::LineOrderNotification);

        if ($row === null || ! $row->isFullyConfigured()) {
            return null;
        }

        return self::targetIsValid($row->identifier) ? $row : null;
    }

    public static function targetIsValid(?string $target): bool
    {
        return is_string($target) && preg_match(self::TARGET_PATTERN, $target) === 1;
    }
}
