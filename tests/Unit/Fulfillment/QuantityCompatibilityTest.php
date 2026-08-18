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

    /** ⛔ 本站自己沒有任何可購數量:無論供應商多寬都不得啟用。 */
    public function test_a_variant_with_no_purchasable_quantity_is_incompatible(): void
    {
        $a = QuantityCompatibility::assess(self::variant(150, 199, 100), self::service('1', '999999'));

        $this->assertFalse($a->compatible);
        $this->assertSame(QuantityCompatibility::NO_PURCHASABLE_QUANTITY, $a->reason);
        $this->assertNull($a->siteFirstPurchasable);
    }

    /**
     * ⛔ 有效範圍是 step 倍數,不是 raw min/max:min 101/step 100 的實際
     * 下限是 200,max 9999 的實際上限是 9900——供應商只要覆蓋實際範圍
     * 就相容,即使覆蓋不了 raw 值。
     */
    public function test_the_effective_bounds_are_step_multiples_not_raw_min_max(): void
    {
        // raw min 101 < provider min 150,但實際下限 200 >= 150 → 相容。
        $low = QuantityCompatibility::assess(self::variant(101, 10000, 100), self::service('150', '10000'));
        $this->assertTrue($low->compatible);
        $this->assertSame(200, $low->siteFirstPurchasable);

        // raw max 9999 > provider max 9950,但實際上限 9900 <= 9950 → 相容。
        $high = QuantityCompatibility::assess(self::variant(100, 9999, 100), self::service('10', '9950'));
        $this->assertTrue($high->compatible);
        $this->assertSame(9900, $high->siteLastPurchasable);
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
     * ⛔ GPT 反例:step 0 曾被 `max(1, step)` 靜默修成 1 而標成相容,
     * checkout 對同一資料卻 Modulo-by-zero。R1 之後結構先行:不比較
     * provider、不標相容、無 warning。
     */
    public function test_the_gpt_step_zero_probe_is_structurally_incompatible_without_warnings(): void
    {
        set_error_handler(function (int $errno, string $errstr): bool {
            $this->fail('⛔ corrupt structure 產生了 PHP error:'.$errstr);
        });

        try {
            $a = QuantityCompatibility::assess(self::variant(100, 10000, 0), self::service('10', '20000'));
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($a->compatible);
        $this->assertSame(QuantityCompatibility::INVALID_SITE_QUANTITY_STRUCTURE, $a->reason);
        // ⛔ 不得回報任何「實際可購」數字:那正是原反例顯示 100–10000 的來源。
        $this->assertNull($a->siteFirstPurchasable);
        $this->assertNull($a->siteLastPurchasable);
    }

    /** min<1、max<min、step<1:全部在 provider 比較之前以固定 reason 拒絕。 */
    public function test_invalid_site_structures_are_rejected_before_provider_comparison(): void
    {
        $cases = [
            'min zero' => self::variant(0, 10000, 100),
            'negative min' => self::variant(-10, 10000, 100),
            'negative step' => self::variant(100, 10000, -5),
            'max below min' => self::variant(500, 100, 100),
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
