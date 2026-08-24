<?php

namespace App\Services\Fulfillment;

use App\Contracts\FulfillmentGateway;
use App\Contracts\TheMostPanelDispatchCredentialSource;
use App\Data\Fulfillment\FulfillmentSubmission;
use App\Data\Fulfillment\FulfillmentSubmissionResult;
use App\Data\Fulfillment\FulfillmentSyncResult;
use App\Enums\FulfillmentAttentionReason;
use App\Enums\FulfillmentStatus;
use App\Services\Integrations\ProviderEndpoints;
use Illuminate\Http\Client\Response;
use JsonException;
use stdClass;
use Throwable;

/**
 * TheMostPanel dispatch adapter: one `add`, one single-order `status`.
 *
 * ⛔ Local-contract work only (M4B-DISPATCH-ADAPTER-A). This class encodes
 * what the PUBLIC docs example demonstrably promises and nothing more:
 * `action=add` with `service + link + quantity`, `action=status` with one
 * `order`. Comments, drip-feed, subscription, refill, cancel and balance are
 * deliberately absent. It has never spoken to the real provider; that needs
 * its own gate, its own approval and its own budget.
 *
 * ⛔ Asymmetry rules every branch. A duplicate `add` costs real money and
 * delivers what nobody bought, so anything that MIGHT have been placed —
 * timeout, non-200, unreadable body, success without a usable id, conflicting
 * fields — is `unknown`, never `rejected`, never retried. Only a definite
 * pre-network refusal (no endpoint, no capability, no credential, bad
 * payload) or a definite provider error object may say "this never existed".
 *
 * ⛔ Nothing provider-authored survives: no message, no body, no echoed key.
 * The status map accepts four exact tokens the public docs demonstrated, and
 * everything else — case variants included — is `unrecognised`, because an
 * unknown status rounded to `completed` closes an order that is still
 * running, or one that failed.
 */
class TheMostPanelFulfillmentGateway implements FulfillmentGateway
{
    /**
     * ⛔ 與版本控制中唯一合法值整串比對;不解析、不正規化。
     *
     * ⛔ 定義只有一份,在 `ProviderEndpoints`:R1 之後 staging 與 production
     * 讀同一個正式端點,兩處各寫一份字串,某天只改一處就出現「比對通過、
     * 送去的卻是另一個網址」。
     */
    private const ENDPOINT = ProviderEndpoints::THEMOSTPANEL_DISPATCH;

    /** provider order/service ID 的 canonical 形狀:正整數 digit string,≤64。 */
    private const ID_PATTERN = '/\A[1-9][0-9]*\z/';

    private const MAX_ID_LENGTH = 64;

    /**
     * ⛔ 公開文件實際示範過的四個 status token,exact match(含大小寫與
     * 空白)。文件沒有承諾完整 enum,所以其他一切都是 unrecognised。
     *
     * @var array<string, FulfillmentStatus>
     */
    private const STATUS_MAP = [
        'In progress' => FulfillmentStatus::Processing,
        'Completed' => FulfillmentStatus::Completed,
        'Partial' => FulfillmentStatus::Partial,
        'Rejected' => FulfillmentStatus::Failed,
    ];

    public function __construct(
        private readonly TheMostPanelDispatchCredentialSource $credentials,
        private readonly ?TheMostPanelCurlCapability $capability = null,
        private readonly ?TheMostPanelHardenedTransport $transport = null,
    ) {}

    public function submit(FulfillmentSubmission $submission): FulfillmentSubmissionResult
    {
        /*
         * ⛔ R1:網路前的每一個阻擋都是「確定沒送出」的設定問題 → blocked,
         * 收斂 configuration_pending;永不 unknown,也不冒充 provider rejected。
         */
        if ($this->blockedBeforeNetwork()) {
            return FulfillmentSubmissionResult::blocked(FulfillmentAttentionReason::DispatchDisabled);
        }

        if (! $this->isCanonicalId($submission->providerServiceId)
            || $submission->quantity < 1
            || trim($submission->target) === '') {
            // 我們自己的資料不合法:同樣確定沒送出。
            return FulfillmentSubmissionResult::blocked(FulfillmentAttentionReason::UnsupportedPayload);
        }

        try {
            $key = $this->credentials->apiKey();
        } catch (Throwable) {
            /*
             * ⛔ credential source 意外 throw:發生在 request 前,仍是
             * blocked;不得變 submission_unknown,也不帶出 exception 訊息。
             */
            return FulfillmentSubmissionResult::blocked(FulfillmentAttentionReason::DispatchDisabled);
        }

        if ($key === null || trim($key) === '') {
            return FulfillmentSubmissionResult::blocked(FulfillmentAttentionReason::DispatchDisabled);
        }

        // ⛔ 只支援公開文件的一般 payload:add + service + link + quantity。
        $outcome = $this->postAndRead($key, [
            'key' => $key,
            'action' => 'add',
            'service' => $submission->providerServiceId,
            'link' => $submission->target,
            'quantity' => $submission->quantity,
        ]);

        if (! $outcome instanceof stdClass) {
            // 從送出那一刻起,一切讀不懂都可能已成立。
            return FulfillmentSubmissionResult::unknown($outcome);
        }

        return $this->readAddResponse($outcome);
    }

