<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Jobs\IssueInvoiceForOrder;

/**
 * Turn "this order is paid" into a queued invoice job.
 *
 * Deliberately thin. OrderPaid is also the seam M4A will use to schedule
 * fulfilment, and the two must not be able to break each other: an invoice
 * provider being down should never stop a customer's followers being
 * delivered, and vice versa. Doing nothing here but queueing keeps that true —
 * a throw in this listener cannot take another listener with it, because there
 * is nothing here to throw.
 *
 * ⛔ Failing to issue an invoice never rewrites the payment. The money did
 * arrive; a tax document that has not been produced yet is a separate problem
 * with a separate state.
 */
class ScheduleInvoiceForPaidOrder
{
    public function handle(OrderPaid $event): void
    {
        IssueInvoiceForOrder::dispatch($event->order->id);
    }
}
