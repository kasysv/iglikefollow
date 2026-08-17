<?php

namespace Tests\Feature\Fulfillment;

use App\Actions\Fulfillment\ApplyProviderServiceCatalogSnapshot;
use App\Exceptions\TheMostPanelCatalogParseException;
use App\Models\FulfillmentMapping;
use App\Models\ProviderService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * A snapshot applies completely or not at all, never moves backwards in time,
 * and never touches anything outside `provider_services`.
 *
 * ⛔ The action's only door is a raw JSON body — every fixture below is a
 * public-doc-derived fictional raw response. There is no way to hand it a
 * DTO, so "already validated" cannot be claimed by a caller, only earned
 * from the parser. CATALOG-A still has no caller except these tests, and
 * cross-process single-flight remains deferred to CATALOG-B — the monotonic
 * gate refuses stale sequential snapshots, it does not serialize concurrent
 * first syncs.
 */
class ProviderServiceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** @return array<string, mixed> */
    private static function item(int $service, array $overrides = []): array
    {
        return array_merge([
            'service' => $service,
            'name' => '虛構服務 '.$service,
            'type' => 'Default',
            'category' => '虛構分類',
            'rate' => '0.90',
            'min' => '10',
            'max' => '10000',
            'refill' => false,
            'cancel' => false,
        ], $overrides);
    }

    private function apply(string $rawBody, string $at = '2026-08-17 12:00:00'): void
    {
        app(ApplyProviderServiceCatalogSnapshot::class)(
            $rawBody,
            new DateTimeImmutable($at),
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function allAttributes(): array
    {
        return ProviderService::query()->orderBy('id')->get()
            ->map(fn (ProviderService $row) => $row->getAttributes())
            ->all();
    }

    public function test_a_first_snapshot_creates_available_rows_with_seen_timestamps(): void
    {
        $this->apply(json_encode([self::item(9101), self::item(9102)]));

        $this->assertSame(2, ProviderService::query()->count());

        $row = ProviderService::query()->where('provider_service_id', '9101')->sole();

        $this->assertTrue($row->is_available);
        $this->assertSame('2026-08-17 12:00:00', $row->first_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-17 12:00:00', $row->last_seen_at->format('Y-m-d H:i:s'));
    }

    public function test_a_later_snapshot_updates_but_keeps_first_seen(): void
    {
        $this->apply(json_encode([self::item(9101)]), '2026-08-17 12:00:00');

        $this->apply(
            json_encode([self::item(9101, ['name' => '改名後的虛構服務', 'rate' => '1.10'])]),
            '2026-08-18 09:00:00'
        );

        $row = ProviderService::query()->sole();

        $this->assertSame('改名後的虛構服務', $row->name);
        $this->assertSame('1.10', $row->rate_raw);
        // ⛔ first_seen_at 是觀察史，不得被後續 snapshot 改寫。
        $this->assertSame('2026-08-17 12:00:00', $row->first_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-18 09:00:00', $row->last_seen_at->format('Y-m-d H:i:s'));
    }

    public function test_an_absent_service_is_marked_unavailable_but_never_deleted(): void
    {
        $this->apply(json_encode([self::item(9101), self::item(9102)]), '2026-08-17 12:00:00');

        $this->apply(json_encode([self::item(9101)]), '2026-08-18 09:00:00');

        $this->assertSame(2, ProviderService::query()->count(), '⛔ 不得刪除');

        $gone = ProviderService::query()->where('provider_service_id', '9102')->sole();

        $this->assertFalse($gone->is_available);
        // last_seen_at 記的是最後一次真的看到，不因缺席而變。
        $this->assertSame('2026-08-17 12:00:00', $gone->last_seen_at->format('Y-m-d H:i:s'));

        $kept = ProviderService::query()->where('provider_service_id', '9101')->sole();
        $this->assertTrue($kept->is_available);
    }

    /**
     * ⛔ The reviewer's bypass, replayed through the only remaining door.
     * Each formerly-hand-built invalid DTO is now a raw JSON body, and the
     * parser refuses every one before the database is touched.
     */
    public function test_the_reviewer_bypass_cases_are_refused_at_the_only_door(): void
    {
        $this->apply(json_encode([self::item(9101)]), '2026-08-17 12:00:00');
        $before = $this->allAttributes();

        $cases = [
            'control-char name' => [self::item(9102, ['name' => "BAD\nCONTROL"])],
            'invalid rate' => [self::item(9102, ['rate' => '1..2'])],
            'inverted bounds' => [self::item(9102, ['min' => '999', 'max' => '1'])],
        ];

        foreach ($cases as $label => $items) {
            try {
                $this->apply(json_encode($items), '2026-08-18 09:00:00');
                $this->fail($label.'：必須被 parser 拒絕');
            } catch (TheMostPanelCatalogParseException) {
                // expected
            }

            // ⛔ 每個案例的 DB 變更都必須是 0。
            $this->assertSame($before, $this->allAttributes(), $label);
        }
    }

    public function test_a_parse_failure_leaves_the_database_untouched(): void
    {
        $this->apply(json_encode([self::item(9101)]), '2026-08-17 12:00:00');
        $before = $this->allAttributes();

        foreach (['[{"service": 1,', '[]', '{"error":"fictional"}'] as $rawBody) {
            try {
                $this->apply($rawBody, '2026-08-18 09:00:00');
                $this->fail('必須被 parser 拒絕');
            } catch (TheMostPanelCatalogParseException) {
                // expected
            }
        }

        $this->assertSame($before, $this->allAttributes());
    }

    /** ⛔ 先 newer 後 older：舊 snapshot 不得倒退任何東西。 */
    public function test_a_stale_snapshot_is_refused_whole(): void
    {
        $this->apply(json_encode([self::item(9101, ['name' => 'newer'])]), '2026-08-18 12:00:00');
        $before = $this->allAttributes();

        try {
            $this->apply(
                json_encode([self::item(9101, ['name' => 'older']), self::item(9102)]),
                '2026-08-17 12:00:00'
            );
            $this->fail('stale snapshot 必須被拒絕');
        } catch (RuntimeException $e) {
            $this->assertSame(ApplyProviderServiceCatalogSnapshot::STALE_SNAPSHOT_MESSAGE, $e->getMessage());
        }

        // ⛔ byte-level 不變：name／rate／capability／available／timestamps 全部原樣。
        $this->assertSame($before, $this->allAttributes());
        $this->assertSame(1, ProviderService::query()->count());
    }

    /** ⛔ 相同 timestamp、不同 body：無法排序，整份拒絕。 */
    public function test_an_equal_timestamp_snapshot_is_refused(): void
    {
        $this->apply(json_encode([self::item(9101, ['name' => 'first'])]), '2026-08-17 12:00:00');
        $before = $this->allAttributes();

        try {
            $this->apply(
                json_encode([self::item(9101, ['name' => 'second', 'rate' => '2.00'])]),
                '2026-08-17 12:00:00'
            );
            $this->fail('相同 timestamp 必須被拒絕');
        } catch (RuntimeException $e) {
            $this->assertSame(ApplyProviderServiceCatalogSnapshot::STALE_SNAPSHOT_MESSAGE, $e->getMessage());
        }

        $this->assertSame($before, $this->allAttributes());
    }

    /** 既有 row 兩個 seen timestamps 皆 null 時，首次合法觀察要一起補上。 */
    public function test_a_never_observed_row_gets_both_timestamps_on_first_snapshot(): void
    {
        ProviderService::factory()->create([
            'provider_service_id' => '9101',
            'is_available' => false,
            'first_seen_at' => null,
            'last_seen_at' => null,
        ]);

        $this->apply(json_encode([self::item(9101)]), '2026-08-17 12:00:00');

        $row = ProviderService::query()->sole();

        $this->assertTrue($row->is_available);
        $this->assertSame('2026-08-17 12:00:00', $row->first_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-17 12:00:00', $row->last_seen_at->format('Y-m-d H:i:s'));
    }

    /**
     * ⛔ The all-or-nothing promise, proven with a deterministic test-only
     * persistence failure — not a hand-built invalid DTO, which the raw JSON
     * seam no longer even accepts. A test trigger aborts the third insert;
     * the whole transaction must roll back, including the two rows already
     * written and the monotonic bookkeeping.
     */
    public function test_a_mid_snapshot_persistence_failure_keeps_the_before_state(): void
    {
        $this->apply(json_encode([self::item(9101)]), '2026-08-17 12:00:00');
        $before = $this->allAttributes();

        DB::statement("
            CREATE TRIGGER test_only_fail_9103
            BEFORE INSERT ON provider_services
            FOR EACH ROW
            WHEN NEW.provider_service_id = '9103'
            BEGIN
                SELECT RAISE(ABORT, 'test-induced deterministic persistence failure');
            END
        ");

        try {
            $this->apply(
                json_encode([
                    self::item(9101, ['name' => '不該留下的名稱']),
                    self::item(9102),
                    self::item(9103),
                ]),
                '2026-08-18 09:00:00'
            );
            $this->fail('必須失敗');
        } catch (\Throwable) {
            // expected
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS test_only_fail_9103');
        }

        $this->assertSame(1, ProviderService::query()->count());
        $this->assertSame($before, $this->allAttributes());
    }

    /** ⛔ snapshot 只碰 provider_services：mapping 一 byte 都不能動。 */
    public function test_mappings_and_their_values_are_untouched(): void
    {
        $mapping = FulfillmentMapping::factory()->enabled()->create();
        $before = $mapping->fresh()->getAttributes();

        $this->apply(json_encode([self::item(9101)]), '2026-08-17 12:00:00');
        $this->apply(json_encode([self::item(9102)]), '2026-08-18 09:00:00');

        $this->assertSame(1, FulfillmentMapping::query()->count());
        $this->assertSame($before, $mapping->fresh()->getAttributes());
        $this->assertSame(0, DB::table('fulfillment_orders')->count());
        $this->assertSame(0, DB::table('fulfillment_events')->count());
    }

    /** catalog 不得自動建立或啟用 mapping。 */
    public function test_no_mapping_is_created_from_the_catalog(): void
    {
        $this->apply(json_encode([self::item(9101)]));

        $this->assertSame(0, FulfillmentMapping::query()->count());
    }
}
