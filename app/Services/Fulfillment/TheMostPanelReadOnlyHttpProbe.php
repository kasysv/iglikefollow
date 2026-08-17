<?php

namespace App\Services\Fulfillment;

use App\Contracts\TheMostPanelReadOnlyProbe;
use App\Data\Fulfillment\TheMostPanelProbeObservation;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\TheMostPanelReadOnlyAction;
use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The one place that may contact TheMostPanel, and only to read.
 *
 * ⛔ Every gate is checked before a request object is even built, because the
 * failure mode here is not a wrong answer — it is an irreversible external
 * call. Once a request leaves this machine it cannot be recalled, so
 * "fail closed" has to mean "never sent", not "sent and ignored".
 *
 * ⛔ No retries, anywhere. The provider's rate limit is unknown, and "it was
 * only a read" is not a defence against being throttled or blocked. One
 * command, at most one request.
 */
class TheMostPanelReadOnlyHttpProbe implements TheMostPanelReadOnlyProbe
{
    private const CONNECT_TIMEOUT = 5;

    private const TOTAL_TIMEOUT = 15;

    /**
     * ⛔ 保守上限，且明確標示為我們的選擇而非供應商保證。
     *
     * `services` 可能很大，但在看到真實回應之前，任何數字都是猜測。寧可先擋
     * 下來、記錄「太大」，也不要把未知大小的內容讀進記憶體。
     */
    private const MAX_BODY_BYTES = 2_097_152;

    /**
     * ⛔ 只接受純數字，且只有一筆。
     *
     * 公開範例中的訂單編號是數字，多筆查詢用的是另一個 `orders` 欄位；我們
     * 永遠只送單一 `order`。因此連字號在這裡沒有任何合法用途——允許它只會讓
     * `100-200` 這種「看起來像範圍」的輸入通過格式檢查。⛔ 寧可擋掉一個罕見
     * 的合法編號，也不要放行一個可能被當成批次的字串。
     *
     * 若日後真實觀察顯示編號含其他字元，這裡再依證據放寬。
     */
    private const ORDER_ID_PATTERN = '/^[0-9]{1,32}$/';

    public function probe(
        TheMostPanelReadOnlyAction $action,
        ?string $orderId = null,
    ): TheMostPanelProbeObservation {
        $blocked = $this->blockingReason($action, $orderId);

        if ($blocked !== null) {
            // ⛔ 一個 byte 都還沒送出。
            return TheMostPanelProbeObservation::blocked($action, $blocked);
        }

        $endpoint = $this->endpoint();
        $key = $this->apiKey();

        $payload = ['key' => $key, 'action' => $action->value];

        if ($action->requiresOrderId()) {
            // ⛔ 單一一筆，且已通過格式驗證。
            $payload['order'] = $orderId;
        }

        $startedAt = microtime(true);

        try {
            $response = Http::asForm()
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TOTAL_TIMEOUT)
                // ⛔ 不自動重試：rate limit 未知。
                ->withoutRedirecting()
                ->withOptions([
                    // ⛔ TLS 驗證維持開啟；verify=false 永久禁止。
                    'verify' => true,
                ])
                ->post($endpoint, $payload);
        } catch (Throwable) {
            // ⛔ 連線失敗、逾時、TLS 失敗：不保存 provider 或例外原文。
            return TheMostPanelProbeObservation::failed(
                $action,
                'transport_failed',
                elapsedMs: $this->elapsed($startedAt),
            );
        }

