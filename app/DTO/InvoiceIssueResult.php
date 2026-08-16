<?php

namespace App\DTO;

/**
 * What a provider said when asked to issue an invoice.
 *
 * Three outcomes, and the third is the one that matters:
 *
 *  - issued: the invoice exists, and its number is known.
 *  - failed: the provider rejected it for a reason that will not change on a
 *    retry — a bad tax id, a closed account.
 *  - ambiguous: we do not know. A timeout, a dropped connection, a response
 *    that cannot be parsed. ⛔ The invoice may or may not exist on their side,
 *    so this must never be treated as a failure and retried: doing so risks
 *    issuing a second invoice for the same order, which is a tax problem the
 *    customer inherits.
 *
 * ⛔ Every field here is safe to store. There is no raw payload, no credential
 * and no buyer identity, because this object is written to the database and
 * shown in the admin.
 */
final class InvoiceIssueResult
{
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $invoiceNumber = null,
        public readonly ?string $randomCode = null,
        public readonly ?string $providerReference = null,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
    ) {}

    public static function issued(
        string $invoiceNumber,
        ?string $randomCode = null,
        ?string $providerReference = null,
    ): self {
        return new self('issued', $invoiceNumber, $randomCode, $providerReference);
    }

    public static function failed(string $code, string $message): self
    {
        return new self('failed', code: $code, message: self::sanitize($message));
    }

    public static function ambiguous(string $code, string $message): self
    {
        return new self('ambiguous', code: $code, message: self::sanitize($message));
    }

    public function isIssued(): bool
    {
        return $this->outcome === 'issued';
    }

    public function isFailed(): bool
    {
        return $this->outcome === 'failed';
    }

    public function isAmbiguous(): bool
    {
        return $this->outcome === 'ambiguous';
    }

    /**
     * Keep messages short and structure-free.
     *
     * ⛔ A provider's raw body often echoes back the request, so storing it
     * verbatim would put the buyer's details — and sometimes the credential —
     * into a column nobody treats as sensitive.
     */
    private static function sanitize(string $message): string
    {
        $message = preg_replace('/\s+/u', ' ', trim($message)) ?? '';
        $message = preg_replace('/[{}<>]/u', '', $message) ?? '';

        return mb_substr($message, 0, 200);
    }
}
