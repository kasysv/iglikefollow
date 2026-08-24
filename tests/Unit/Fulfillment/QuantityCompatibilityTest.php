<?php

namespace Tests\Unit\Fulfillment;

use App\Models\ProviderService;
use App\Models\ServiceVariant;
use App\Support\QuantityCompatibility;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The value object alone: pure comparisons, no database, no network.
 *
 * ⛔ Fixtures are in-memory models with fictional values. Provider bounds are
 * digit strings and must never be cast — the 64-digit cases prove it.
 */
class QuantityCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private static function variant(int $min, int $max, int $step): ServiceVariant
    {
        return new ServiceVariant([
            'min_quantity' => $min,
            'max_quantity' => $max,
            'step_quantity' => $step,
        ]);
    }

    private static function service(string $min, string $max): ProviderService
    {
        return new ProviderService([
            'minimum_quantity_raw' => $min,
            'maximum_quantity_raw' => $max,
        ]);
    }

    public function test_boundary_equal_is_compatible(): void
    {
        $a = QuantityCompatibility::assess(self::variant(100, 10000, 100), self::service('100', '10000'));

        $this->assertTrue($a->compatible);
        $this->assertNull($a->reason);
        $this->assertSame(100, $a->siteFirstPurchasable);
        $this->assertSame(10000, $a->siteLastPurchasable);
    }

    public function test_a_wider_provider_range_is_compatible(): void
    {
        $this->assertTrue(
            QuantityCompatibility::assess(self::variant(100, 10000, 100), self::service('10', '20000'))->compatible
        );
    }

    public function test_a_zero_provider_minimum_is_canonical_and_compatible(): void
    {
        $this->assertTrue(
            QuantityCompatibility::assess(self::variant(100, 10000, 100), self::service('0', '10000'))->compatible
        );
    }

    public function test_a_provider_minimum_above_the_first_purchasable_is_incompatible(): void
    {
        $a = QuantityCompatibility::assess(self::variant(100, 10000, 100), self::service('150', '20000'));

        $this->assertFalse($a->compatible);
        $this->assertSame(QuantityCompatibility::PROVIDER_MINIMUM_TOO_HIGH, $a->reason);
    }

    public function test_a_provider_maximum_below_the_last_purchasable_is_incompatible(): void
    {
        $a = QuantityCompatibility::assess(self::variant(100, 10000, 100), self::service('10', '9999'));

        $this->assertFalse($a->compatible);
        $this->assertSame(QuantityCompatibility::PROVIDER_MAXIMUM_TOO_LOW, $a->reason);
    }

    /** ⛔ 本站自己沒有任何可購數量(範圍是空的):無論供應商多寬都不得啟用。 */
    public function test_a_variant_with_no_purchasable_quantity_is_incompatible(): void
    {
        // ⛔ M3A:範圍內任何整數皆可,因此「沒有可購數量」只剩 max < min 一種。
        $a = QuantityCompatibility::assess(self::variant(199, 150, 1), self::service('1', '999999'));

        $this->assertFalse($a->compatible);
        $this->assertSame(QuantityCompatibility::INVALID_SITE_QUANTITY_STRUCTURE, $a->reason);
        $this->assertNull($a->siteFirstPurchasable);
    }

    /**
     * ⛔ M3A:有效範圍就是 raw min/max,不再向 step 倍數對齊。
     *
     * 這條測試原本主張相反的事(min 101／step 100 的實際下限是 200)。那正是
     * Owner 回報缺陷的同一個根:legacy step 會把本站可買範圍說得比實際更窄。
     */
    public function test_the_effective_bounds_are_the_raw_min_and_max(): void
    {
        // legacy step 100 仍在資料裡,但不得再影響判斷。
        $a = QuantityCompatibility::assess(self::variant(101, 9999, 100), self::service('101', '9999'));

        $this->assertTrue($a->compatible);
        $this->assertSame(101, $a->siteFirstPurchasable);
        $this->assertSame(9999, $a->siteLastPurchasable);
    }

    /** ⛔ 供應商承接不了 raw 下限／上限時仍不相容:邊界沒有因此被放寬。 */
    public function test_a_provider_that_cannot_cover_the_raw_range_is_still_incompatible(): void
    {
        // provider min 150 > 本站實際下限 101 → 不相容(舊規則會誤判為相容)。
        $low = QuantityCompatibility::assess(self::variant(101, 10000, 100), self::service('150', '10000'));
        $this->assertFalse($low->compatible);
        $this->assertSame(QuantityCompatibility::PROVIDER_MINIMUM_TOO_HIGH, $low->reason);

        // provider max 9950 < 本站實際上限 9999 → 不相容。
        $high = QuantityCompatibility::assess(self::variant(100, 9999, 100), self::service('10', '9950'));
        $this->assertFalse($high->compatible);
        $this->assertSame(QuantityCompatibility::PROVIDER_MAXIMUM_TOO_LOW, $high->reason);
    }

    /** ⛔ 64 位數 bound 不得溢位:長度＋lexicographic,永不 cast。 */
    public function test_sixty_four_digit_bounds_compare_without_overflow(): void
    {
        $huge = '1'.str_repeat('0', 63);

        $this->assertTrue(
            QuantityCompatibility::assess(self::variant(100, 10000, 100), self::service('10', $huge))->compatible
        );

        $minTooHigh = QuantityCompatibility::assess(self::variant(100, 10000, 100), self::service($huge, $huge));
        $this->assertFalse($minTooHigh->compatible);
        $this->assertSame(QuantityCompatibility::PROVIDER_MINIMUM_TOO_HIGH, $minTooHigh->reason);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function malformedBoundProvider(): array
    {
        return [
            'leading zeros min' => ['007', '10000'],
            'empty min' => ['', '10000'],
            'alpha max' => ['10', 'abc'],
            'decimal min' => ['9.5', '10000'],
            'signed min' => ['-10', '10000'],
            'overlong max' => ['10', str_repeat('9', 65)],
            'space in min' => ['1 0', '10000'],
        ];
    }

    /** ⛔ malformed bounds fail closed 為不相容:無 warning、不猜、不修正。 */
    #[DataProvider('malformedBoundProvider')]
    public function test_malformed_provider_bounds_fail_closed_without_warnings(string $min, string $max): void
    {
        set_error_handler(function (int $errno, string $errstr): bool {
            $this->fail('⛔ malformed bound 產生了 PHP error:'.$errstr);
        });

        try {
            $a = QuantityCompatibility::assess(self::variant(100, 10000, 100), self::service($min, $max));
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($a->compatible);
        $this->assertSame(QuantityCompatibility::MALFORMED_PROVIDER_BOUNDS, $a->reason);
    }

    // ==================================== R1:local structure 先行

    /**
     * ⛔ M3A:legacy step 0 不再讓一個結構正常的款式變成不相容。
     *
     * 舊規則把 step 0 當成結構錯誤(當時是對的——checkout 會 Modulo-by-zero)。
     * 現在 step 完全不參與購買規則,那個除零路徑已不存在,所以一筆 min/max
     * 正常、只是 legacy step 為 0 的舊資料**必須照常可用**;否則升級後既有商品
     * 會無故無法派單。原始的安全性質(不得產生 PHP error)仍然斷言。
     */
    public function test_a_legacy_zero_step_no_longer_blocks_a_structurally_sound_variant(): void
    {
        set_error_handler(function (int $errno, string $errstr): bool {
            $this->fail('⛔ legacy step 0 產生了 PHP error:'.$errstr);
        });

        try {
            $a = QuantityCompatibility::assess(self::variant(100, 10000, 0), self::service('10', '20000'));
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($a->compatible);
        $this->assertSame(100, $a->siteFirstPurchasable);
        $this->assertSame(10000, $a->siteLastPurchasable);
    }

    /**
     * min<1、max<min:在 provider 比較之前以固定 reason 拒絕。
     *
     * ⛔ M3A:step 已從這份清單移除——它不再是結構條件(見上一個測試)。
     */
    public function test_invalid_site_structures_are_rejected_before_provider_comparison(): void
    {
        $cases = [
            'min zero' => self::variant(0, 10000, 1),
            'negative min' => self::variant(-10, 10000, 1),
            'max below min' => self::variant(500, 100, 1),
        ];

        foreach ($cases as $label => $variant) {
            // provider 給到最寬,證明拒絕與 provider 無關。
            $a = QuantityCompatibility::assess($variant, self::service('1', '999999999999'));

            $this->assertFalse($a->compatible, $label);
            $this->assertSame(QuantityCompatibility::INVALID_SITE_QUANTITY_STRUCTURE, $a->reason, $label);
            $this->assertStringContainsString('結構不合法', $a->label(), $label);
        }
    }

    /** label 是固定本地文案:⛔ 不含任何 provider 值。 */
    public function test_labels_are_fixed_and_value_free(): void
    {
        $incompatible = QuantityCompatibility::assess(
            self::variant(100, 10000, 100),
            self::service('123456789', '987654321')
        );

        $this->assertSame(QuantityCompatibility::PROVIDER_MINIMUM_TOO_HIGH, $incompatible->reason);
        $this->assertStringNotContainsString('123456789', $incompatible->label());
        $this->assertStringNotContainsString('987654321', $incompatible->label());

        $compatible = QuantityCompatibility::assess(self::variant(100, 10000, 100), self::service('10', '20000'));
        $this->assertStringContainsString('相容', $compatible->label());
    }
}
