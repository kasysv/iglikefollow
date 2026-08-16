<?php

namespace App\Enums;

/**
 * The reasons an invoice attempt can end badly, as this system understands them.
 *
 * This is a closed set of *our* reason tokens, not the provider's. An adapter
 * translates whatever it receives into one of these; anything it cannot
 * classify becomes `Unknown`.
 *
 * That indirection is the whole point. A provider's own message frequently
 * echoes the request back — merchant id, buyer email, sometimes the signing
 * key — and those messages get written to the database and rendered in the
 * admin. ⛔ Stripping braces and angle brackets from such a string removes its
 * structure, not its secrets: "MerchantID=SECRET123 buyer@example.com" survives
 * that untouched. Nor is guessing at redaction with email and key regexes a
 * defence, because the next provider will phrase it differently.
 *
 * So no provider text is stored at all. Each token below carries a message
 * written here, which cannot contain anything the provider said.
 */
enum InvoiceFailureReason: string
{
    /** 買受人資料不符合規定（統編、載具、愛心碼等）。 */
    case InvalidBuyerDetails = 'INVALID_BUYER_DETAILS';

    /** 金額或稅額不被接受。 */
    case InvalidAmount = 'INVALID_AMOUNT';

    /** 商店帳號或憑證被拒絕。 */
    case MerchantRejected = 'MERCHANT_REJECTED';

    /** 這張發票在對方系統已存在。 */
    case DuplicateInvoice = 'DUPLICATE_INVOICE';

    /** 對方暫時無法服務。 */
    case ProviderUnavailable = 'PROVIDER_UNAVAILABLE';

    /** 逾時，結果不明。 */
    case Timeout = 'TIMEOUT';

    /** 回應無法解讀，結果不明。 */
    case UnreadableResponse = 'UNREADABLE_RESPONSE';

    /** ⛔ 無法歸類的一切；adapter 遇到不認識的錯誤一律降級到這裡。 */
    case Unknown = 'UNKNOWN';

    /**
     * The message shown and stored for this reason.
     *
     * ⛔ Written here, in this file. It never contains provider text, so it
     * cannot carry a credential or a customer's details no matter what the
     * provider returned.
     */
    public function message(): string
    {
        return match ($this) {
            self::InvalidBuyerDetails => '買受人資料不符合開立規定，請確認統編或載具資訊。',
            self::InvalidAmount => '金額不符合開立規定。',
            self::MerchantRejected => '商店帳號或憑證被對方拒絕，請確認串接設定。',
            self::DuplicateInvoice => '對方系統已存在這張發票。',
            self::ProviderUnavailable => '對方系統暫時無法服務。',
            self::Timeout => '開立逾時，結果不明，需人工確認。',
            self::UnreadableResponse => '無法解讀對方回應，結果不明，需人工確認。',
            self::Unknown => '開立過程發生未知錯誤，需人工確認。',
        };
    }

    /**
     * Map an arbitrary token to a known reason, or to Unknown.
     *
     * ⛔ Never throws and never keeps the input. An adapter handing over
     * something unrecognised gets the generic reason, not a stored copy of
     * whatever it said.
     */
    public static function classify(?string $token): self
    {
        if ($token === null) {
            return self::Unknown;
        }

        return self::tryFrom(strtoupper(trim($token))) ?? self::Unknown;
    }

    /** 結果不明（可能已開立），⛔ 不得自動重送。 */
    public function isAmbiguous(): bool
    {
        return in_array($this, [self::Timeout, self::UnreadableResponse, self::Unknown], true);
    }
}
