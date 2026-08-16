<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An order became paid, and fulfilment may now be scheduled.
 *
 * This is the seam M4A will listen on to dispatch a TheMostPanel job. It is
 * deliberately inert in this milestone: nothing listens, and no external call
 * is made. RecordPaymentResult dispatches it exactly once per order, guarded
 * by the unique order_paid event row.
 */
class OrderPaid
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
