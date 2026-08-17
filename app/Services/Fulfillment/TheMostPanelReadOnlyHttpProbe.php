<?php

namespace App\Services\Fulfillment;

use App\Contracts\TheMostPanelReadOnlyProbe;
use App\Contracts\TheMostPanelServiceCatalogSource;
use App\Data\Fulfillment\TheMostPanelCatalogFetchResult;
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
 *
 * ⛔ Two read paths, one transport. The shape probe (RO-A) and the catalog
 * source (CATALOG-B1) share the same gates, the same credential discipline
 * and the same `postExactlyOnce()` request chain — a second copied HTTP chain
 * would inevitably drift from this one, and the drifted copy would be the one
 * carrying the API key. They differ only in what they keep: the probe keeps
 * shape and discards the body; the catalog source keeps the body privately,
 * one-shot, for the CATALOG-A parser and nothing else.
 */
class TheMostPanelReadOnlyHttpProbe implements TheMostPanelReadOnlyProbe, TheMostPanelServiceCatalogSource
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

    public function __construct(private ?TheMostPanelCurlCapability $capability = null) {}

    /** ⛔ production path 讀真實 runtime；測試可注入。 */
    private function capability(): TheMostPanelCurlCapability
    {
        return $this->capability ??= TheMostPanelCurlCapability::fromRuntime();
    }

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

        /*
         * ⛔ 每次 request 都要一個全新的 sink。
         *
         * 共用 stream 或 byte counter 會讓前一次的小回應吃掉下一次的額度，
         * 兩個探針同時執行時也會互相污染。
         */
        $sink = new TheMostPanelBoundedResponseStream(TheMostPanelResponseSizeGuard::MAX_BODY_BYTES);

        // ⛔ 每次 request 一份，用來記下 cURL 的 errno，不記任何訊息。
        $transfer = new TheMostPanelTransferState;

        try {
            $response = $this->postExactlyOnce($payload, $sink, $transfer);
        } catch (Throwable $e) {
            // ⛔ 連線失敗、逾時、TLS 失敗：不保存 provider 或例外原文。
            return TheMostPanelProbeObservation::failed(
                $action,
                $this->transportFailureCode($e, $transfer),
                elapsedMs: $this->elapsed($startedAt),
            );
        }

        return $this->read($action, $response, $startedAt, $markers);
    }

    /**
     * Fetch the `services` list, keeping the raw body for the parser.
     *
     * ⛔ Same gates, same credential discipline, same transport as the probe —
     * this method adds no capability, only a different destination for a
     * successful body. It never parses: strict validation belongs to the
     * CATALOG-A parser, and a second lenient copy here would drift.
     */
    public function fetchServices(): TheMostPanelCatalogFetchResult
    {
        $blocked = $this->blockingReasonBeforeCredential(TheMostPanelReadOnlyAction::Services, null);

        if ($blocked !== null) {
            return TheMostPanelCatalogFetchResult::blocked($blocked);
        }

        /*
         * ⛔ 沒有 app key 連加密的 credential 都解不開——在讀取之前就停，
         * 而不是讓解密在半路丟例外。
         */
        if ($this->fingerprintKey() === null) {
            return TheMostPanelCatalogFetchResult::blocked('blocked_no_app_key');
        }

        /*
         * ⛔ catalog 路徑讀整列 setting，一次。key 必須存在，且 `is_enabled`
         * 必須為 false：這個開關武裝的是自動派單，被異常打開時 catalog sync
         * 反而拒絕——「可以讀清單」絕不能與「已武裝派單」出現在同一個狀態裡。
         */
        $setting = $this->setting();
        $key = $setting?->secret('ApiKey');

        if (! is_string($key) || trim($key) === '') {
            return TheMostPanelCatalogFetchResult::blocked('blocked_no_credential');
        }

        if ($setting->is_enabled) {
            return TheMostPanelCatalogFetchResult::blocked('blocked_credential_enabled');
        }

        $payload = ['key' => $key, 'action' => TheMostPanelReadOnlyAction::Services->value];

        $startedAt = microtime(true);
        $sink = new TheMostPanelBoundedResponseStream(TheMostPanelResponseSizeGuard::MAX_BODY_BYTES);
        $transfer = new TheMostPanelTransferState;

        try {
            $response = $this->postExactlyOnce($payload, $sink, $transfer);
        } catch (Throwable $e) {
            return TheMostPanelCatalogFetchResult::failed(
                $this->transportFailureCode($e, $transfer),
                elapsedMs: $this->elapsed($startedAt),
            );
        }

        $elapsed = $this->elapsed($startedAt);
        $status = $response->status();

        $statusFailure = $this->statusFailureCode($status);

        if ($statusFailure !== null) {
            return TheMostPanelCatalogFetchResult::failed($statusFailure, $status, $elapsed);
        }

        $body = (string) $response->body();

        $bodyFailure = $this->bodyFailureCode($body);

        if ($bodyFailure !== null) {
            return TheMostPanelCatalogFetchResult::failed($bodyFailure, $status, $elapsed);
        }

        return TheMostPanelCatalogFetchResult::fetched($body, $status, $elapsed);
    }

    /**
     * The one request chain both read paths share.
     *
     * ⛔ Exactly one POST, no retry, no redirect, TLS verified, identity
     * encoding, native 2 MiB transport cap plus bounded sink. Copying this
     * chain is forbidden — two copies of these options will drift, and the
     * drifted one still carries the API key.
     */
    private function postExactlyOnce(
        array $payload,
        TheMostPanelBoundedResponseStream $sink,
        TheMostPanelTransferState $transfer,
    ) {
        return Http::asForm()
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TOTAL_TIMEOUT)
            // ⛔ 不自動重試：rate limit 未知。
            ->withoutRedirecting()
            /*
             * ⛔ 明確要求不壓縮，並關閉自動解壓。
             *
             * 否則「線路上 2 KB、解壓後 2 GB」就能繞過整套大小限制：cURL
             * 的上限看的是 wire bytes，而我們解析的是解壓後的內容。
             */
            ->withHeaders(['Accept-Encoding' => 'identity'])
            ->withOptions([
                // ⛔ TLS 驗證維持開啟；verify=false 永久禁止。
                'verify' => true,
                'decode_content' => false,
                /*
                 * ⛔ 真正的傳輸上限：由 libcurl 本身執行。
                 *
                 * bounded sink 只能限制我們「存下」多少；連線仍會繼續，
                 * 對方要送多少就送多少。8.4.0 起的 max-filesize 才會在
                 * 傳輸途中直接中止——這也是 runtime 能力閘存在的理由。
                 */
                'curl' => [
                    CURLOPT_MAXFILESIZE_LARGE => TheMostPanelResponseSizeGuard::MAX_BODY_BYTES,
                ],
                // handler 無關的第二層：限制實際保存的位元組數。
                'sink' => $sink,
                // 已宣告長度就超限、或宣告了壓縮編碼時，連第一個 byte 都不收。
                'on_headers' => function ($response) {
                    TheMostPanelResponseSizeGuard::assertContentLength($response->getHeaders());
                    TheMostPanelResponseSizeGuard::assertIdentityEncoding($response->getHeaders());
                },
                // ⛔ 只取 errno，不取任何訊息。
                'on_stats' => function ($stats) use ($transfer) {
                    $transfer->record($stats->getHandlerErrorData());
                },
                /*
                 * 額外一層，⛔ 但不是 hard cap：它何時觸發由 handler 決定，
                 * 不由我們決定。
                 */
                'progress' => function ($downloadTotal, $downloaded) {
                    TheMostPanelResponseSizeGuard::assertProgress((int) $downloaded);
                },
            ])
            ->post($this->endpoint(), $payload);
    }

    /**
     * ⛔ Classify a transport failure by errno first, exception chain second,
     * message never. `CURLE_FILESIZE_EXCEEDED` (63) is a transport-layer fact;
     * parsing exception text is guessing.
     */
    private function transportFailureCode(Throwable $e, TheMostPanelTransferState $transfer): string
    {
        if ($transfer->exceededMaxFileSize()) {
            return 'body_too_large';
        }

        // 其次才看是不是我們自己的 sink／header 中止。
        if (TheMostPanelResponseSizeGuard::isSizeAbort($e)) {
            return 'body_too_large';
        }

        if (TheMostPanelResponseSizeGuard::isEncodingRefusal($e)) {
            return 'unsupported_encoding';
        }

        return 'transport_failed';
    }

    /** ⛔ 3xx 不跟隨、429 明確標示；null 代表 2xx 可續讀。 */
    private function statusFailureCode(int $status): ?string
    {
        if ($status >= 300 && $status < 400) {
            return 'redirect_refused';
        }

        if ($status === 429) {
            return 'rate_limited';
        }

        if ($status >= 500) {
            return 'server_error';
        }

        // 4xx——以及理論上的 1xx——都當作 client 端不可用。
        if ($status >= 400 || $status < 200) {
            return 'client_error';
        }

        return null;
    }

    /** 成功 status 之下，body 仍不可用的情況；null 代表可續讀。 */
    private function bodyFailureCode(string $body): ?string
    {
        if ($body === '') {
            return 'empty_body';
        }

        // ⛔ 第二層：transport 中止若因故沒有生效，這裡仍然擋下。
        if (strlen($body) > TheMostPanelResponseSizeGuard::MAX_BODY_BYTES) {
            return 'body_too_large';
        }

        if (! mb_check_encoding($body, 'UTF-8')) {
            return 'invalid_encoding';
        }

        return null;
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

        /*
         * ⛔ 這個 runtime 有沒有能力真的中止超大傳輸？
         *
         * 沒有就停在這裡——在讀 credential、建立 request 之前。R2 已經證明
         * 「bounded sink ＋ 15 秒 timeout」不是傳輸上限：連線期間對方要送多少
         * 就送多少，我們只是不存下來。libcurl 8.4.0 之前
         * `CURLOPT_MAXFILESIZE_LARGE` 不會套用到進行中的傳輸。
         */
        if (! $this->capability()->supportsOngoingTransferCap()) {
            return 'blocked_unsupported_transport_cap';
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
        $key = $this->setting()?->secret('ApiKey');

        return is_string($key) && trim($key) !== '' ? $key : null;
    }

    /** 單一一次的 setting 查詢；⛔ 兩條路徑共用同一個讀取紀律。 */
    private function setting(): ?IntegrationSetting
    {
        return IntegrationSetting::query()
            ->where('provider', IntegrationProvider::TheMostPanel)
            ->where('environment', IntegrationEnvironment::Production)
            ->first();
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

        // ⛔ 與 catalog 路徑共用同一套 status／body 分類，不得各留一份。
        $statusFailure = $this->statusFailureCode($status);

        if ($statusFailure !== null) {
            return TheMostPanelProbeObservation::failed($action, $statusFailure, $status, $elapsed);
        }

        $body = (string) $response->body();

        $bodyFailure = $this->bodyFailureCode($body);

        if ($bodyFailure !== null) {
            return TheMostPanelProbeObservation::failed($action, $bodyFailure, $status, $elapsed);
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
