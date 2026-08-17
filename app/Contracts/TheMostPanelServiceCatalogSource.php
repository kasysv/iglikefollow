<?php

namespace App\Contracts;

use App\Data\Fulfillment\TheMostPanelCatalogFetchResult;

/**
 * Fetch the provider's `services` list — and nothing else.
 *
 * ⛔ One method, no parameters. The same API exposes `add`, `refill` and
 * `cancel`; a source that took an action string would make "refresh the
 * catalog" and "spend money at a supplier" the same code path with a
 * different argument. This contract cannot ask for anything but services.
 *
 * ⛔ Deliberately separate from both `FulfillmentGateway` (which can submit)
 * and `TheMostPanelReadOnlyProbe` (which observes shape and discards the
 * body). This is the only contract that returns a raw body at all, and it
 * returns it exactly once, for exactly one purpose: the CATALOG-A parser.
 *
 * ⛔ Never throws. Every outcome, including a refusal to send, comes back as
 * a fetch result with a local reason code.
 */
interface TheMostPanelServiceCatalogSource
{
    public function fetchServices(): TheMostPanelCatalogFetchResult;
}