    public function sync(string $providerOrderId): FulfillmentSyncResult
    {
        // ⛔ 唯讀;任何不能確定的情況都是 unrecognised,維持原狀。
        if ($this->blockedBeforeNetwork() || ! $this->isCanonicalId($providerOrderId)) {
            return FulfillmentSyncResult::unrecognised();
        }

        try {
            $key = $this->credentials->apiKey();
        } catch (Throwable) {
            // ⛔ request 前的 credential 問題:unrecognised、0 request。
            return FulfillmentSyncResult::unrecognised();
        }

        if ($key === null || trim($key) === '') {
            return FulfillmentSyncResult::unrecognised();
        }

        $outcome = $this->postAndRead($key, [
            'key' => $key,
            'action' => 'status',
            'order' => $providerOrderId,
        ]);

        if (! $outcome instanceof stdClass) {
            return FulfillmentSyncResult::unrecognised();
        }

        return $this->readStatusResponse($outcome);
    }

    /**
     * 網路前的固定閘:環境邊界、endpoint、runtime 傳輸能力。
     *
     * ⛔ Owner 的總開關不在這裡重複檢查——它已內含在 credential source
     * (row 未啟用 → apiKey() null → blocked),而且那份檢查每次 submit 都
     * 重新查 DB,所以 Owner 關掉開關後,queue 裡已排入的 job 也會在網路前
     * 停止。這裡負責的是不隨開關改變的技術邊界。
     */
    private function blockedBeforeNetwork(): bool
    {
        /*
         * ⛔ R1:production 不再無條件拒絕——正式派單由 Owner 的總開關決定,
         * 而那個開關已內含在 credential source(row 未啟用 → apiKey() null
         * → blocked),每次 submit 都重新查 DB。剩下的環境邊界是這一條:
         *
         * ⛔ local 一律拒絕。舊版靠「local 沒有 env-keyed endpoint 設定」這個
         * 巧合擋住本機;R1 端點統一讀 production allowlist 之後,巧合消失,
         * 換成這個明確的拒絕——本機開發永遠不該對供應商下真單。
         *
         * `testing` 不在此列:那是 phpunit 的環境,transport 走 Laravel Http,
         * 測試以 `Http::fake()`＋`preventStrayRequests()` 完全接管;adapter
         * 的 e2e 測試就在這個環境跑。
         */
        if (app()->environment('local')) {
            return true;
        }

        /*
         * ⛔ 端點一律讀 production allowlist,不依 APP_ENV 拼接 config key:
         * 一個新環境名稱不該成為一個沒人 review 過的端點來源。
         */
        if (ProviderEndpoints::theMostPanelDispatch() !== self::ENDPOINT) {
            return true;
        }

        $capability = $this->capability ?? TheMostPanelCurlCapability::fromRuntime();

        // ⛔ runtime 無法中止超大傳輸就不送:與 RO-A 同一 fail-closed 理由。
        return ! $capability->supportsOngoingTransferCap();
    }

    private function isCanonicalId(string $value): bool
    {
        return strlen($value) <= self::MAX_ID_LENGTH
            && preg_match(self::ID_PATTERN, $value) === 1;
    }

    /**
     * POST once and read the body into a decoded object — or a reason.
     *
     * @param  array<string, mixed>  $payload
     * @return stdClass|FulfillmentAttentionReason decoded JSON object,或
     *                                             「讀不懂」的本地原因(⛔ 從不是 provider 文字)
     */
    private function postAndRead(string $key, array $payload): stdClass|FulfillmentAttentionReason
    {
        $sink = new TheMostPanelBoundedResponseStream(TheMostPanelResponseSizeGuard::MAX_BODY_BYTES);
        $transfer = new TheMostPanelTransferState;

        try {
            $response = ($this->transport ?? new TheMostPanelHardenedTransport)
                ->postExactlyOnce(self::ENDPOINT, $payload, $sink, $transfer);
        } catch (Throwable $e) {
            /*
             * ⛔ R1(curl 7.68):size abort 以 sink 的 overflow state 辨認,
             * 不讀 provider／cURL 的錯誤文字。sink short write 會讓 libcurl
             * 以 write error 中止,浮上來的是一般的 transport 例外——但
             * `overflowed()` 明確記著「這是我們自己拒收的」。header 階段的
             * 拒絕(宣告長度超限/壓縮編碼)與新版 libcurl 的原生 63 也一併
             * 歸入同類。
             *
             * ⛔ 對 `add` 而言這仍是「可能已成立」:對方可能已收單,只是回應
             * 太大——收斂 submission_unknown、絕不自動重送。
             */
            if ($sink->overflowed()
                || $transfer->exceededMaxFileSize()
                || TheMostPanelResponseSizeGuard::isSizeAbort($e)
                || TheMostPanelResponseSizeGuard::isEncodingRefusal($e)) {
                return FulfillmentAttentionReason::UnreadableResponse;
            }

            /*
             * ⛔ errno 不細分訊息:連線逾時、TLS、中斷都可能已送達。
             * 統一 Timeout 類的「可能已成立」語意。
             */
            return FulfillmentAttentionReason::Timeout;
        }

        // 防禦性:transfer 沒失敗但 sink 曾拒收——內容不完整,不可解析。
        if ($sink->overflowed()) {
            return FulfillmentAttentionReason::UnreadableResponse;
        }

        return $this->readBody($response, $key);
    }

