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

    public static function accepted(string $providerOrderId): self
    {
        return new self('accepted', $providerOrderId);
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
