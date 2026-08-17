<?php

namespace App\Data\Fulfillment;

/**
 * Everything a provider needs to place one order, and nothing else.
 *
 * ⛔ `$target` is the customer's account or post URL. It is read from the
 * encrypted order snapshot at the moment of submission and passed straight
 * through — it is never stored again, never logged, never put in a queue
 * payload, and never written to the fulfilment tables.
 *
 * ⛔ This object is deliberately not serializable into a job. Jobs carry
 * integer ids; a queue payload is written to storage, retried and often logged,
 * so a customer's account must not be able to reach one.
 */
final class FulfillmentSubmission
{
    public function __construct(
        public readonly string $providerServiceId,
        public readonly string $target,
        public readonly int $quantity,
    ) {}

    /**
     * The inputs the fingerprint is computed from.
     *
     * ⛔ Excludes the target. The fingerprint is stored, and a keyed hash of a
     * short account name is still a hash of a customer's account — the service
     * id and quantity are enough to answer "was this the same call".
     */
    public function fingerprintInputs(): array
    {
        return [
            'provider_service_id' => $this->providerServiceId,
            'quantity' => $this->quantity,
        ];
    }
}
