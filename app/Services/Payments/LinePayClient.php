<?php

namespace App\Services\Payments;

use App\DTO\LinePayResponse;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use App\Services\Integrations\LiveIntegration;
use App\Services\Integrations\ProviderEndpoints;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The HTTP half of LINE Pay, kept away from the domain.
 *
 * Every call returns a typed result; ⛔ nothing here throws into the caller and
 * nothing here stores anything. Timeouts are the ones the official
 * documentation specifies — 10 seconds to request, 40 to confirm, because
 * confirm can involve the customer's bank and a short timeout would abandon
 * payments that were about to succeed.
 */
class LinePayClient
{
    private const REQUEST_TIMEOUT = 10;

    private const CONFIRM_TIMEOUT = 40;

    /**
     * Ask LINE Pay to start a payment.
     *
     * @param  array<string, mixed>  $body
     */
    public function requestPayment(array $body): LinePayResponse
    {
        return $this->call('/v4/payments/request', $body, self::REQUEST_TIMEOUT);
    }

    /**
     * Confirm a payment the customer approved.
     *
     * ⛔ This, not the browser's return, is what proves a payment happened.
     *
     * @param  array<string, mixed>  $body
     */
    public function confirmPayment(string $transactionId, array $body): LinePayResponse
    {
        return $this->call("/v4/payments/{$transactionId}/confirm", $body, self::CONFIRM_TIMEOUT);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function call(string $uri, array $body, int $timeout): LinePayResponse
    {
        /*
         * ⛔ 最靠近網路的一層也要擋。`setting()` 已涵蓋環境、Owner 開關與
         * credential 完整度:任一不成立就一個 request 都不送。
         */
        $setting = $this->setting();

        if ($setting === null) {
            return LinePayResponse::unavailable();
        }

        $channelId = (string) $setting->identifier;
        $secret = $setting->secret('ChannelSecret');

        // ⛔ base 必須與版本控制中的白名單完全一致,不做 rtrim／正規化:
        // 需要被「整理」才符合的值,本身就不是白名單裡的那一個。
        $base = ProviderEndpoints::linePayApi();

        if ($secret === null || $channelId === '' || $base === null) {
            return LinePayResponse::unavailable();
        }

        /*
         * ⭐ 序列化恰好一次，簽章與 wire 用的是同一份 bytes。
         *
         * ⛔ 舊版把 array 交給 `LinePaySignature::headers()` 算簽章，再把同一個
         * array 交給 `->asJson()->post()`，由 Guzzle 用**另一套預設旗標**重新
         * 編碼。body 只要含 redirect URL（必然含 `https://`）或中文，兩份 bytes
         * 就不同：`https://` → `https:\/\/`、`行銷` → `行銷`。簽章涵蓋
         * 的內容與實際送出的內容不一致，LINE Pay 一律拒絕——這就是 Owner 在
         * staging 連付款頁都沒看到就被擋下的原因。
         */
        $rawBody = LinePaySignature::encodeBody($body);

        if ($rawBody === null) {
            /*
             * ⛔ 編碼失敗就一個 request 都不送。
             *
             * 用空字串或部分 body 硬送，等於送出一份與簽章不符、內容也不完整的
             * 請求；而且 `neverSent()` 必須為真，呼叫端才知道重試是安全的。
             */
            return LinePayResponse::unavailable();
        }

        // ⛔ 每次都用新的 nonce：重複使用等於允許重放同一筆簽好的請求。
        $headers = LinePaySignature::headers(
            $channelId, $secret, $uri, $rawBody, LinePaySignature::nonce()
        );

        try {
            /*
             * ⛔ `withBody()` 送出的是**這一份 raw bytes**，不是再編碼一次的
             * array。`asJson()` 在這裡是錯的:它會把 array 重新編碼,正是上面
             * 那個 bug。Content-Type 由 withBody() 的第二個參數明確指定。
             */
            $response = Http::withHeaders($headers)
                ->timeout($timeout)
                ->acceptJson()
                ->withBody($rawBody, 'application/json')
                ->post($base.$uri);
        } catch (Throwable) {
            // ⛔ 逾時或連線失敗＝結果不明，不是失敗：對方可能已經處理了。
            // 也不帶出 exception 訊息，那裡面常有連線字串與 channel id。
            return LinePayResponse::timeout();
        }

        /*
         * ⛔ 先看 HTTP 狀態，再談 body 裡寫什麼。
         *
         * LINE Pay 的正常回應固定是 200。非 200 代表這次交握沒有正常完成，
         * 此時 body 內容不可採信——即使它剛好寫著 returnCode=0000。少了這道
         * 檢查，一個 500 加上偽裝的成功內容就能讓訂單被標記為已付款。
         */
        if ($response->status() !== 200) {
            $this->diagnose($uri, null, 'http_status_not_200');

            return LinePayResponse::unreadable();
        }

        $json = null;

        try {
            $json = $response->json();
        } catch (Throwable) {
            $json = null;
        }

        if (! is_array($json) || ! isset($json['returnCode'])) {
            $this->diagnose($uri, null, 'unparsable_body');

            return LinePayResponse::unreadable();
        }

        $result = LinePayResponse::fromArray($json);

        if (! $result->isSuccess()) {
            // ⛔ 只帶 returnCode，不帶 returnMessage：那是對方的自由文字。
            $this->diagnose($uri, $json['returnCode'] ?? null, 'provider_rejected');
        }

        return $result;
    }

    /**
     * Record just enough to tell one failure apart from another.
     *
     * ⭐ 本輪加入這個的原因：Owner 在 staging 看到「付款結果驗證失敗」，但真正的
     * `returnCode` 沒有被保存在任何地方，於是根因只能停在 `unknown`。少了它，
     * 下一次同樣的故障仍然無從分辨是 header、金額還是設定問題。
     *
     * ⛔ 只寫三個欄位，而且每一個都是本地 allowlist 或格式驗證過的值：
     *
     *  - `phase`：`request` 或 `confirm`，由固定字串比對得出，⛔ 不含
     *    transactionId——`/v4/payments/{id}/confirm` 的 URI 本身帶著它。
     *  - `return_code`：必須通過 `^\d{4}$`，否則一律記 `unrecognized`。
     *    四位數字容納不了 email、secret 或任何自由文字。
     *  - `reason`：本地固定 token。
     *
     * ⛔ 絕不寫入：`returnMessage`、raw response／body、Channel ID／Secret、
     * 簽章、nonce、order／attempt reference、transactionId、金額、email、電話、
     * 網址或任何客戶資料。這些一個都不經過這個方法。
     *
     * ⛔ 寫 structured log，不寫 DB、不新增欄位、不顯示給客人。
     *
     * ⭐ R1：整個寫入是 best-effort，logger 的任何 `Throwable` 都在這個邊界內
     * 被隔離。
     *
     * ⛔ 初版直接呼叫 `Log::warning()` 而沒有任何保護。log 目錄不可寫時 Monolog
     * 會丟 `UnexpectedValueException`，那個例外會一路衝出 `LinePayClient`——
     * 後果遠不只「少了一行 log」：`LinePayGateway` 依賴 client **回傳一個結果**
     * 來收斂 payment attempt，例外冒泡上去之後，request 階段原本應該收斂為
     * `failed` 的 attempt 可能留在 open／pending，而 `ResolvePaymentAttempt`
     * 會正確地擋下任何 pending——那張訂單於是再也付不了款。
     *
     * ⛔ 一個寫不進 log 的磁碟，不該讓客人的訂單卡死。診斷是附加觀測，
     * 不是付款狀態機的一部分。
     */
    private function diagnose(string $uri, mixed $returnCode, string $reason): void
    {
        try {
            Log::warning('linepay.call_failed', [
                'phase' => str_contains($uri, '/confirm') ? 'confirm' : 'request',
                'return_code' => $this->safeReturnCode($returnCode),
                'reason' => $reason,
            ]);
        } catch (Throwable) {
            /*
             * ⛔ 刻意完全吞掉，而且什麼都不做。
             *
             * 不再 log 一次：logger 正是壞掉的那個東西，再叫它一次只會再丟一次
             * 例外，或在某些 handler 下無限遞迴。
             *
             * 不寫 DB、不回顯 exception、不輸出任何輸入內容：這裡唯一該發生的
             * 事就是「付款流程繼續往下走」。診斷本來就是可以失去的東西。
             */
        }
    }

    /** ⛔ 四位數字才記；其餘一律 `unrecognized`，不回顯原值。 */
    private function safeReturnCode(mixed $value): string
    {
        if ((is_string($value) || is_int($value)) && preg_match('/\A\d{4}\z/', (string) $value) === 1) {
            return (string) $value;
        }

        return 'unrecognized';
    }

    /** Owner 維護的唯一一套正式設定;⛔ 環境或開關任一不成立即 null。 */
    private function setting(): ?IntegrationSetting
    {
        return LiveIntegration::setting(IntegrationProvider::LinePay);
    }
}
