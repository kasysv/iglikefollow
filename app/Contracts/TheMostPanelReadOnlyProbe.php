<?php

namespace App\Contracts;

use App\Data\Fulfillment\TheMostPanelProbeObservation;
use App\Enums\TheMostPanelReadOnlyAction;

/**
 * Ask TheMostPanel a question, and learn only the shape of the answer.
 *
 * ⛔ Deliberately separate from `FulfillmentGateway`, which can `submit()`. If
 * this were bolted onto that interface, then discovering a response format and
 * placing a paid supplier order would share one binding, one config flag and
 * one enable path — and turning on the safe one would quietly arm the
 * expensive one.
 *
 * ⛔ Never throws. Every outcome, including a refusal to send at all, comes
 * back as an observation with a local reason code.
 */
interface TheMostPanelReadOnlyProbe
{
    /**
     * @param  string|null  $orderId  required for `status`, forbidden otherwise
     */
    public function probe(
        TheMostPanelReadOnlyAction $action,
        ?string $orderId = null,
    ): TheMostPanelProbeObservation;
}
