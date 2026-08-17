<?php

namespace App\Data\Fulfillment;

/**
 * One validated entry from a `services` response.
 *
 * ⛔ Only the parser constructs these, and only from input that survived every
 * check — so holding one is proof the values are typed, bounded and free of
 * control characters. Nothing here is interpreted: the rate and quantity
 * bounds stay verbatim strings, because their currency and billing unit are
 * unverified and float math on money is forbidden.
 */
final class TheMostPanelServiceDefinition
{
    public function __construct(
        /** Canonical positive-integer decimal string, e.g. "1234". */
        public readonly string $providerServiceId,
        public readonly string $name,
        /** Provider's `type` verbatim — ⛔ not this site's payload type. */
        public readonly string $serviceType,
        public readonly string $category,
        /** Provider's `rate` verbatim — ⛔ not a retail price. */
        public readonly string $rateRaw,
        public readonly string $minimumQuantityRaw,
        public readonly string $maximumQuantityRaw,
        /** Documented capability flags — ⛔ not permission to offer them here. */
        public readonly bool $supportsRefill,
        public readonly bool $supportsCancel,
    ) {}
}
