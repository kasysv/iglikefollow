<?php

namespace App\Jobs;

use App\Actions\Fulfillment\PrepareFulfillmentForOrder;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Create the fulfilment rows for one paid order.
 *
 * ⛔ `ShouldBeUnique` is actually implemented, not just `uniqueId()`. Laravel
 * never reads that method on its own, so declaring it without the interface
 * would leave no lock at all while looking like there was one.
 *
 * Retrying is safe: every row is claimed through a unique `order_item_id`, so a
 * second run finds what the first created rather than making more.
 */
class PrepareFulfillmentForPaidOrder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    /**
     * ⛔ 只帶整數 id。
     *
     * queue payload 會被寫入儲存、重試並常常被記錄，所以客人的帳號、Email 與
     * 任何 credential 都不得出現在這裡。
     */
    public function __construct(public readonly int $orderId) {}

    /** ⛔ 用永不改變的訂單 id；隨嘗試次數變動的鍵等於沒有鎖。 */
    public function uniqueId(): string
    {
        return 'fulfillment-order-'.$this->orderId;
    }

    public function handle(PrepareFulfillmentForOrder $prepare): void
    {
        $order = Order::query()->find($this->orderId);

        // ⛔ 重新讀資料庫：訂單可能已被取消，或這個事件是重播的。
        if ($order === null || ! $order->isPaid()) {
            return;
        }

        foreach ($prepare->handle($order) as $fulfillment) {
            SubmitFulfillmentOrder::dispatch($fulfillment->id);
        }
    }
}
