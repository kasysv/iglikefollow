<?php

namespace App\DTO;

use App\Enums\InvoiceFailureReason;

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
 * ⛔ There is no way to put provider text into this object.
 *
 * An earlier version accepted a free-form message and "sanitized" it by
 * stripping braces and truncating. That is not redaction — it removes
 * structure, not secrets, and "MerchantID=SECRET123 buyer@example.com" passed
 * through it unchanged and into the database. Guessing with regexes would fail
 * the same way, one provider phrasing later. So the constructor takes a reason
 * token from a closed set, and the stored message comes from our own enum. An
 * adapter that receives something it cannot classify gets the generic reason,
 * not a stored copy of what it was told.
 */
final class InvoiceIssueResult
{
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $invoiceNumber = null,
        public readonly ?string $randomCode = null,
        public readonly ?string $providerReference = null,
        public readonly ?InvoiceFailureReason $reason = null,
    ) {}

    public static function issued(
        string $invoiceNumber,
        ?string $randomCode = null,
        ?string $providerReference = null,
    ): self {
        return new self('issued', $invoiceNumber, $randomCode, $providerReference);
    }

    /**
     * A rejection that will not change if we ask again.
     *
     * ⛔ Takes a reason, not a message. An unrecognised token becomes Unknown,
     * and an unknown *failure* is not a failure we can be sure about — it is
     * routed to ambiguous rather than closed off, because "we could not
     * classify the rejection" is not evidence that nothing was issued.
     */
    public static function failed(InvoiceFailureReason|string|null $reason): self
    {
        $reason = $reason instanceof InvoiceFailureReason
            ? $reason
            : InvoiceFailureReason::classify($reason);

        if ($reason->isAmbiguous()) {
            return new self('ambiguous', reason: $reason);
        }

        return new self('failed', reason: $reason);
    }

    /** 結果不明；⛔ 呼叫端必須進人工對帳，不得自動重送。 */
    public static function ambiguous(InvoiceFailureReason|string|null $reason = null): self
    {
        $reason = $reason instanceof InvoiceFailureReason
            ? $reason
            : InvoiceFailureReason::classify($reason);

        // 明確要求 ambiguous 時就是 ambiguous，即使 reason 本身可歸類。
        return new self('ambiguous', reason: $reason);
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

    /** 落盤與顯示用的代碼；⛔ 一定是本地 allowlist 中的值。 */
    public function code(): ?string
    {
        return $this->reason?->value;
    }

    /** 落盤與顯示用的訊息；⛔ 由本地 enum 產生，不含 provider 任何字元。 */
    public function message(): ?string
    {
        return $this->reason?->message();
    }
}
