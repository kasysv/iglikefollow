<?php

namespace App\Support;

use App\Models\ProviderService;
use App\Models\ServiceVariant;

/**
 * Can this provider service actually cover every quantity our variant sells?
 *
 * ⛔ The site's effective bounds are not the raw `min_quantity`/`max_quantity`:
 * a customer may only buy multiples of the step, so the real lower bound is
 * `firstPurchasableQuantity()` and the real upper bound is the largest step
 * multiple that fits under the maximum. A provider whose minimum sits above
 * our first purchasable quantity — or whose maximum sits below our last —
 * would take the customer's money and then have no way to dispatch.
 *
 * ⛔ Provider bounds are canonical digit strings up to 64 chars and are never
 * cast to int or float: a 64-digit maximum overflows PHP integers, and a
 * float comparison would silently accept a wrong pair. Comparison is length
 * + lexicographic, the same overflow-safe idea the catalog parser uses.
 * Malformed bounds fail closed as incompatible — without a warning and
 * without echoing the value.
 *
 * ⛔ This class states facts only: no cost conversion, no profit judgement,
 * no recommendation. Every reason is a fixed local code.
 */
final class QuantityCompatibility
{
    public const INVALID_SITE_QUANTITY_STRUCTURE = 'invalid_site_quantity_structure';

    public const NO_PURCHASABLE_QUANTITY = 'no_purchasable_quantity';

    public const MALFORMED_PROVIDER_BOUNDS = 'malformed_provider_bounds';

    public const PROVIDER_MINIMUM_TOO_HIGH = 'provider_minimum_too_high';

    public const PROVIDER_MAXIMUM_TOO_LOW = 'provider_maximum_too_low';

    /** 與 catalog 欄位一致的長度上限。 */
    private const MAX_BOUND_LENGTH = 64;

    private function __construct(
        public readonly bool $compatible,
        /** 本站實際最低可購量;null = 整段範圍沒有任何可購數量。 */
        public readonly ?int $siteFirstPurchasable,
        /** 本站實際最高可購量(≤ max 的最大 step 倍數)。 */
        public readonly ?int $siteLastPurchasable,
        /** 固定本地 reason code;⛔ 永不含 provider 值。 */
        public readonly ?string $reason,
    ) {}

    public static function assess(ServiceVariant $variant, ProviderService $service): self
    {
        $min = (int) $variant->min_quantity;
        $max = (int) $variant->max_quantity;
        $step = (int) $variant->step_quantity;

        /*
         * ⛔ R1:先驗本站 local structure,再談 provider。GPT 反例證明
         * `max(1, step)` 會把 step 0 的 corrupt 款式標成相容,而 checkout
         * 對同一筆資料 Modulo-by-zero——結構不合法(min<1、max<min、
         * step<1)直接以固定 reason 拒絕,不比較 provider、不標相容。
         */
        if ($min < 1 || $max < $min || $step < 1) {
            return new self(false, null, null, self::INVALID_SITE_QUANTITY_STRUCTURE);
        }

        $first = $variant->firstPurchasableQuantity();

        if ($first === null) {
            // ⛔ 本站自己就沒有可購數量:任何 mapping 都不得啟用。
            return new self(false, null, null, self::NO_PURCHASABLE_QUANTITY);
        }

        $last = intdiv($max, $step) * $step;

        $providerMin = (string) $service->minimum_quantity_raw;
        $providerMax = (string) $service->maximum_quantity_raw;

        if (! self::isCanonicalBound($providerMin) || ! self::isCanonicalBound($providerMax)) {
            // ⛔ fail closed:形狀不對的 bound 一律視為不相容,不猜、不修正。
            return new self(false, $first, $last, self::MALFORMED_PROVIDER_BOUNDS);
        }

        if (self::digitStringExceeds($providerMin, (string) $first)) {
            return new self(false, $first, $last, self::PROVIDER_MINIMUM_TOO_HIGH);
        }

        if (self::digitStringExceeds((string) $last, $providerMax)) {
            return new self(false, $first, $last, self::PROVIDER_MAXIMUM_TOO_LOW);
        }

        return new self(true, $first, $last, null);
    }

    /** 固定安全標籤,給 Owner 畫面與 validation message 用;⛔ 無 provider 值。 */
    public function label(): string
    {
        return match ($this->reason) {
            null => '✔ 數量相容:供應商範圍可完整承接本站可購數量。',
            self::INVALID_SITE_QUANTITY_STRUCTURE => '✘ 不相容:本站款式數量設定結構不合法(min/max/step),請先修正款式。',
            self::NO_PURCHASABLE_QUANTITY => '✘ 不相容:本站款式的數量範圍內沒有任何可購數量(min/max/step 設定問題)。',
            self::MALFORMED_PROVIDER_BOUNDS => '✘ 不相容:供應商數量欄位格式異常,無法安全比較。',
            self::PROVIDER_MINIMUM_TOO_HIGH => '✘ 不相容:供應商最低量高於本站實際最低可購量。',
            self::PROVIDER_MAXIMUM_TOO_LOW => '✘ 不相容:供應商最高量低於本站實際最高可購量。',
            default => '✘ 不相容。',
        };
    }

    private static function isCanonicalBound(string $value): bool
    {
        return strlen($value) <= self::MAX_BOUND_LENGTH
            && preg_match('/\A(0|[1-9][0-9]*)\z/', $value) === 1;
    }

    /**
     * Is $a > $b, for canonical digit strings of any length?
     *
     * ⛔ Never a cast: both are canonical (no leading zeros), so longer means
     * larger and equal length falls back to byte order.
     */
    private static function digitStringExceeds(string $a, string $b): bool
    {
        if (strlen($a) !== strlen($b)) {
            return strlen($a) > strlen($b);
        }

        return strcmp($a, $b) > 0;
    }
}
