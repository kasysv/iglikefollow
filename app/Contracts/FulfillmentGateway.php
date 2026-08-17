<?php

namespace App\Contracts;

use App\Data\Fulfillment\FulfillmentSubmission;
use App\Data\Fulfillment\FulfillmentSubmissionResult;
use App\Data\Fulfillment\FulfillmentSyncResult;

/**
 * What a fulfilment provider must be able to do.
 *
 * Two operations, and both return a typed result rather than throwing. The
 * caller's decision — record it, refuse it, or park it for a human — depends
 * entirely on whether an order might exist on the provider's side, and an
 * exception carries that distinction badly.
 */
interface FulfillmentGateway
{
    /**
     * Place one order.
     *
     * ⛔ Must never be called twice for the same fulfilment row. Implementations
     * are not expected to deduplicate; that is settled before they are reached,
     * by an atomic claim and a unique index.
     */
    public function submit(FulfillmentSubmission $submission): FulfillmentSubmissionResult;

    /**
     * Ask what became of an order we know exists.
     *
     * ⛔ Read-only. This must never create anything.
     */
    public function sync(string $providerOrderId): FulfillmentSyncResult;
}
