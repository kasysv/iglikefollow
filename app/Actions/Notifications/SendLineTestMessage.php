<?php

namespace App\Actions\Notifications;

use App\Enums\IntegrationProvider;
use App\Models\User;
use App\Services\Notifications\LineNotificationGate;
use App\Services\Notifications\LineNotificationOutcome;
use App\Services\Notifications\LinePushClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Send one fixed test message, on the Owner's explicit request.
 *
 * ⭐ 為什麼需要它：Owner 填完 token 與接收 ID 之後，唯一能確認「填對了」的
 * 方法，就是真的送一則出去。少了這個按鈕，他只能直接開啟自動通知，
 * 拿**下一張真實訂單**當測試——那是更糟的順序。
 *
 * ⛔ 因此它刻意**不受自動通知開關限制**（那正是要先驗證的東西）。
 * ⛔ 但環境、端點、credential 完整度與接收 ID 形狀一項都不放寬。
 *
 * ⛔ 本機／testing 一律 fail closed：`LineNotificationGate::settingForManualTest()`
 * 的第一道就是 `outboundAllowed()`。開發時按到這顆按鈕不該送出真實訊息。
 */
final class SendLineTestMessage
{
    /**
     * ⛔ 固定文字，⛔ 不接受任何呼叫端傳入的內容。
     *
     * 一個可以自由輸入訊息內容的後台按鈕，就是一個用本站身分對外發訊息的
     * 管道；日後若有人取得後台權限，那會是現成的工具。
     */
    private const TEST_MESSAGE = "【IGNF 訂單通知】測試訊息\n----------------------------\n這是一則測試訊息，代表 LINE 通知設定正確。\n收到這則訊息後，即可開啟新訂單自動通知。";

    public function __construct(private readonly LinePushClient $client) {}

    /**
     * @return LineNotificationOutcome ⛔ 只含 allowlist token，不含 provider 原文
     */
    public function handle(): LineNotificationOutcome
    {
        /*
         * ⛔ 在 action 內再檢查一次權限。
         *
         * 呼叫端（Filament page）已經檢查過，但那只擋得住畫面；一個偽造的
         * Livewire 請求會直接打到這裡。這是既有 `RevealIntegrationSecret`
         * 的相同理由。
         */
        $user = Auth::user();

        if (! $user instanceof User || ! $user->isOwner()) {
            return LineNotificationOutcome::blocked('disabled');
        }

        $setting = LineNotificationGate::settingForManualTest();

        if ($setting === null) {
            /*
             * ⛔ 只回一個粗略原因，⛔ 不逐項回報「缺哪個 credential」。
             * 後台另有欄位完整度提示，那裡不會經過這條路徑。
             */
            return LineNotificationOutcome::blocked(
                LineNotificationGate::blockedReason() ?? 'not_configured',
            );
        }

        /*
         * ⛔ 測試訊息用**每次都不同**的 retry key。
         *
         * 這與訂單通知相反，而且是刻意的：訂單通知要防重複，所以 key 由
         * order id 推導、永遠相同；測試訊息則是 Owner 主動要求「再送一次」，
         * 若沿用同一個 key，第二次按下去只會拿到 409 而什麼都不會發生，
         * Owner 會以為按鈕壞了。
         */
        return $this->client->push($setting, self::TEST_MESSAGE, (string) Str::uuid());
    }

    /** 後台顯示用的固定白話訊息；⛔ 不回顯 token、target 或原始 response。 */
    public static function message(LineNotificationOutcome $outcome): string
    {
        return match ($outcome->reason) {
            'sent' => '測試訊息已送出，請確認 LINE 是否收到。',
            'outbound_blocked' => '目前環境不允許對外發送，尚未送出請求。',
            'endpoint_mismatch' => '通知端點設定異常，尚未送出請求。',
            'not_configured' => '請先儲存 Channel Access Token 與接收 ID，再送測試訊息。',
            'invalid_target' => '接收 ID 格式不正確，請確認是 U／C／R 開頭的 ID。',
            'rejected' => 'LINE 拒絕了這次請求，請確認 Channel Access Token 與接收 ID 是否正確。',
            'rate_limited' => 'LINE 目前限制發送頻率，請稍後再試。',
            'server_error' => 'LINE 服務暫時異常，請稍後再試。',
            'transport_error' => '連線 LINE 失敗，請稍後再試。',
            default => '測試訊息未送出，請稍後再試。',
        };
    }

    /** 這個 provider 目前是否值得顯示測試按鈕。 */
    public static function provider(): IntegrationProvider
    {
        return IntegrationProvider::LineOrderNotification;
    }
}
