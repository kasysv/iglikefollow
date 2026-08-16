<?php

namespace App\Jobs;

use App\Actions\Invoices\CreateInvoiceForPaidOrder;
use App\Actions\Invoices\IssueInvoice;
use App\Enums\InvoiceStatus;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
class IssueInvoiceForOrder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * ⛔ 只重試「取得發票紀錄」這一段。真正的開立由 IssueInvoice 的
     * atomic claim 保護，ambiguous 結果永遠不會走到重試。
     */
    public int $tries = 3;

    /**
     * ⛔ ShouldBeUnique 必須真的實作，`uniqueId()` 本身不會取得任何鎖。
     *
     * 沒有這個介面時，Laravel 根本不會去看 uniqueId()——那個方法只是一段
     * 沒人呼叫的程式碼，而同一張訂單仍可能被兩個 worker 同時處理。
     *
     * 鎖在 handle() 結束（成功或丟例外）時釋放，最長不超過 uniqueFor 秒；
     * ⛔ 這道鎖只是第一層，真正的保證是 IssueInvoice 的 compare-and-set。
     */
    public int $uniqueFor = 300;

    public function __construct(public readonly int $orderId) {}

    /** ⛔ 用訂單 id 這個永不改變的值；隨嘗試次數變動的鍵等於沒有鎖。 */
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
