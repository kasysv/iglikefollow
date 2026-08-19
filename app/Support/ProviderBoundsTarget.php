<?php

namespace App\Support;

use App\Models\ProviderService;
use App\Models\ServiceVariant;

/**
 * What would this variant's quantity bounds become if the Owner applied the
 * provider's last-observed catalog min/max as the site's defaults?
 *
 * ⛔ A computation, not a mutation: nothing here touches the database. The
 * provider's `minimum_quantity_raw`/`maximum_quantity_raw` are verbatim
 * strings from the last catalog snapshot — a default target the Owner opts
 * into, never a background sync rule.
 *
 * ⛔ Only canonical digit strings are accepted. Negative numbers, decimals,
 * leading zeros, non-digits and over-long values fail closed with a fixed
 * local reason code — the raw payload is never echoed. Values are compared
 * as digit strings (length + lexicographic, the same overflow-safe idea as
 * QuantityCompatibility) and must additionally fit the site's unsigned
 * 32-bit quantity columns before any cast to int happens.
 *
 * ⛔ The provider has no trustworthy step contract, so `step_quantity` is
 * never guessed at or changed: the target keeps the variant's existing step,
 * and if the new range contains no multiple of that step the whole target is
 * rejected instead of "fixing" the step.
 */
final class ProviderBoundsTarget
{
    public const MALFORMED_PROVIDER_BOUNDS = 'malformed_provider_bounds';

    public const PROVIDER_BOUNDS_OVERFLOW = 'provider_bounds_overflow';

    public const PROVIDER_MIN_ABOVE_MAX = 'provider_min_above_max';

    public const EMPTY_TARGET_RANGE = 'empty_target_range';

    public const INVALID_SITE_STEP = 'invalid_site_step';

    public const NO_PURCHASABLE_STEP = 'no_purchasable_step';

    /** 與 catalog 欄位一致的長度上限。 */
    private const MAX_BOUND_LENGTH = 64;

    /** `service_variants` 數量欄位是 unsigned 32-bit;超過一律 fail closed。 */
    private const SITE_COLUMN_MAX = '4294967295';

    private function __construct(
        public readonly bool $ok,
        public readonly ?int $targetMin,
        public readonly ?int $targetMax,
        public readonly ?int $targetDefault,
        /** provider min 為 0,本站目標最小值提升為 1。 */
        public readonly bool $minZeroLifted,
        /** 原 default 不符新範圍或 step,已調整為範圍內第一個合法 step 倍數。 */
        public readonly bool $defaultAdjusted,
        /** 固定本地 reason code;⛔ 永不含 provider 值。 */
        public readonly ?string $reason,
    ) {}

    public static function compute(ServiceVariant $variant, ProviderService $service): self
    {
        $providerMin = (string) $service->minimum_quantity_raw;
        $providerMax = (string) $service->maximum_quantity_raw;

        if (! self::isCanonicalBound($providerMin) || ! self::isCanonicalBound($providerMax)) {
            return self::rejected(self::MALFORMED_PROVIDER_BOUNDS);
        }

        if (self::digitStringExceeds($providerMin, self::SITE_COLUMN_MAX)
            || self::digitStringExceeds($providerMax, self::SITE_COLUMN_MAX)) {
            return self::rejected(self::PROVIDER_BOUNDS_OVERFLOW);
        }

        if (self::digitStringExceeds($providerMin, $providerMax)) {
            return self::rejected(self::PROVIDER_MIN_ABOVE_MAX);
        }

        // ≤ SITE_COLUMN_MAX 已確認,此後 int 運算在 64-bit PHP 內不會溢位。
        $minZeroLifted = $providerMin === '0';
        $targetMin = $minZeroLifted ? 1 : (int) $providerMin;
        $targetMax = (int) $providerMax;

        if ($targetMin > $targetMax) {
            // 只會發生在 provider min=max=0:提升後 1 > 0,範圍為空。
            return self::rejected(self::EMPTY_TARGET_RANGE);
        }

        $step = (int) $variant->step_quantity;

        if ($step < 1) {
            // ⛔ corrupt step 不是套用的理由,也絕不代為「修正」。
            return self::rejected(self::INVALID_SITE_STEP);
        }

        // 新範圍內第一個合法 step 倍數;不存在就整個拒絕。
        $first = intdiv($targetMin + $step - 1, $step) * $step;

        if ($first > $targetMax) {
            return self::rejected(self::NO_PURCHASABLE_STEP);
        }

        $currentDefault = (int) $variant->default_quantity;
        $defaultStillValid = $currentDefault >= $targetMin
            && $currentDefault <= $targetMax
            && $currentDefault % $step === 0;

        return new self(
            ok: true,
            targetMin: $targetMin,
            targetMax: $targetMax,
            targetDefault: $defaultStillValid ? $currentDefault : $first,
            minZeroLifted: $minZeroLifted,
            defaultAdjusted: ! $defaultStillValid,
            reason: null,
        );
    }

    /** 固定安全標籤,給 Owner 畫面與 validation message 用;⛔ 無 provider 值。 */
    public function label(): string
    {
        return match ($this->reason) {
            null => '✔ 可套用供應商上下限為本站預設。',
            self::MALFORMED_PROVIDER_BOUNDS => '✘ 無法套用:供應商數量欄位格式異常(負數、小數、前導零或非數字),不安全。',
            self::PROVIDER_BOUNDS_OVERFLOW => '✘ 無法套用:供應商數量超出本站欄位可承載範圍。',
            self::PROVIDER_MIN_ABOVE_MAX => '✘ 無法套用:供應商最低量大於最高量,目錄資料矛盾。',
            self::EMPTY_TARGET_RANGE => '✘ 無法套用:換算後的目標範圍是空的。',
            self::INVALID_SITE_STEP => '✘ 無法套用:本站款式的數量間隔設定不合法,請先修正款式。',
            self::NO_PURCHASABLE_STEP => '✘ 無法套用:新範圍內不存在任何數量間隔的合法倍數(數量間隔不會被此動作改動)。',
            default => '✘ 無法套用。',
        };
    }

    private static function rejected(string $reason): self
    {
        return new self(false, null, null, null, false, false, $reason);
    }

    private static function isCanonicalBound(string $value): bool
    {
        return strlen($value) <= self::MAX_BOUND_LENGTH
            && preg_match('/\A(0|[1-9][0-9]*)\z/', $value) === 1;
    }

    /** $a > $b?canonical digit string 比較,⛔ 永不 cast int/float。 */
    private static function digitStringExceeds(string $a, string $b): bool
    {
        if (strlen($a) !== strlen($b)) {
            return strlen($a) > strlen($b);
        }

        return strcmp($a, $b) > 0;
    }
}
