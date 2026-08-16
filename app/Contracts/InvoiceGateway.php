<?php

namespace App\Contracts;

use App\DTO\InvoiceIssueResult;
use App\Models\Invoice;

/**
 * Whatever actually issues an e-invoice.
 *
 * Production code depends on this, never on a concrete class, so the Fake used
 * in local and testing cannot accidentally become the thing that runs when
 * real invoices matter — the binding decides that, in one reviewable place.
 *
 * An implementation must never throw for a business rejection: a malformed tax
 * id is a `failed` result, not an exception. Exceptions are reserved for
 * genuinely unknown outcomes, and even those should prefer returning an
 * `ambiguous` result so the caller can record it rather than guess.
 */
interface InvoiceGateway
{
    /**
     * Ask the provider to issue this invoice.
     *
     * @param  string  $idempotencyKey  stable across retries of the same attempt
     */
    public function issue(Invoice $invoice, string $idempotencyKey): InvoiceIssueResult;
}
