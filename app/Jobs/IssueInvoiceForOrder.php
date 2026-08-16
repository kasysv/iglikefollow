<?php

namespace App\Jobs;

use App\Actions\Invoices\CreateInvoiceForPaidOrder;
use App\Actions\Invoices\IssueInvoice;
use App\Enums\InvoiceStatus;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Issue the invoice for a paid order, off the request.
 *
 * Queued rather than inline because the customer's payment has already
 * succeeded: whether the tax authority's system answers in 200ms or times out
 * must not decide what the customer sees, and must certainly not roll anything
 * back.
 *
 * ⛔ Carries an order id, not credentials or buyer details — a queue payload is
 * stored, retried and often logged.
 *
 * Safe to deliver twice: creating the invoice is idempotent on a unique
 * order_id, and issuing is idempotent on a unique attempt key.
 */
class IssueInvoiceForOrder implements ShouldQueue
{
    use Queueable;

    /**
     * ⛔ 只重試「取得發票紀錄」這一段。真正的開立由 IssueInvoice 內部的
     * 冪等鍵保護，ambiguous 結果永遠不會走到重試。
     */
    public int $tries = 3;

    public function __construct(public readonly int $orderId) {}

    /** 同一張訂單的發票工作不並行，避免兩個 worker 同時嘗試。 */
    public function uniqueId(): string
    {
        return 'invoice-order-'.$this->orderId;
    }

    public function handle(CreateInvoiceForPaidOrder $create, IssueInvoice $issue): void
    {
        $order = Order::find($this->orderId);

        if ($order === null || ! $order->isPaid()) {
            return; // ⛔ 訂單不見了或還沒付款就什麼都不做。
        }

        $invoice = $create->handle($order);

        // credential 還沒設定就停在這裡，⛔ 不呼叫網路、不無限重試。
        if ($invoice->status !== InvoiceStatus::Pending) {
            return;
        }

        $issue->handle($invoice);
    }
}
