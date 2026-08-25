<?php

namespace App\Jobs;

use App\Actions\Fulfillment\SyncFulfillmentState;
use App\Models\FulfillmentOrder;
use App\Services\Fulfillment\FulfillmentDispatchGate;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Refresh one fulfilment row's status from the provider.
 *
 * ⛔ M4C：現在有排程了。`routes/console.php` 每 10 分鐘跑
 * `fulfillment:queue-status-sync`(`QueueFulfillmentStatusSync`),只挑
 * `submitted`／`processing` 且有 provider order id 的列,經由
 * `ShouldBeUnique` 排入這個 job。排程本身在非 staging／production 或
 * Owner 總開關關閉時只排入 0——local／production 不受影響、不會外呼。
 * 這個 job 的 `handle()` 在做任何網路 I/O 之前仍會再讀一次總開關,見下方。
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
        /*
         * ⛔ R1:執行時再讀一次總開關。
         *
         * 這個 job 可能是在開關還開著時排入的;Owner 關掉之後,queue 裡
         * 已排入、尚未執行的 job 必須在任何網路 I/O 之前停止。⛔ 靜默返回
         * 而不寫 event:關一個開關不該在每列 timeline 灌一筆「讀不懂」。
         */
        if (! FulfillmentDispatchGate::enabled()) {
            return;
        }

        $fulfillment = FulfillmentOrder::query()->find($this->fulfillmentOrderId);

        if ($fulfillment === null) {
            return;
        }

        $sync->handle($fulfillment);
    }
}
