<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An order became paid, and fulfilment may now be scheduled.
 *
 * This is the seam M4A will listen on to dispatch a TheMostPanel job. It is
 * deliberately inert in this milestone: nothing listens, and no external call
 * is made.
 *
 * ShouldDispatchAfterCommit is the important part. The promotion to paid runs
 * inside a transaction, so without it a future listener could start — and
 * place a real SMM order — while that transaction is still open. If the
 * transaction then rolled back, the order would not be paid but the external
 * work would already have happened. Deferring to commit makes the event mean
 * "this order is durably paid" rather than "this order is probably paid".
 *
 * The unique order_paid event row remains the database-level idempotency
 * proof; this contract governs *when* the seam fires, not how often.
 */
class OrderPaid implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
