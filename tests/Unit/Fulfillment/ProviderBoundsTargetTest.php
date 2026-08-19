<?php

namespace Tests\Unit\Fulfillment;

use App\Models\ProviderService;
use App\Models\ServiceVariant;
use App\Support\ProviderBoundsTarget;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The pure computation alone: no database writes, no network.
 *
 * ⛔ Provider bounds are verbatim digit strings and are compared without a
 * cast until they are proven canonical AND inside the site's unsigned 32-bit
 * columns. Every malformed / overflow / contradictory shape must fail closed
 * with a fixed reason and no echo of the raw value.
 */
class ProviderBoundsTargetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private static function variant(int $min, int $max, int $step, int $default): ServiceVariant
    {
        return new ServiceVariant([
            'min_quantity' => $min,
            'max_quantity' => $max,
            'step_quantity' => $step,
            'default_quantity' => $default,
        ]);
    }

    private static function service(string $min, string $max): ProviderService
    {
        return new ProviderService([
            'minimum_quantity_raw' => $min,
            'maximum_quantity_raw' => $max,
        ]);
    }

    public function test_a_valid_range_keeps_a_still_valid_default(): void
    {
        $t = ProviderBoundsTarget::compute(self::variant(100, 10000, 100, 1000), self::service('50', '5000'));

        $this->assertTrue($t->ok);
        $this->assertSame(50, $t->targetMin);
        $this->assertSame(5000, $t->targetMax);
        // 1000 仍在 [50, 5000] 且是 100 的倍數:保留。
        $this->assertSame(1000, $t->targetDefault);
        $this->assertFalse($t->defaultAdjusted);
        $this->assertFalse($t->minZeroLifted);
        $this->assertNull($t->reason);
    }

    public function test_provider_minimum_zero_lifts_the_site_minimum_to_one(): void
    {
        $t = ProviderBoundsTarget::compute(self::variant(100, 10000, 100, 1000), self::service('0', '5000'));

        $this->assertTrue($t->ok);
        $this->assertSame(1, $t->targetMin);
        $this->assertSame(5000, $t->targetMax);
        $this->assertTrue($t->minZeroLifted);
        // UI 提示必須存在:label/preview 由 minZeroLifted 驅動。
        $this->assertSame(1000, $t->targetDefault);
    }

    public function test_an_out_of_range_default_moves_to_the_first_legal_step_multiple(): void
    {
        $t = ProviderBoundsTarget::compute(self::variant(100, 10000, 100, 1000), self::service('2050', '9000'));

        $this->assertTrue($t->ok);
        $this->assertSame(2050, $t->targetMin);
        $this->assertSame(9000, $t->targetMax);
        // 1000 低於新 min:調整為 ≥2050 的第一個 100 倍數 = 2100。
        $this->assertSame(2100, $t->targetDefault);
        $this->assertTrue($t->defaultAdjusted);
    }

    public function test_a_default_that_breaks_the_step_is_adjusted_even_inside_the_range(): void
    {
        $t = ProviderBoundsTarget::compute(self::variant(100, 10000, 300, 1000), self::service('500', '1500'));

        $this->assertTrue($t->ok);
        // 1000 在 [500,1500] 內但不是 300 的倍數 → 第一個合法倍數 600。
        $this->assertSame(600, $t->targetDefault);
        $this->assertTrue($t->defaultAdjusted);
    }

    public function test_equal_bounds_that_fit_the_step_are_accepted(): void
    {
        $t = ProviderBoundsTarget::compute(self::variant(100, 10000, 100, 1000), self::service('1000', '1000'));

        $this->assertTrue($t->ok);
        $this->assertSame(1000, $t->targetMin);
        $this->assertSame(1000, $t->targetMax);
        $this->assertSame(1000, $t->targetDefault);
        $this->assertFalse($t->defaultAdjusted);
    }

    public function test_the_column_maximum_itself_is_still_accepted(): void
    {
        $t = ProviderBoundsTarget::compute(self::variant(100, 10000, 1, 1000), self::service('4294967295', '4294967295'));

        $this->assertTrue($t->ok);
        $this->assertSame(4294967295, $t->targetMax);
        $this->assertSame(4294967295, $t->targetDefault);
        $this->assertTrue($t->defaultAdjusted);
    }

    public function test_a_range_with_no_legal_step_multiple_is_rejected_instead_of_touching_the_step(): void
    {
        // step 100 在 [101,199] 內沒有任何倍數;⛔ step 不得被「修正」。
        $t = ProviderBoundsTarget::compute(self::variant(100, 10000, 100, 1000), self::service('101', '199'));

        $this->assertFalse($t->ok);
        $this->assertSame(ProviderBoundsTarget::NO_PURCHASABLE_STEP, $t->reason);
        $this->assertNull($t->targetMin);
    }

    public function test_a_corrupt_site_step_fails_closed_without_guessing(): void
    {
        $t = ProviderBoundsTarget::compute(self::variant(100, 10000, 0, 1000), self::service('50', '5000'));

        $this->assertFalse($t->ok);
        $this->assertSame(ProviderBoundsTarget::INVALID_SITE_STEP, $t->reason);
    }

    public function test_provider_min_above_max_is_a_contradiction(): void
    {
        $t = ProviderBoundsTarget::compute(self::variant(100, 10000, 100, 1000), self::service('500', '100'));

        $this->assertFalse($t->ok);
        $this->assertSame(ProviderBoundsTarget::PROVIDER_MIN_ABOVE_MAX, $t->reason);
    }

    public function test_zero_zero_produces_an_empty_target_range(): void
    {
        // min 0 提升為 1 之後 1 > 0:範圍為空,拒絕。
        $t = ProviderBoundsTarget::compute(self::variant(100, 10000, 100, 1000), self::service('0', '0'));

        $this->assertFalse($t->ok);
        $this->assertSame(ProviderBoundsTarget::EMPTY_TARGET_RANGE, $t->reason);
    }

    public function test_values_beyond_the_site_column_overflow_fail_closed(): void
    {
        // canonical 但超過 unsigned 32-bit 欄位(含 64 位數上限內的大值)。
        foreach (['4294967296', '99999999999999999999', str_repeat('9', 64)] as $huge) {
            $t = ProviderBoundsTarget::compute(self::variant(100, 10000, 100, 1000), self::service('10', $huge));

            $this->assertFalse($t->ok, $huge);
            $this->assertSame(ProviderBoundsTarget::PROVIDER_BOUNDS_OVERFLOW, $t->reason, $huge);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function malformedBounds(): array
    {
        return [
            'negative min' => ['-5', '1000'],
            'decimal min' => ['1.5', '1000'],
            'leading zero min' => ['007', '1000'],
            'non-digit min' => ['abc', '1000'],
            'empty min' => ['', '1000'],
            'whitespace min' => [' 10', '1000'],
            'plus sign min' => ['+10', '1000'],
            'negative max' => ['10', '-1000'],
            'decimal max' => ['10', '10.0'],
            'leading zero max' => ['10', '01000'],
            'non-digit max' => ['10', '1e3'],
            'empty max' => ['10', ''],
            'too long (65 digits)' => ['10', str_repeat('9', 65)],
        ];
    }

    #[DataProvider('malformedBounds')]
    public function test_malformed_bounds_fail_closed(string $min, string $max): void
    {
        $t = ProviderBoundsTarget::compute(self::variant(100, 10000, 100, 1000), self::service($min, $max));

        $this->assertFalse($t->ok);
        $this->assertSame(ProviderBoundsTarget::MALFORMED_PROVIDER_BOUNDS, $t->reason);
        $this->assertNull($t->targetMin);
        $this->assertNull($t->targetMax);
        $this->assertNull($t->targetDefault);
    }

    #[DataProvider('malformedBounds')]
    public function test_rejection_labels_never_echo_the_raw_value(string $min, string $max): void
    {
        $t = ProviderBoundsTarget::compute(self::variant(100, 10000, 100, 1000), self::service($min, $max));

        $label = $t->label();

        foreach ([$min, $max] as $raw) {
            if ($raw !== '' && trim($raw) !== '') {
                $this->assertStringNotContainsString($raw, $label);
            }
        }
    }
}