        return $this->read($action, $response, $startedAt);
    }

    /**
     * Why this must not be sent, or null if it may be.
     *
     * ⛔ Order matters only for the message; each branch is equally a refusal.
     */
    private function blockingReason(TheMostPanelReadOnlyAction $action, ?string $orderId): ?string
    {
        /*
         * ⛔ production 一律拒絕。
         *
         * 這是唯讀的，但仍是用正式 credential 打正式 API。在正式站上執行探針
         * 應該是一個刻意的、有人在場的決定，不是任何自動流程碰得到的東西。
         */
        if (app()->environment('production')) {
            return 'blocked_production';
        }

        // ⛔ 只能由人在終端機執行；HTTP request 不得觸發。
        if (! app()->runningInConsole()) {
            return 'blocked_not_cli';
        }

        if (! (bool) config('integrations.themostpanel_read_only.enabled', false)) {
            return 'blocked_disabled';
        }

        if ($this->endpoint() === null) {
            return 'blocked_endpoint';
        }

        if ($this->apiKey() === null) {
            return 'blocked_no_credential';
        }

        if ($action->requiresOrderId()) {
            if ($orderId === null || ! preg_match(self::ORDER_ID_PATTERN, $orderId)) {
                return 'blocked_invalid_order_id';
            }
        } elseif ($orderId !== null) {
            // ⛔ 不需要訂單編號的 action 不得夾帶一個。
            return 'blocked_unexpected_order_id';
        }

        return null;
    }

    /**
     * ⛔ 端點必須與版本控制中的值完全一致。
     *
     * 整串比對，不解析、不正規化：只有一個合法值，逐段檢查只是多幾個忘記
     * 檢查的機會（query、fragment、userinfo、port 少一個就是一個缺口）。
     *
     * ⛔ 讀的是 `themostpanel_read_only.endpoint`，不是 `endpoints.themostpanel`。
     * 後者代表「可執行交易的端點」，其 production 必須維持為空——查詢與下單
     * 不共用同一個設定值。
     */
    private function endpoint(): ?string
    {
        $configured = (string) config('integrations.themostpanel_read_only.endpoint', '');

        return $configured === 'https://themostpanel.com/api/v2' ? $configured : null;
    }

    /**
     * ⛔ Only ever from the encrypted setting, server side.
     *
     * Never a command option or shell argument — those reach the process list
     * and the shell history, where a key outlives the command that used it.
     */
    private function apiKey(): ?string
    {
        $setting = IntegrationSetting::query()
            ->where('provider', IntegrationProvider::TheMostPanel)
            ->where('environment', IntegrationEnvironment::Production)
            ->first();

        $key = $setting?->secret('ApiKey');

        return is_string($key) && trim($key) !== '' ? $key : null;
    }

    /**
     * Read the response for its shape, strictly.
     *
     * ⛔ 每一種「讀不到」都有自己的本地代碼，而且都不保存 provider 原文。
     */
    private function read(
        TheMostPanelReadOnlyAction $action,
        $response,
        float $startedAt,
    ): TheMostPanelProbeObservation {
        $elapsed = $this->elapsed($startedAt);
        $status = $response->status();

        // ⛔ 3xx 不跟隨：重導可能指向完全不同的主機。
        if ($status >= 300 && $status < 400) {
            return TheMostPanelProbeObservation::failed($action, 'redirect_refused', $status, $elapsed);
        }

        if ($status === 429) {
            return TheMostPanelProbeObservation::failed($action, 'rate_limited', $status, $elapsed);
        }

        if (! $response->successful()) {
            return TheMostPanelProbeObservation::failed(
                $action,
                $status >= 500 ? 'server_error' : 'client_error',
                $status,
                $elapsed,
            );
        }

        $body = (string) $response->body();

        if ($body === '') {
            return TheMostPanelProbeObservation::failed($action, 'empty_body', $status, $elapsed);
        }

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return TheMostPanelProbeObservation::failed($action, 'body_too_large', $status, $elapsed);
        }

        if (! mb_check_encoding($body, 'UTF-8')) {
            return TheMostPanelProbeObservation::failed($action, 'invalid_encoding', $status, $elapsed);
        }

        $decoded = json_decode($body, true);

        if ($decoded === null && trim($body) !== 'null') {
            // HTML 錯誤頁、純文字訊息等等都落在這裡。
            return TheMostPanelProbeObservation::failed($action, 'unparseable_body', $status, $elapsed);
        }

        return TheMostPanelProbeObservation::observed(
            action: $action,
            httpStatus: $status,
            topLevelType: $this->describeType($decoded),
            fieldTypes: $this->describeFields($decoded),
            itemCount: is_array($decoded) && array_is_list($decoded) ? count($decoded) : null,
            // ⛔ 單向雜湊，只用來比對「兩次回應是否相同」，無法反解。
            bodyHash: hash('sha256', $body),
            elapsedMs: $elapsed,
        );
    }

    /**
     * Field names and types, never values.
     *
     * ⛔ For a list, only the first element is described — enough to write a
     * parser, and it avoids walking a catalogue of unknown size. Names are
     * length-capped: a field name is metadata, but a provider that puts data in
     * its keys should not be able to smuggle it through here.
     *
     * @return array<string, string>
     */
    private function describeFields(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }

        $sample = array_is_list($decoded) ? ($decoded[0] ?? null) : $decoded;

        if (! is_array($sample)) {
            return [];
        }

        $fields = [];

        foreach ($sample as $name => $value) {
            if (count($fields) >= 40) {
                break;
            }

            $safeName = mb_substr((string) $name, 0, 40);
            $fields[$safeName] = $this->describeType($value);
        }

        ksort($fields);

        return $fields;
    }

    /** ⛔ 只回傳型別名稱，不回傳值本身。 */
    private function describeType(mixed $value): string
    {
        if (is_array($value)) {
            return array_is_list($value) ? 'list' : 'object';
        }

        return get_debug_type($value);
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
