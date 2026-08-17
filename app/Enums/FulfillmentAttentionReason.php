<?php

namespace App\Enums;

/**
 * Why a fulfilment row is not moving, in our own words.
 *
 * ⛔ Every value here is written by us. Provider text is never stored, not even
 * "sanitized": stripping punctuation from a message removes its structure, not
 * its secrets, and a string like "key=abc123 target=@someone" survives that
 * untouched — carrying a credential and a customer's account into the database
 * and the admin UI. An unrecognised token becomes Unknown instead.
 */
enum FulfillmentAttentionReason: string
{
    /** 這個款式還沒有對應的供應商設定。 */
    case MappingMissing = 'MAPPING_MISSING';

    /** 有設定，但被停用。 */
    case MappingDisabled = 'MAPPING_DISABLED';

    /** 派單總開關關閉；⛔ 這是預設狀態，不是錯誤。 */
    case DispatchDisabled = 'DISPATCH_DISABLED';

    /** 這個 payload 型別本輪還沒實作。 */
    case UnsupportedPayload = 'UNSUPPORTED_PAYLOAD';

    /** 對方明確拒絕。 */
    case ProviderRejected = 'PROVIDER_REJECTED';

    /** 逾時或連線失敗；⛔ 對方可能已經收到了。 */
    case Timeout = 'TIMEOUT';

    /** 回應讀不懂；⛔ 同樣不代表沒有成立。 */
    case UnreadableResponse = 'UNREADABLE_RESPONSE';

    /** ⛔ 無法歸類的一律落到這裡，不保留原文。 */
    case Unknown = 'UNKNOWN';

    public function message(): string
    {
        return match ($this) {
            self::MappingMissing => '這個款式尚未設定供應商對應，請先在履約對應中新增。',
            self::MappingDisabled => '供應商對應目前為停用狀態。',
            self::DispatchDisabled => '自動派單尚未開啟。',
            self::UnsupportedPayload => '這個對應的資料型別目前不支援。',
            self::ProviderRejected => '供應商拒絕了這筆委派。',
            self::Timeout => '送出時逾時，結果不明，請人工確認是否已成立。',
            self::UnreadableResponse => '供應商回應無法解讀，結果不明，請人工確認。',
            self::Unknown => '發生未預期狀況，請人工確認後再處理。',
        };
    }

    /**
     * ⛔ 只接受 allowlist 中的值；其餘一律 Unknown。
     *
     * 這是唯一把外部字串變成本地代碼的入口，所以它不能有「找不到就原樣保留」
     * 的分支。
     */
    public static function classify(?string $token): self
    {
        if ($token === null) {
            return self::Unknown;
        }

        return self::tryFrom(strtoupper(trim($token))) ?? self::Unknown;
    }

    /**
     * 結果不明（可能已成立），⛔ 不得自動重送。
     */
    public function isAmbiguous(): bool
    {
        return in_array($this, [self::Timeout, self::UnreadableResponse, self::Unknown], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
