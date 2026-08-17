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
     * ⛔ 只接受純數字，且只有一筆。
     *
     * 公開範例中的訂單編號是數字，多筆查詢用的是另一個 `orders` 欄位；我們
     * 永遠只送單一 `order`。因此連字號在這裡沒有任何合法用途——允許它只會讓
     * `100-200` 這種「看起來像範圍」的輸入通過格式檢查。⛔ 寧可擋掉一個罕見
     * 的合法編號，也不要放行一個可能被當成批次的字串。
     */
    private const ORDER_ID_PATTERN = '/^[0-9]{1,32}$/';

    /**
     * ⛔ 只有長得像技術欄位名稱的 key 才可以原樣顯示。
     *
     * 這是 allowlist 而不是 blocklist：欄位名稱由供應商完全控制，所以問題不是
     * 「哪些字元危險」，而是「我們願意原樣印出什麼」。一個 API 的欄位名稱本來
     * 就只會是這種形狀；不符合的，一律不顯示原文。
     */
    private const SAFE_FIELD_NAME = '/^[A-Za-z_][A-Za-z0-9_.\-]{0,39}$/';

    public function probe(
        TheMostPanelReadOnlyAction $action,
        ?string $orderId = null,
    ): TheMostPanelProbeObservation {
        /*
         * ⛔ credential 只讀取一次，而且是在所有不需要它的閘門都通過之後。
         *
         * 初版讀了兩次：一次判斷「有沒有 key」，通過後又讀一次組 payload。
         * 兩次讀取之間設定可能被刪除或輪替，於是第二次拿到 null，request 帶著
         * `key=null` 送了出去——閘門說有，實際送出的沒有。
         */
        $blocked = $this->blockingReasonBeforeCredential($action, $orderId);

        if ($blocked !== null) {
            return TheMostPanelProbeObservation::blocked($action, $blocked);
        }

        $key = $this->apiKey();

        if ($key === null) {
            return TheMostPanelProbeObservation::blocked($action, 'blocked_no_credential');
        }

        /*
         * ⛔ App key 缺失時停止，不降級成普通雜湊。
         *
         * 回應指紋必須是 keyed 的；沒有金鑰就沒有指紋，而不是「先用不安全的
         * 版本頂著」。
         */
        if ($this->fingerprintKey() === null) {
            return TheMostPanelProbeObservation::blocked($action, 'blocked_no_app_key');
        }

        $payload = ['key' => $key, 'action' => $action->value];

        if ($action->requiresOrderId()) {
            // ⛔ 單一一筆，且已通過格式驗證。
            $payload['order'] = $orderId;
        }

        /*
         * ⛔ 送出去的東西，就是等一下要從回應中抹掉的東西。
         *
         * 供應商完全控制自己的 JSON key，所以它可以把我們的 key 或這位客人的
         * 訂單編號放進欄位名稱裡。用同一份 snapshot 當作 marker，才能保證
         * 「檢查的」與「送出的」是同一個值。
         */
        $markers = array_values(array_filter([$key, $orderId]));

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
                    // ⛔ 標頭一到就檢查宣告長度，過大直接中止，body 不進記憶體。
                    'on_headers' => function ($response) {
                        TheMostPanelResponseSizeGuard::assertContentLength($response->getHeaders());
                    },
                    // ⛔ chunked／未宣告長度時，改在下載過程中止。
                    'progress' => function ($downloadTotal, $downloaded) {
                        TheMostPanelResponseSizeGuard::assertProgress((int) $downloaded);
                    },
                ])
                ->post($this->endpoint(), $payload);
        } catch (Throwable $e) {
            $elapsed = $this->elapsed($startedAt);

            // 是我們自己因為過大而中止的，回報成過大而不是連線失敗。
            if (TheMostPanelResponseSizeGuard::isSizeAbort($e)) {
                return TheMostPanelProbeObservation::failed($action, 'body_too_large', elapsedMs: $elapsed);
            }

            // ⛔ 連線失敗、逾時、TLS 失敗：不保存 provider 或例外原文。
            return TheMostPanelProbeObservation::failed($action, 'transport_failed', elapsedMs: $elapsed);
        }

        return $this->read($action, $response, $startedAt, $markers);
    }

    /**
     * Why this must not be sent — everything decidable without the credential.
     *
     * ⛔ Order matters only for the message; each branch is equally a refusal.
     */
    private function blockingReasonBeforeCredential(
        TheMostPanelReadOnlyAction $action,
        ?string $orderId,
    ): ?string {
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
     * ⛔ Only ever from the encrypted setting, server side, exactly once.
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

    /** 指紋用的金鑰；⛔ 空白時整個探針停止，不降級。 */
    private function fingerprintKey(): ?string
    {
        $key = (string) config('app.key');

        return trim($key) === '' ? null : $key;
    }

    /**
     * Read the response for its shape, strictly.
     *
     * ⛔ 每一種「讀不到」都有自己的本地代碼，而且都不保存 provider 原文。
     *
     * @param  list<string>  $markers
     */
    private function read(
        TheMostPanelReadOnlyAction $action,
        $response,
        float $startedAt,
        array $markers,
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

        // ⛔ 第二層：transport 中止若因故沒有生效，這裡仍然擋下。
        if (strlen($body) > TheMostPanelResponseSizeGuard::MAX_BODY_BYTES) {
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
            fieldTypes: $this->describeFields($decoded, $markers),
            itemCount: is_array($decoded) && array_is_list($decoded) ? count($decoded) : null,
            bodyFingerprint: $this->fingerprint($body),
            elapsedMs: $elapsed,
        );
    }

    /**
     * A keyed fingerprint, so the body cannot be recovered by guessing.
     *
     * ⛔ HMAC, not a plain digest. A `balance` response is short and its format
     * is predictable, so a plain SHA-256 can be reversed by hashing candidate
     * values until one matches — GPT recovered a balance from our first version
     * in about 1,200 guesses. Keying it means an attacker without our app key
     * has nothing to compare against.
     *
     * It is still only useful for "are these two responses identical".
     */
    private function fingerprint(string $body): string
    {
        return hash_hmac('sha256', $body, (string) $this->fingerprintKey());
    }

    /**
     * Field names and types, never values — and never a name we cannot vouch for.
     *
     * ⛔ The provider chooses its own JSON keys, so a key is provider-controlled
     * text like any other. It can be our own API key, or this customer's order
     * id, placed there deliberately or echoed back in an error body. Truncating
     * to 40 characters does not help: a key shorter than that survives intact.
     *
     * So a name is printed only when it looks like a technical identifier *and*
     * contains none of the values we just sent. Everything else becomes a
     * positional placeholder, which still tells a reader how many fields there
     * were and what types they held.
     *
     * @param  list<string>  $markers
     * @return array<string, string>
     */
    private function describeFields(mixed $decoded, array $markers): array
    {
        if (! is_array($decoded)) {
            return [];
        }

        $sample = array_is_list($decoded) ? ($decoded[0] ?? null) : $decoded;

        if (! is_array($sample)) {
            return [];
        }

        $fields = [];
        $position = 0;

        foreach ($sample as $name => $value) {
            if ($position >= 40) {
                break;
            }

            $position++;

            $safeName = $this->safeFieldName((string) $name, $markers, $position);

            /*
             * ⛔ 佔位名稱不得覆蓋另一個欄位。
             *
             * 兩個不同的原始名稱可能被抹成同一個佔位符；直接覆寫會讓輸出少一
             * 個欄位，看起來像回應比實際更簡單。位置編號已保證唯一，這裡只是
             * 對「原樣顯示」的名稱再保險一次。
             */
            while (array_key_exists($safeName, $fields)) {
                $safeName = "{$safeName}_{$position}";
            }

            $fields[$safeName] = $this->describeType($value);
        }

        ksort($fields);

        return $fields;
    }

    /**
     * ⛔ 三道檢查，任何一道不過就不顯示原文。
     *
     * @param  list<string>  $markers
     */
    private function safeFieldName(string $name, array $markers, int $position): string
    {
        // 1. 含有我們送出去的值：連前後綴都算。
        foreach ($markers as $marker) {
            if ($marker !== '' && str_contains($name, $marker)) {
                return "[redacted_field_{$position}]";
            }
        }

        // 2. 不是可辨識的技術欄位名稱（含 unicode、控制字元、超長）。
        if (! preg_match(self::SAFE_FIELD_NAME, $name)) {
            return "field_{$position}";
        }

        return $name;
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
