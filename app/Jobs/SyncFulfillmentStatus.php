<?php

namespace App\Jobs;

use App\Actions\Fulfillment\SyncFulfillmentState;
use App\Models\FulfillmentOrder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Refresh one fulfilment row's status from the provider.
 *
 * ⛔ Not scheduled. M4A registers no scheduler and does not touch
 * `routes/console.php`: polling needs a proven status contract and a known rate
 * limit, and we have neither. This job exists so the read path is built and
 * tested; M4B decides when it runs.
 *
 * Retrying is safe — the operation is read-only and creates nothing.
 */
class SyncFulfillmentStatus implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $fulfillmentOrderId) {}

    public function uniqueId(): string
    {
        return 'fulfillment-sync-'.$this->fulfillmentOrderId;
    }

    public function handle(SyncFulfillmentState $sync): void
    {
        $fulfillment = FulfillmentOrder::query()->find($this->fulfillmentOrderId);

        if ($fulfillment === null) {
            return;
        }

        $sync->handle($fulfillment);
    }
}
