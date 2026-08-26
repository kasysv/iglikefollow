<?php

namespace App\Services\Notifications;

use App\Models\IntegrationSetting;
use App\Services\Integrations\ProviderEndpoints;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one place that talks to LINE's push endpoint.
 *
 * ⛔ 這個 class **不決定要不要送**——那是 `LineNotificationGate` 的職責。
 * 它只負責「把已經被批准的一則訊息送出去，並回報一個安全的結果」。
 *
 * ⛔ 永遠不 throw 給呼叫端：回傳 `LineNotificationOutcome`。通知失敗絕不能
 * 影響付款、發票或派單——一個會往上冒泡的例外正是那條路徑的開端。
 */
final class LinePushClient
{
    /** ⛔ 施工單指定：10 秒。逾時代表結果**未知**，不是失敗。 */
    private const TIMEOUT = 10;

    /**
     * ⛔ 安全截斷長度。
     *
     * LINE 的 text 上限是 5000（以 UTF-16 code unit 計）。我們在 4800 就先
     * 自己截斷，⛔ 不是等 LINE 用 5000 把**整則**訊息退掉——那樣 Owner 會
     * 完全收不到這張訂單的通知，而不是收到一則被截短的。
     *
     * ⛔ 用 `mb_strlen(..., 'UTF-16')` 之外的計法會低估：中文與 emoji 在
     * UTF-16 下可能佔 2 個 code unit。這裡用保守的字元數上限，配合 4800 的
     * 緩衝，確保換算後仍在 5000 以內。
     */
    private const MAX_CHARS = 4800;

    private const TRUNCATION_SUFFIX = '…（略）';

    /**
     * Send one text message.
     *
     * ⛔ `$setting` 必須來自 `LineNotificationGate`：這個 method 不再自己判斷
     * 開關與環境，⛔ 也因此絕不可以從別處直接呼叫它。
     */
    public function push(IntegrationSetting $setting, string $message, string $retryKey): LineNotificationOutcome
    {
        $endpoint = ProviderEndpoints::linePushMessage();

        /*
         * ⛔ 送出前再確認一次端點。
         *
         * gate 已經檢查過，但那可能是好幾秒前、在 queue 排隊之前的事。
         * 這個請求會帶著 Channel Access Token，值得在最靠近網路的地方再看一眼。
         */
        if ($endpoint === null) {
            return LineNotificationOutcome::blocked('endpoint_mismatch');
        }

        $token = $setting->secret('ChannelAccessToken');
        $target = $setting->identifier;

        if (! is_string($token) || $token === '') {
            return LineNotificationOutcome::blocked('not_configured');
        }

        if (! LineNotificationGate::targetIsValid($target)) {
            return LineNotificationOutcome::blocked('invalid_target');
        }

        $text = self::truncate($message);

        if ($text === '') {
            return LineNotificationOutcome::blocked('empty_message');
        }

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    /*
                     * ⛔ 同一張訂單的每次 retry 都用**同一個** retry key。
                     *
                     * LINE 保證：帶同一個 retry key 的請求只會被執行一次，
                     * 之後的重試會得到 409 而**不會**重複送達。少了它，
                     * 一次 timeout 後的重試就可能讓 Owner 收到兩則相同通知。
                     *
                     * ⛔ 官方要求它必須在**第一次**請求就帶上——事後才加是無效的。
                     * https://developers.line.biz/en/docs/messaging-api/retrying-api-request/
                     */
                    'X-Line-Retry-Key' => $retryKey,
                ])
                ->timeout(self::TIMEOUT)
                ->asJson()
                ->post($endpoint, [
                    'to' => $target,
                    // ⛔ 單一 text message。上限是 5 則，我們只送 1 則。
                    'messages' => [
                        ['type' => 'text', 'text' => $text],
                    ],
                ]);
        } catch (Throwable) {
            /*
             * ⛔ 連線失敗或逾時：結果**未知**。
             *
             * 訊息可能已經送達、也可能沒有。因此標記為可重試——而重試會帶著
             * 同一個 retry key，所以「已經送達」的情況不會變成第二則訊息。
             *
             * ⛔ 不記錄 exception message：它可能含有 URL、header 或內容片段。
             */
            $outcome = LineNotificationOutcome::transportError();
            self::record($outcome);

            return $outcome;
        }

        /*
         * ⛔ 409 代表「這個 retry key 已經被接受過了」——訊息**已經送出**，
         * 這次是重複請求。那是成功，不是失敗。
         *
         * ⛔ 若把它當失敗重試，會永遠重試下去（每次都得到 409）。
         */
        if ($response->status() === 409) {
            $outcome = LineNotificationOutcome::sent();
            self::record($outcome);

            return $outcome;
        }

        $outcome = LineNotificationOutcome::fromStatus($response->status());
        self::record($outcome);

        return $outcome;
    }

    /**
     * ⛔ 安全截斷。
     *
     * ⛔ 截斷後接上固定字串，讓 Owner 看得出來「這則被截短了」——否則他會
     * 以為訂單真的只有這幾項。
     */
    private static function truncate(string $message): string
    {
        $message = trim($message);

        if ($message === '' || mb_strlen($message) <= self::MAX_CHARS) {
            return $message;
        }

        $keep = self::MAX_CHARS - mb_strlen(self::TRUNCATION_SUFFIX);

        return mb_substr($message, 0, $keep).self::TRUNCATION_SUFFIX;
    }

    /**
     * ⛔ 只記錄三個 allowlist 欄位。
     *
     * ⛔ 絕不記錄：token、完整接收 ID、客戶 Email／電話、訊息內容、
     * LINE 的 response body 或它的錯誤訊息原文。
     *
     * ⛔ 整段包在 `try/catch (Throwable)` 裡：log 寫不進去（磁碟滿、權限錯）
     * 絕不能讓通知流程拋例外。這是 LINE Pay R1 學到的——當時
     * 一個沒有隔離的 `Log::warning()` 讓付款收斂整個中斷。
     * ⛔ 必須 catch `Throwable` 而不是 `Exception`：Monolog 會拋 `Error`。
     */
    private static function record(LineNotificationOutcome $outcome): void
    {
        if ($outcome->successful()) {
            return;
        }

        try {
            Log::warning('line_notification.push_failed', $outcome->toLogContext());
        } catch (Throwable) {
            // ⛔ 刻意留空：觀測性不得成為通知流程的新單點故障。
        }
    }
}
