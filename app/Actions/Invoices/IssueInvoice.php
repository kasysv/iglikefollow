<?php

namespace App\Actions\Invoices;

use App\Contracts\InvoiceGateway;
use App\Enums\InvoiceAttemptStatus;
use App\Enums\InvoiceFailureReason;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceAttempt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ask the gateway to issue an invoice, once.
 *
 * The provider call is the irreversible part, so ownership of it is settled
 * before it happens: one transaction locks the invoice row, checks it is still
 * `pending`, moves it to `processing` and writes the attempt. A second worker
 * arriving at the same moment finds the row locked, then finds it no longer
 * `pending`, and returns without calling anyone.
 *
 * ⛔ This is a compare-and-set, not a read-then-write. Counting existing
 * attempts to derive the next key would let two workers each compute a
 * different key and both reach the provider — the unique index would accept
 * both, because they are not duplicates of each other.
 *
 * ⛔ Ambiguous results and unexpected exceptions both stop permanently. The
 * provider may already have issued a real invoice, and asking again could
 * produce a second one for the same order.
 */
class IssueInvoice
{
    public function __construct(private readonly InvoiceGateway $gateway) {}

    public function handle(Invoice $invoice): Invoice
    {
        $attempt = $this->claim($invoice);

        if ($attempt === null) {
            // 沒搶到（或狀態已不是 pending）：⛔ 絕不呼叫 gateway。
            return $invoice->fresh();
        }

        $invoice = $invoice->fresh();

        try {
            $result = $this->gateway->issue($invoice, $attempt->idempotency_key);
        } catch (Throwable $e) {
            // ⛔ 逾時或任何未知例外都不是「失敗」：對方可能已經開出發票。
            // 留在 processing／started 會讓紀錄永遠卡住，所以在這裡收斂。
            //
            // ⛔ 只帶 reason token，不帶 $e->getMessage()：例外訊息常含連線字串、
            // 商店代號，甚至被回音的請求內容。
            $this->recordAmbiguous($invoice, $attempt, InvoiceFailureReason::Unknown);

            // ⛔ 不重新丟出：丟出會讓 queue 自動重試，等於再呼叫一次 provider。
            return $invoice->fresh();
        }

        if ($result->isIssued()) {
            return $this->recordIssued($invoice, $attempt, $result);
        }

        if ($result->isAmbiguous()) {
            $this->recordAmbiguous($invoice, $attempt, $result->reason ?? InvoiceFailureReason::Unknown);

            return $invoice->fresh();
        }

        return $this->recordFailed($invoice, $attempt, $result);
    }

    /**
     * Take exclusive ownership of the one issuing attempt, atomically.
     *
     * Returns null when this worker did not win, which includes the case where
     * the invoice was never `pending` in the first place.
     */
    private function claim(Invoice $invoice): ?InvoiceAttempt
    {
        return DB::transaction(function () use ($invoice) {
            $locked = Invoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== InvoiceStatus::Pending) {
                return null;
            }

            $locked->forceFill(['status' => InvoiceStatus::Processing])->save();

            // ⛔ 固定的冪等鍵：只由發票 id 與金額推導，重送不會產生新鍵。
            return InvoiceAttempt::create([
                'invoice_id' => $locked->id,
                'idempotency_key' => $locked->initialIdempotencyKey(),
                'status' => InvoiceAttemptStatus::Started,
                // ⛔ 只存單向雜湊，不存可重播的內容。
                'request_fingerprint' => InvoiceAttempt::fingerprint([
                    'invoice_id' => $locked->id,
                    'order_id' => $locked->order_id,
                    'amount' => $locked->amount,
                    'currency' => $locked->currency,
                ]),
                'started_at' => now(),
            ]);
        });
    }

    private function recordIssued(Invoice $invoice, InvoiceAttempt $attempt, $result): Invoice
    {
        return DB::transaction(function () use ($invoice, $attempt, $result) {
            $attempt->forceFill([
                'status' => InvoiceAttemptStatus::Succeeded,
                'provider_reference' => $result->providerReference,
                'completed_at' => now(),
            ])->save();

            $invoice->forceFill([
                'status' => InvoiceStatus::Issued,
                'invoice_number' => $result->invoiceNumber,
                'random_code' => $result->randomCode,
                'provider_reference' => $result->providerReference,
                'failure_code' => null,
                'failure_message' => null,
                'issued_at' => now(),
            ])->save();

            return $invoice->fresh();
        });
    }

    private function recordFailed(Invoice $invoice, InvoiceAttempt $attempt, $result): Invoice
    {
        // ⛔ 兩個值都來自本地 allowlist，不是 provider 傳來的字串。
        $reason = $result->reason ?? InvoiceFailureReason::Unknown;

        return DB::transaction(function () use ($invoice, $attempt, $reason) {
            $attempt->forceFill([
                'status' => InvoiceAttemptStatus::Failed,
                'failure_code' => $reason->value,
                'failure_message' => $reason->message(),
                'completed_at' => now(),
            ])->save();

            $invoice->forceFill([
                'status' => InvoiceStatus::Failed,
                'failure_code' => $reason->value,
                'failure_message' => $reason->message(),
            ])->save();

            return $invoice->fresh();
        });
    }

    /**
     * Park an unknown outcome for a person to resolve.
     *
     * ⛔ Runs in its own transaction so the state is recorded even when the
     * caller is unwinding from an exception; leaving the row in `processing`
     * would make it invisible to both retry and review.
     */
    private function recordAmbiguous(
        Invoice $invoice,
        InvoiceAttempt $attempt,
        InvoiceFailureReason $reason,
    ): void {
        // ⛔ 型別就是 allowlist：這個方法收不到任意字串。
        DB::transaction(function () use ($invoice, $attempt, $reason) {
            $attempt->forceFill([
                'status' => InvoiceAttemptStatus::Ambiguous,
                'failure_code' => $reason->value,
                'failure_message' => $reason->message(),
                'completed_at' => now(),
            ])->save();

            $invoice->forceFill([
                'status' => InvoiceStatus::ReconciliationRequired,
                'failure_code' => $reason->value,
                'failure_message' => $reason->message(),
                'reconciliation_required_at' => now(),
            ])->save();
        });
    }
}
