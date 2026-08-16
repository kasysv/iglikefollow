<?php

namespace App\Enums;

/**
 * Why a payment attempt ended badly, in our own vocabulary.
 *
 * A closed set of local tokens, each carrying a message written in this file.
 * The provider's own `RtnMsg` or `returnMessage` is never stored: those fields
 * routinely echo the request back, and a payment request contains the merchant
 * id, the order reference and sometimes the buyer's details.
 *
 * ⛔ This is the same rule the invoice side already follows, for the same
 * reason — stripping punctuation out of a provider string removes its
 * structure, not its secrets.
 */
enum PaymentFailureReason: string
{
    /** 卡片或帳戶被發卡／支付方拒絕。 */
    case Declined = 'DECLINED';

    /** 客人自行取消。 */
    case CanceledByUser = 'CANCELED_BY_USER';

    /** 付款逾時未完成。 */
    case Expired = 'EXPIRED';

    /** 對方回報的金額或幣別與本站訂單不符。 */
    case AmountMismatch = 'AMOUNT_MISMATCH';

    /** 簽章或來源驗證失敗。 */
    case VerificationFailed = 'VERIFICATION_FAILED';

    /** 對方系統暫時無法服務。 */
    case ProviderUnavailable = 'PROVIDER_UNAVAILABLE';

    /** 逾時，結果不明。 */
    case Timeout = 'TIMEOUT';

    /** 回應無法解讀，結果不明。 */
    case UnreadableResponse = 'UNREADABLE_RESPONSE';

    /** ⛔ 無法歸類的一切。 */
    case Unknown = 'UNKNOWN';

    public function message(): string
    {
        return match ($this) {
            self::Declined => '付款被拒絕，請改用其他付款方式或聯絡發卡銀行。',
            self::CanceledByUser => '客人在付款頁取消了付款。',
            self::Expired => '付款逾時未完成。',
            self::AmountMismatch => '對方回報的金額與訂單不符，需人工確認。',
            self::VerificationFailed => '付款結果驗證失敗，未採信。',
            self::ProviderUnavailable => '付款服務暫時無法使用。',
            self::Timeout => '付款結果逾時未確認，需人工對帳。',
            self::UnreadableResponse => '無法解讀付款回應，需人工對帳。',
            self::Unknown => '付款發生未知狀況，需人工對帳。',
        };
    }

    /** ⛔ 查不到就是 Unknown；永不保留輸入值。 */
    public static function classify(?string $token): self
    {
        if ($token === null) {
            return self::Unknown;
        }

        return self::tryFrom(strtoupper(trim($token))) ?? self::Unknown;
    }

    /**
     * 結果不明：⛔ 不得當成失敗結案，也不得盲目重送。
     *
     * 錢可能已經扣了。把它記成 failed 會讓客人被收款卻看到失敗，而重送可能
     * 造成第二次扣款——兩者都要人來看。
     */
    public function isUncertain(): bool
    {
        return in_array($this, [
            self::Timeout,
            self::UnreadableResponse,
            self::AmountMismatch,
            self::Unknown,
        ], true);
    }
}
