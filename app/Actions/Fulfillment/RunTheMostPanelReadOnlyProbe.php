<?php

namespace App\Actions\Fulfillment;

use App\Contracts\TheMostPanelReadOnlyProbe;
use App\Data\Fulfillment\TheMostPanelProbeObservation;
use App\Enums\TheMostPanelReadOnlyAction;

/**
 * One probe, one request, one observation.
 *
 * ⛔ Exists so the command has no reason to touch the HTTP client directly, and
 * so there is exactly one place where "how many requests does a probe make"
 * is answered. The answer is one.
 *
 * ⛔ Nothing here is persisted. The observation is returned to the caller and
 * printed; it does not reach the database, the log or a queue.
 */
class RunTheMostPanelReadOnlyProbe
{
    public function __construct(private readonly TheMostPanelReadOnlyProbe $probe) {}

    public function handle(
        TheMostPanelReadOnlyAction $action,
        ?string $orderId = null,
    ): TheMostPanelProbeObservation {
        return $this->probe->probe($action, $orderId);
    }
}
