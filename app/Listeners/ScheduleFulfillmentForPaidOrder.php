<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Jobs\PrepareFulfillmentForPaidOrder;

/**
 * Queue fulfilment preparation once an order is genuinely paid.
 *
 * ⛔ Does nothing but dispatch. `OrderPaid` also drives invoicing, and the two
 * must not be able to break each other: a throw in this listener would take the
 * invoice listener down with it, and vice versa. Queueing is the smallest thing
 * that can be done here, so it is all that is done.
 *
 * ⛔ Not registered in `AppServiceProvider`. Laravel discovers listeners in
 * `app/Listeners` by the type hint on `handle()`; listing it manually as well
 * would run it twice for every paid order.
 */
class ScheduleFulfillmentForPaidOrder
{
    public function handle(OrderPaid $event): void
    {
        // ⛔ 傳整數 id，不傳 model：queue payload 會被寫入儲存、重試與記錄。
        PrepareFulfillmentForPaidOrder::dispatch($event->order->id);
    }
}
