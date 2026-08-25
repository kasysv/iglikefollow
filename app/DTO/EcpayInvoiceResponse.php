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
        /**
         * ⭐ 失敗發生在哪一層，以及對方給的數字碼（如果有）。
         *
         * ⛔ 這不是 provider 的訊息，是本站自己組出來的固定 token＋整數。
         * 舊版把所有失敗折成同一個 `uncertain()`，Owner 在後台只看得到
         * `UNKNOWN`，無從分辨憑證／傳輸／欄位／查詢解析問題。
         */
        public readonly ?InvoiceFailureCode $failureCode = null,
    ) {}

    public static function issued(string $invoiceNumber, string $randomNumber, string $invoiceDate): self
    {
        return new self('issued', $invoiceNumber, $randomNumber, $invoiceDate);
    }

    /** 對方明確拒絕，且確定沒有開出發票。 */
    public static function rejected(?InvoiceFailureCode $code = null): self
    {
        return new self('rejected', failureCode: $code);
    }

    /** ⛔ 結果不明：可能已經開出，不得重開。 */
    public static function uncertain(?InvoiceFailureCode $code = null): self
    {
        return new self('uncertain', failureCode: $code);
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
