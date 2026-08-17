<?php

namespace Tests\Unit\Fulfillment;

use App\Exceptions\TheMostPanelCatalogParseException;
use App\Services\Fulfillment\TheMostPanelResponseSizeGuard;
use App\Services\Fulfillment\TheMostPanelServiceCatalogParser;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The parser must accept exactly the documented example shape and nothing else.
 *
 * ⛔ Every fixture below is a public-doc-derived fictional fixture: the shape
 * copies the provider's public Docs example, every value is invented. Nothing
 * here is the user's account catalog, and nothing here may be seeded anywhere.
 */
class TheMostPanelServiceCatalogParserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // ⛔ parser 是純函式，但整個 milestone 的外呼上限是 0——這裡一併鎖住。
        Http::preventStrayRequests();
    }

    private function parser(): TheMostPanelServiceCatalogParser
    {
        return new TheMostPanelServiceCatalogParser;
    }

    /**
     * One fictional docs-shape item, with optional overrides/removals.
     *
     * @return array<string, mixed>
     */
    private static function item(array $overrides = [], array $without = []): array
    {
        $item = array_merge([
            'service' => 9101,
            'name' => '虛構 Instagram 測試服務',
            'type' => 'Default',
            'category' => '虛構分類',
            'rate' => '0.90',
            'min' => '10',
            'max' => '10000',
            'refill' => true,
            'cancel' => false,
        ], $overrides);

        foreach ($without as $field) {
            unset($item[$field]);
        }

        return $item;
    }

    private function expectRejection(string $reason, string $rawBody): void
    {
        try {
            $this->parser()->parse($rawBody);
            $this->fail('必須整份拒絕，卻通過了。');
        } catch (TheMostPanelCatalogParseException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_the_documented_example_shape_parses(): void
    {
        $raw = json_encode([
            self::item(),
            self::item(['service' => 9102, 'name' => '虛構 TikTok 測試服務', 'rate' => '1.25']),
        ]);

        $definitions = $this->parser()->parse($raw);

        $this->assertCount(2, $definitions);

        $first = $definitions[0];
        $this->assertSame('9101', $first->providerServiceId);
        $this->assertSame('虛構 Instagram 測試服務', $first->name);
        $this->assertSame('Default', $first->serviceType);
        $this->assertSame('虛構分類', $first->category);
        $this->assertSame('0.90', $first->rateRaw);
        $this->assertSame('10', $first->minimumQuantityRaw);
        $this->assertSame('10000', $first->maximumQuantityRaw);
        $this->assertTrue($first->supportsRefill);
        $this->assertFalse($first->supportsCancel);
    }

    public function test_unknown_extra_fields_are_ignored_and_not_preserved(): void
    {
        $raw = json_encode([
            self::item(['dripfeed' => true, 'secret_internal_note' => 'EXTRA-FIELD-MARKER']),
        ]);

        $definitions = $this->parser()->parse($raw);

        $this->assertCount(1, $definitions);
        // ⛔ 未知欄位不得原樣保存：DTO 序列化後不含那個值。
        $this->assertStringNotContainsString(
            'EXTRA-FIELD-MARKER',
            json_encode((array) $definitions[0])
        );
    }

    public function test_an_oversized_body_is_rejected_before_decoding(): void
    {
        $raw = str_repeat('x', TheMostPanelResponseSizeGuard::MAX_BODY_BYTES + 1);

        $this->expectRejection(TheMostPanelCatalogParseException::BODY_TOO_LARGE, $raw);
    }

    public function test_invalid_utf8_is_rejected(): void
    {
        $this->expectRejection(
            TheMostPanelCatalogParseException::INVALID_UTF8,
            "[{\"service\":1,\"name\":\"\xC3\x28\"}]"
        );
    }

    #[DataProvider('malformedBodyProvider')]
    public function test_a_body_that_is_not_a_json_list_is_rejected(string $reason, string $raw): void
    {
        $this->expectRejection($reason, $raw);
    }

    public static function malformedBodyProvider(): array
    {
        return [
            'truncated json' => [TheMostPanelCatalogParseException::MALFORMED_JSON, '[{"service": 1,'],
            'html error page' => [TheMostPanelCatalogParseException::MALFORMED_JSON, '<html><body>Maintenance</body></html>'],
            'plain text' => [TheMostPanelCatalogParseException::MALFORMED_JSON, 'rate limited, slow down'],
            'top-level null' => [TheMostPanelCatalogParseException::TOP_LEVEL_NOT_LIST, 'null'],
            'top-level object' => [TheMostPanelCatalogParseException::TOP_LEVEL_NOT_LIST, '{"error": "fictional"}'],
            'top-level string' => [TheMostPanelCatalogParseException::TOP_LEVEL_NOT_LIST, '"services"'],
            'top-level number' => [TheMostPanelCatalogParseException::TOP_LEVEL_NOT_LIST, '42'],
            'empty list' => [TheMostPanelCatalogParseException::EMPTY_LIST, '[]'],
        ];
    }

    public function test_the_item_count_cap_is_enforced(): void
    {
        $items = [];

        for ($i = 1; $i <= TheMostPanelServiceCatalogParser::MAX_ITEMS + 1; $i++) {
            $items[] = self::item(['service' => $i]);
        }

        $this->expectRejection(TheMostPanelCatalogParseException::TOO_MANY_ITEMS, json_encode($items));
    }

    public function test_exactly_the_cap_is_still_accepted(): void
    {
        $items = [];

        for ($i = 1; $i <= TheMostPanelServiceCatalogParser::MAX_ITEMS; $i++) {
            $items[] = self::item(['service' => $i]);
        }

        $this->assertCount(
            TheMostPanelServiceCatalogParser::MAX_ITEMS,
            $this->parser()->parse(json_encode($items))
        );
    }

    #[DataProvider('badItemProvider')]
    public function test_a_bad_item_rejects_the_whole_response(string $reason, array $item): void
    {
        // ⛔ 一項壞就整份壞：好項在前也不得回傳 partial list。
        $this->expectRejection($reason, json_encode([self::item(), $item]));
    }

    public static function badItemProvider(): array
    {
        $e = TheMostPanelCatalogParseException::class;

        return [
            'item is a list' => [$e::ITEM_NOT_OBJECT, ['not', 'an', 'object']],
            'missing service' => [$e::MISSING_FIELD, self::item(without: ['service'])],
            'missing name' => [$e::MISSING_FIELD, self::item(['service' => 9102], ['name'])],
            'missing cancel' => [$e::MISSING_FIELD, self::item(['service' => 9102], ['cancel'])],
            'service as string' => [$e::INVALID_SERVICE_ID, self::item(['service' => '9102'])],
            'service zero' => [$e::INVALID_SERVICE_ID, self::item(['service' => 0])],
            'service negative' => [$e::INVALID_SERVICE_ID, self::item(['service' => -5])],
            'service float' => [$e::INVALID_SERVICE_ID, self::item(['service' => 91.5])],
            'service true' => [$e::INVALID_SERVICE_ID, self::item(['service' => true])],
            'duplicate id' => [$e::DUPLICATE_SERVICE_ID, self::item()],
            'name as number' => [$e::WRONG_TYPE, self::item(['service' => 9102, 'name' => 123])],
            'name blank' => [$e::BLANK_TEXT, self::item(['service' => 9102, 'name' => '   '])],
            'name with newline' => [$e::CONTROL_CHARACTERS, self::item(['service' => 9102, 'name' => "壞\n名稱"])],
            'name with nul' => [$e::CONTROL_CHARACTERS, self::item(['service' => 9102, 'name' => "壞\u{0000}名稱"])],
            'name overlong' => [$e::TEXT_TOO_LONG, self::item(['service' => 9102, 'name' => str_repeat('a', 256)])],
            'type overlong' => [$e::TEXT_TOO_LONG, self::item(['service' => 9102, 'type' => str_repeat('b', 101)])],
            'category blank' => [$e::BLANK_TEXT, self::item(['service' => 9102, 'category' => ''])],
            'rate as number' => [$e::WRONG_TYPE, self::item(['service' => 9102, 'rate' => 0.9])],
            'rate scientific' => [$e::INVALID_RATE, self::item(['service' => 9102, 'rate' => '1e3'])],
            'rate signed' => [$e::INVALID_RATE, self::item(['service' => 9102, 'rate' => '-0.5'])],
            'rate comma' => [$e::INVALID_RATE, self::item(['service' => 9102, 'rate' => '1,000'])],
            'rate nan' => [$e::INVALID_RATE, self::item(['service' => 9102, 'rate' => 'NaN'])],
            'rate infinity' => [$e::INVALID_RATE, self::item(['service' => 9102, 'rate' => 'Infinity'])],
            'rate leading zeros' => [$e::INVALID_RATE, self::item(['service' => 9102, 'rate' => '007'])],
            'rate trailing dot' => [$e::INVALID_RATE, self::item(['service' => 9102, 'rate' => '5.'])],
            'min as number' => [$e::WRONG_TYPE, self::item(['service' => 9102, 'min' => 10])],
            'min decimal' => [$e::INVALID_QUANTITY, self::item(['service' => 9102, 'min' => '1.5'])],
            'min signed' => [$e::INVALID_QUANTITY, self::item(['service' => 9102, 'min' => '+10'])],
            'max blank' => [$e::INVALID_QUANTITY, self::item(['service' => 9102, 'max' => ''])],
            'min above max' => [$e::QUANTITY_BOUNDS_INVERTED, self::item(['service' => 9102, 'min' => '500', 'max' => '100'])],
            'refill as string' => [$e::WRONG_TYPE, self::item(['service' => 9102, 'refill' => 'true'])],
            'refill as int' => [$e::WRONG_TYPE, self::item(['service' => 9102, 'refill' => 1])],
            'cancel as null' => [$e::WRONG_TYPE, self::item(['service' => 9102, 'cancel' => null])],
        ];
    }

    /**
     * ⛔ Overflow must not sneak through a cast. A service id beyond PHP's int
     * range decodes as float and is rejected — never silently rounded into a
     * different id.
     */
    public function test_an_overflowing_service_id_is_rejected(): void
    {
        $raw = '[{"service":99999999999999999999,"name":"n","type":"t","category":"c",'
            .'"rate":"1","min":"1","max":"2","refill":true,"cancel":true}]';

        $this->expectRejection(TheMostPanelCatalogParseException::INVALID_SERVICE_ID, $raw);
    }

    /** 64 位數的 min／max 不得因 int cast 溢位而誤判大小。 */
    public function test_quantity_bounds_compare_beyond_integer_range(): void
    {
        $small = str_repeat('9', 30);
        $large = '1'.str_repeat('0', 40);

        $ok = json_encode([self::item(['min' => $small, 'max' => $large])]);
        $this->assertCount(1, $this->parser()->parse($ok));

        $inverted = json_encode([self::item(['min' => $large, 'max' => $small])]);
        $this->expectRejection(TheMostPanelCatalogParseException::QUANTITY_BOUNDS_INVERTED, $inverted);
    }

    /**
     * ⛔ The exception must never carry the input. The provider's values —
     * and anything echoed inside them, like an API key — must not survive
     * into a message that reaches logs.
     */
    public function test_rejection_messages_never_contain_field_values(): void
    {
        $marker = 'FAKE-KEY-LEAK-MARKER-123';

        $bodies = [
            json_encode([self::item(['name' => $marker."\n"])]),
            json_encode([self::item(['rate' => $marker])]),
            json_encode(['error' => $marker]),
            '<html>'.$marker.'</html>',
        ];

        foreach ($bodies as $raw) {
            try {
                $this->parser()->parse($raw);
                $this->fail('必須拒絕');
            } catch (TheMostPanelCatalogParseException $e) {
                $this->assertStringNotContainsString($marker, $e->getMessage());
                $this->assertStringNotContainsString($marker, json_encode([$e->reason, $e->field]));
            }
        }
    }

    public function test_reasons_outside_the_allowlist_cannot_be_constructed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TheMostPanelCatalogParseException::because('some provider text');
    }

    public function test_field_names_outside_the_documented_nine_cannot_be_constructed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TheMostPanelCatalogParseException::because(
            TheMostPanelCatalogParseException::WRONG_TYPE,
            'sk-live-fake-value'
        );
    }
}
