<?php

namespace App\Observers;

use App\Models\FulfillmentEvent;
use RuntimeException;

/**
 * The timeline is written once and never touched again.
 *
 * ⛔ A timeline that can be edited or pruned afterwards is not evidence of
 * anything — it is just a description of what someone currently wants the
 * history to look like. When a fulfilment row ends up in
 * `submission_unknown`, this timeline is what a person reconciles against.
 *
 * The database enforces the same rule; this layer only makes the failure
 * legible where developers work.
 */
class FulfillmentEventIntegrityObserver
{
    public function updating(FulfillmentEvent $event): void
    {
        throw new RuntimeException('⛔ 履約事件為 append-only，不得修改。');
    }

    public function deleting(FulfillmentEvent $event): void
    {
        throw new RuntimeException('⛔ 履約事件為 append-only，不得刪除。');
    }
}