    private function readBody(Response $response, string $key): stdClass|FulfillmentAttentionReason
    {
        if ($response->status() !== 200) {
            // ⛔ 4xx／5xx 都不證明未成立;不猜。
            return FulfillmentAttentionReason::UnreadableResponse;
        }

        $body = (string) $response->body();

        if ($body === '' || strlen($body) > TheMostPanelResponseSizeGuard::MAX_BODY_BYTES) {
            return FulfillmentAttentionReason::UnreadableResponse;
        }

        if (! mb_check_encoding($body, 'UTF-8')) {
            return FulfillmentAttentionReason::UnreadableResponse;
        }

        /*
         * ⛔ credential echo:整個結果 fail closed。key 只存在於這個
         * stack frame;body 從此丟棄,不保存、不記錄、不顯示。
         */
        if (TheMostPanelCredentialEchoGuard::echoes($body, $key)) {
            return FulfillmentAttentionReason::UnreadableResponse;
        }

        try {
            // ⛔ 不用 assoc:與 echo guard／catalog parser 同一型別紀律。
            $decoded = json_decode($body, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return FulfillmentAttentionReason::UnreadableResponse;
        }

        if (! $decoded instanceof stdClass) {
            // 文件範例的 add／單筆 status 都是 object;其他 shape 不可判定。
            return FulfillmentAttentionReason::UnreadableResponse;
        }

        return $decoded;
    }

    /**
     * The strict `add` success contract.
     *
     * ⛔ Success is exactly: an object whose `order` is a positive integer or
     * a canonical positive digit string, with no conflicting `error` beside
     * it. Everything else — 0, negatives, floats, arrays, overlong, blank,
     * conflict — is `unknown`: the call happened, the order may exist.
     */
    private function readAddResponse(stdClass $decoded): FulfillmentSubmissionResult
    {
        $hasOrder = property_exists($decoded, 'order');
        $hasError = property_exists($decoded, 'error');

        if ($hasOrder && $hasError) {
            // ⛔ 衝突欄位:不可判定,可能已成立。
            return FulfillmentSubmissionResult::unknown(FulfillmentAttentionReason::UnreadableResponse);
        }

        if ($hasOrder) {
            $id = $this->canonicalOrderId($decoded->order);

            return $id === null
                // ⛔ 成功卻沒有可用 ID:永遠 unknown,絕不 rejected、絕不重送。
                ? FulfillmentSubmissionResult::unknown(FulfillmentAttentionReason::UnreadableResponse)
                : FulfillmentSubmissionResult::accepted($id);
        }

        if ($hasError && is_string($decoded->error) && trim($decoded->error) !== '') {
            /*
             * 明確的 provider error object:文件示範的「未成立」形狀。
             * ⛔ message 本身到此為止——不保存、不顯示、不記錄。
             */
            return FulfillmentSubmissionResult::rejected(FulfillmentAttentionReason::ProviderRejected);
        }

        return FulfillmentSubmissionResult::unknown(FulfillmentAttentionReason::UnreadableResponse);
    }

    /** 正整數或 canonical positive digit string → bounded ID;其他一律 null。 */
    private function canonicalOrderId(mixed $order): ?string
    {
        if (is_int($order) && $order >= 1) {
            return (string) $order;
        }

        if (is_string($order) && $this->isCanonicalId($order)) {
            return $order;
        }

        return null;
    }

    private function readStatusResponse(stdClass $decoded): FulfillmentSyncResult
    {
        if (! property_exists($decoded, 'status') || ! is_string($decoded->status)) {
            return FulfillmentSyncResult::unrecognised();
        }

        // ⛔ exact token:大小寫、空白差異都不是我們認得的狀態。
        $status = self::STATUS_MAP[$decoded->status] ?? null;

        return $status === null
            ? FulfillmentSyncResult::unrecognised()
            : FulfillmentSyncResult::status($status);
    }
}
