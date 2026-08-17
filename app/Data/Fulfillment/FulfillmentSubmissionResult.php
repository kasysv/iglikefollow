<?php

namespace App\Data\Fulfillment;

use App\Enums\FulfillmentAttentionReason;

/**
 * What the provider said when asked to place an order.
 *
 * Three outcomes, and the third is the one that matters:
 *
 *  - accepted: the order exists there, and its id is known.
 *  - rejected: refused for a reason that will not change on a retry.
 *  - unknown: we cannot tell. A timeout, a dropped connection, a response we
 *    could not read. ⛔ The order may or may not exist on their side, so this
 *    must never be treated as a failure and retried — doing so risks placing
 *    and paying for the same order twice, and delivering a service the customer
 *    did not buy.
 *
 * ⛔ There is no way to put provider text into this object. The reason comes
 * from a local enum, so an adapter that receives something it cannot classify
 * gets `Unknown` rather than storing a copy of what it was told.
 */
final class FulfillmentSubmissionResult
{
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $providerOrderId = null,
        public readonly ?FulfillmentAttentionReason $reason = null,
    ) {}

    /**
     * The provider took the order and gave us an id for it.
     *
     * ⛔ An acceptance without a usable id is not an acceptance. Recording one
     * would leave a row that claims to be dispatched with nothing to ask the
     * provider about — permanently unreconcilable. But the call did happen and
     * the order may well exist there, so the safe answer is `unknown`, never
     * `rejected` and never a retry.
     *
     * ⛔ The id is bounded and must look like an identifier. A provider that
     * echoes a sentence, or a malformed body read as a string, must not have
     * that text stored as though it were an order number.
     */
    public static function accepted(?string $providerOrderId): self
    {
        $id = trim((string) $providerOrderId);

        if ($id === '' || strlen($id) > 64 || ! preg_match('/^[A-Za-z0-9._\-]+$/', $id)) {
            return self::unknown(FulfillmentAttentionReason::UnreadableResponse);
        }

        return new self('accepted', $id);
    }

    /** 對方明確拒絕，且確定沒有成立。 */
    public static function rejected(
        FulfillmentAttentionReason $reason = FulfillmentAttentionReason::ProviderRejected,
    ): self {
        return new self('rejected', reason: $reason);
    }

    /** ⛔ 結果不明：可能已經成立，不得重送。 */
    public static function unknown(
        FulfillmentAttentionReason $reason = FulfillmentAttentionReason::Unknown,
    ): self {
        return new self('unknown', reason: $reason);
    }

    public function isAccepted(): bool
    {
        return $this->outcome === 'accepted';
    }

    public function isRejected(): bool
    {
        return $this->outcome === 'rejected';
    }

    public function isUnknown(): bool
    {
        return $this->outcome === 'unknown';
    }
}
