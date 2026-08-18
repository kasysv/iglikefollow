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
 * ⛔ It refuses rather than throwing, and it refuses with `blocked` rather than
 * `unknown` or `rejected`. That distinction is the whole point — nothing was
 * sent, so nothing can exist on a provider's side, and the row converges to
 * `configuration_pending`, safe to reprocess once someone configures it.
 * Reporting `unknown` would park it for manual reconciliation over a call that
 * provably never happened; `rejected` would mark it terminally failed.
 */
class DisabledFulfillmentGateway implements FulfillmentGateway
{
    public function submit(FulfillmentSubmission $submission): FulfillmentSubmissionResult
    {
        // ⛔ R1:0 request 的設定問題是 blocked → configuration_pending,不是 failed。
        return FulfillmentSubmissionResult::blocked(FulfillmentAttentionReason::DispatchDisabled);
    }

    public function sync(string $providerOrderId): FulfillmentSyncResult
    {
        // ⛔ 沒有可查詢的對象；不猜測狀態。
        return FulfillmentSyncResult::unrecognised();
    }
}
