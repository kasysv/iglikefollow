<?php

namespace App\Data\Fulfillment;

/**
 * One entry from a `services` response, as the parser validated it.
 *
 * ⛔ Holding one is NOT proof of validation. PHP cannot stop other code from
 * constructing this class by hand — a reviewer did exactly that and walked
 * unvalidated values past an `instanceof` check. It is a carrier between the
 * parser and the snapshot action, nothing more; the snapshot seam therefore
 * accepts only a raw JSON body and runs the parser itself, so a hand-built
 * definition has no door to walk through.
 *
 * Nothing here is interpreted: the rate and quantity bounds stay verbatim
 * strings, because their currency and billing unit are unverified and float
 * math on money is forbidden.
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
