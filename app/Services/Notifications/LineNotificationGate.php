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
     * ⛔ `userId` 的官方契約：`U[0-9a-f]{32}`。
     *
     * ⭐ 這一個**有**官方明文保證，所以照契約嚴格檢查。
     * https://developers.line.biz/en/docs/messaging-api/getting-user-ids/
     */
    private const USER_PATTERN = '/\A U[0-9a-f]{32} \z/x';

    /**
     * ⛔⛔ `groupId`／`roomId` 只做**最小**邊界檢查。
     *
     * ⭐ R1 修正：初版要求 C／R 之後必須是 16–64 位小寫十六進位。
     * **那是把範例當成契約**——官方只把 groupId／roomId 定義為 webhook 回傳的
     * opaque String，從未規範長度或字元集；文件裡的 `Ca56f94637c…` 是範例。
     *
     * ⛔ 我們自己發明的規則一旦與 LINE 的實際值不符，Owner 的通知會**全部
     * 靜默失效**，而那個錯在我們身上。真實收件者的正確性由 Owner 按
     * 「送測試訊息」確認——那是唯一能真正驗證的方法。
     *
     * ⛔ 但仍必須擋掉真正常見的貼錯：display name、`@LINE ID`、URL、空字串、
     * 含空白或控制字元的值。那些會把整張訂單的內容送去未知對象。
     *
     * ⛔ 上限沿用 DB 欄位長度（`identifier` 是 string(255)）：超過的值本來
     * 就存不進去，讓它在這裡就停下來比在寫入時炸掉清楚。
     */
    private const OPAQUE_PATTERN = '/\A [CR] [^\s\x00-\x1f]{1,254} \z/xu';

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

    /**
     * ⛔ `U` 走官方契約；`C`／`R` 只做最小邊界檢查（見上方常數註解）。
     *
     * ⛔ 其他開頭一律拒絕：`@my_id`、`https://line.me/...`、display name
     * 都不是接收 ID。
     */
    public static function targetIsValid(?string $target): bool
    {
        if (! is_string($target) || $target === '') {
            return false;
        }

        if (str_starts_with($target, 'U')) {
            return preg_match(self::USER_PATTERN, $target) === 1;
        }

        return preg_match(self::OPAQUE_PATTERN, $target) === 1;
    }
}
