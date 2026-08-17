<?php

namespace App\Services\Fulfillment;

use App\Contracts\FulfillmentGateway;
use App\Data\Fulfillment\FulfillmentSubmission;
use App\Data\Fulfillment\FulfillmentSubmissionResult;
use App\Data\Fulfillment\FulfillmentSyncResult;
use App\Enums\FulfillmentAttentionReason;

/**
 * The default: a gateway that never contacts anyone.
 *
 * ⛔ It refuses rather than throwing, and it refuses with `rejected` rather than
 * `unknown`. That distinction is the whole point — nothing was sent, so nothing
 * can exist on a provider's side, and the row is safe to retry once someone
 * configures it. Reporting `unknown` here would park a row for manual
 * reconciliation over a call that provably never happened.
 */
class DisabledFulfillmentGateway implements FulfillmentGateway
{
    public function submit(FulfillmentSubmission $submission): FulfillmentSubmissionResult
    {
        return FulfillmentSubmissionResult::rejected(FulfillmentAttentionReason::DispatchDisabled);
    }

    public function sync(string $providerOrderId): FulfillmentSyncResult
    {
        // ⛔ 沒有可查詢的對象；不猜測狀態。
        return FulfillmentSyncResult::unrecognised();
    }
}
