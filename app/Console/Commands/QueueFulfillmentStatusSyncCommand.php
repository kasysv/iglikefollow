<?php

namespace App\Console\Commands;

use App\Actions\Fulfillment\QueueFulfillmentStatusSync;
use Illuminate\Console\Command;

/**
 * The scheduler's entry point for fulfilment status polling.
 *
 * ⛔ A thin shell over the action: it prints a count and nothing else. It
 * never calls the provider, never touches submission, and outside staging
 * (or with the flag off) it queues exactly zero jobs.
 */
class QueueFulfillmentStatusSyncCommand extends Command
{
    protected $signature = 'fulfillment:queue-status-sync';

    protected $description = '挑選可同步的履約列並排入狀態查詢 jobs(staging 專用,default off;不呼叫 provider)';

    public function handle(QueueFulfillmentStatusSync $queue): int
    {
        if (! QueueFulfillmentStatusSync::enabled()) {
            $this->line('status polling 未啟用(僅 staging＋flag);本輪排入 0。');

            return self::SUCCESS;
        }

        $queued = $queue->handle();

        $this->line('已排入 '.$queued.' 個狀態查詢 job。');

        return self::SUCCESS;
    }
}
