<?php

namespace App\DTO;

/**
 * A decrypted ECPay invoice response, reduced to what we can act on.
 *
 * ⛔ The raw body never leaves the client. ECPay echoes the request back inside
 * the encrypted payload, so it contains the buyer's email, tax id and company
 * name; `RtnMsg` is free text from their side. None of it is stored, and the
 * caller sees only these typed fields plus a local reason.
 *
 * Every field is read for the shape it must have, never coerced. PHP turns an
 * array into the string "Array", so a `!== null` check alone would let a
 * malformed body be recorded as a real invoice number — a number that does not
 * exist at the tax authority and can never be reconciled.
 */
final class EcpayInvoiceResponse
{
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $invoiceNumber = null,
        public readonly ?string $randomNumber = null,
        public readonly ?string $invoiceDate = null,
    ) {}

    public static function issued(string $invoiceNumber, string $randomNumber, string $invoiceDate): self
    {
        return new self('issued', $invoiceNumber, $randomNumber, $invoiceDate);
    }

    /** 對方明確拒絕，且確定沒有開出發票。 */
    public static function rejected(): self
    {
        return new self('rejected');
    }

    /** ⛔ 結果不明：可能已經開出，不得重開。 */
    public static function uncertain(): self
    {
        return new self('uncertain');
    }

    public function isIssued(): bool
    {
        return $this->outcome === 'issued';
    }

    public function isRejected(): bool
    {
        return $this->outcome === 'rejected';
    }

    public function isUncertain(): bool
    {
        return $this->outcome === 'uncertain';
    }
}
