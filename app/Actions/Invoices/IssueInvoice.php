<?php

namespace App\Actions\Invoices;

use App\Contracts\InvoiceGateway;
use App\Enums\InvoiceAttemptStatus;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceAttempt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Ask the gateway to issue an invoice, once.
 *
 * The attempt row is claimed *before* the gateway is called, under a unique
 * idempotency key. If a duplicate job is already in flight the insert fails and
 * this returns without calling anyone — which is the point: the expensive,
 * irreversible thing is the provider call, so the guard has to come first.
 *
 * ⛔ Ambiguous results stop here permanently. The provider may have issued a
 * real invoice, and asking again could produce a second one for the same
 * order; a person has to check. Deterministic failures also stop, because
 * repeating a rejected request just gets rejected again.
 */
class IssueInvoice
{
    public function __construct(private readonly InvoiceGateway $gateway) {}

    public function handle(Invoice $invoice): Invoice
    {
        $invoice = $invoice->fresh();

        if (! $this->shouldAttempt($invoice)) {
            return $invoice;
        }

        $attempt = $this->claimAttempt($invoice);

        if ($attempt === null) {
            return $invoice->fresh(); // 已有同鍵嘗試在進行，⛔ 不重複呼叫。
        }

        $invoice->forceFill(['status' => InvoiceStatus::Processing])->save();

        $result = $this->gateway->issue($invoice, $attempt->idempotency_key);

        return DB::transaction(function () use ($invoice, $attempt, $result) {
            if ($result->isIssued()) {
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
            }

            if ($result->isAmbiguous()) {
                $attempt->forceFill([
                    'status' => InvoiceAttemptStatus::Ambiguous,
                    'failure_code' => $result->code,
                    'failure_message' => $result->message,
                    'completed_at' => now(),
                ])->save();

                // ⛔ 進人工對帳，不自動重送：對方可能已經開出一張真的發票。
                $invoice->forceFill([
                    'status' => InvoiceStatus::ReconciliationRequired,
                    'failure_code' => $result->code,
                    'failure_message' => $result->message,
                    'reconciliation_required_at' => now(),
                ])->save();

                return $invoice->fresh();
            }

            $attempt->forceFill([
                'status' => InvoiceAttemptStatus::Failed,
                'failure_code' => $result->code,
                'failure_message' => $result->message,
                'completed_at' => now(),
            ])->save();

            $invoice->forceFill([
                'status' => InvoiceStatus::Failed,
                'failure_code' => $result->code,
                'failure_message' => $result->message,
            ])->save();

            return $invoice->fresh();
        });
    }

    /** 只有還在等待開立的發票才需要呼叫 gateway。 */
    private function shouldAttempt(Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Pending;
    }

    /**
     * Take exclusive ownership of this issuing attempt.
     *
     * ⛔ Relies on the unique index rather than a read-then-write check: two
     * concurrent workers would both pass a check, but only one can win the
     * insert.
     */
    private function claimAttempt(Invoice $invoice): ?InvoiceAttempt
    {
        $sequence = $invoice->attempts()->count() + 1;
        $key = $invoice->idempotencyKeyFor($sequence);

        try {
            return InvoiceAttempt::create([
                'invoice_id' => $invoice->id,
                'idempotency_key' => $key,
                'status' => InvoiceAttemptStatus::Started,
                // ⛔ 只存單向雜湊，不存可重播的內容。
                'request_fingerprint' => InvoiceAttempt::fingerprint([
                    'invoice_id' => $invoice->id,
                    'order_id' => $invoice->order_id,
                    'amount' => $invoice->amount,
                    'currency' => $invoice->currency,
                ]),
                'started_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
