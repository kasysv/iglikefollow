<?php

namespace App\Services\Fulfillment;

use App\Contracts\FulfillmentGateway;
use App\Contracts\TheMostPanelDispatchCredentialSource;
use App\Data\Fulfillment\FulfillmentSubmission;
use App\Data\Fulfillment\FulfillmentSubmissionResult;
use App\Data\Fulfillment\FulfillmentSyncResult;
use App\Enums\FulfillmentAttentionReason;
use App\Enums\FulfillmentStatus;
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
    /** ⛔ 與版本控制中唯一合法值整串比對;不解析、不正規化。 */
    private const ENDPOINT = 'https://themostpanel.com/api/v2';

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
        // ⛔ 網路前的每一個拒絕都是「確定沒送出」:rejected,永不 unknown。
        if ($this->blockedBeforeNetwork()) {
            return FulfillmentSubmissionResult::rejected(FulfillmentAttentionReason::DispatchDisabled);
        }

        if (! $this->isCanonicalId($submission->providerServiceId)
            || $submission->quantity < 1
            || trim($submission->target) === '') {
            // 我們自己的資料不合法:同樣確定沒送出。
            return FulfillmentSubmissionResult::rejected(FulfillmentAttentionReason::UnsupportedPayload);
        }

        $key = $this->credentials->apiKey();

        if ($key === null || trim($key) === '') {
            return FulfillmentSubmissionResult::rejected(FulfillmentAttentionReason::DispatchDisabled);
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

        $key = $this->credentials->apiKey();

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
     * 網路前的固定閘:production、endpoint、runtime 傳輸能力。
     *
     * ⛔ endpoint 讀 `integrations.endpoints.themostpanel` 的當前環境值,
     * 與唯一合法字串整串比對——production config 維持空字串,所以這裡
     * 在正式環境永遠 fail closed,連同 container binding 的雙保險。
     */
    private function blockedBeforeNetwork(): bool
    {
        if (app()->environment('production')) {
            return true;
        }

        $endpoint = (string) config('integrations.endpoints.themostpanel.'.app()->environment(), '');

        if ($endpoint !== self::ENDPOINT) {
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
        } catch (TheMostPanelResponseTooLarge) {
            return FulfillmentAttentionReason::UnreadableResponse;
        } catch (Throwable) {
            /*
             * ⛔ errno 不細分訊息:連線逾時、TLS、中斷都可能已送達。
             * 統一 Timeout 類的「可能已成立」語意。
             */
            return FulfillmentAttentionReason::Timeout;
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
