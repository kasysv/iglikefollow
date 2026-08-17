<?php

namespace Tests\Feature\Fulfillment;

use App\Actions\Fulfillment\ApplyProviderServiceCatalogSnapshot;
use App\Data\Fulfillment\TheMostPanelServiceDefinition;
use App\Models\FulfillmentMapping;
use App\Models\ProviderService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * A snapshot applies completely or not at all, and never touches anything
 * outside `provider_services`.
 *
 * ⛔ All DTOs below are hand-built fictional values — CATALOG-A has no caller
 * for this action except these tests, and cross-process single-flight is
 * explicitly deferred to CATALOG-B, not claimed here.
 */
class ProviderServiceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function definition(string $id, array $overrides = []): TheMostPanelServiceDefinition
    {
        return new TheMostPanelServiceDefinition(
            providerServiceId: $id,
            name: $overrides['name'] ?? '虛構服務 '.$id,
            serviceType: $overrides['serviceType'] ?? 'Default',
            category: $overrides['category'] ?? '虛構分類',
            rateRaw: $overrides['rateRaw'] ?? '0.90',
            minimumQuantityRaw: $overrides['minimumQuantityRaw'] ?? '10',
            maximumQuantityRaw: $overrides['maximumQuantityRaw'] ?? '10000',
            supportsRefill: $overrides['supportsRefill'] ?? false,
            supportsCancel: $overrides['supportsCancel'] ?? false,
        );
    }

    private function apply(array $definitions, string $at = '2026-08-17 12:00:00'): void
    {
        (new ApplyProviderServiceCatalogSnapshot)(
            $definitions,
            new DateTimeImmutable($at),
        );
    }

    public function test_a_first_snapshot_creates_available_rows_with_seen_timestamps(): void
    {
        $this->apply([$this->definition('9101'), $this->definition('9102')]);

        $this->assertSame(2, ProviderService::query()->count());

        $row = ProviderService::query()->where('provider_service_id', '9101')->sole();

        $this->assertTrue($row->is_available);
        $this->assertSame('2026-08-17 12:00:00', $row->first_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-17 12:00:00', $row->last_seen_at->format('Y-m-d H:i:s'));
    }

    public function test_a_later_snapshot_updates_but_keeps_first_seen(): void
    {
        $this->apply([$this->definition('9101')], '2026-08-17 12:00:00');

        $this->apply(
            [$this->definition('9101', ['name' => '改名後的虛構服務', 'rateRaw' => '1.10'])],
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
        $this->apply([$this->definition('9101'), $this->definition('9102')], '2026-08-17 12:00:00');

        $this->apply([$this->definition('9101')], '2026-08-18 09:00:00');

        $this->assertSame(2, ProviderService::query()->count(), '⛔ 不得刪除');

        $gone = ProviderService::query()->where('provider_service_id', '9102')->sole();

        $this->assertFalse($gone->is_available);
        // last_seen_at 記的是最後一次真的看到，不因缺席而變。
        $this->assertSame('2026-08-17 12:00:00', $gone->last_seen_at->format('Y-m-d H:i:s'));

        $kept = ProviderService::query()->where('provider_service_id', '9101')->sole();
        $this->assertTrue($kept->is_available);
    }

    /**
     * ⛔ The all-or-nothing promise. A definition the DB refuses must abort the
     * whole snapshot and leave the previous state intact — including rows the
     * same snapshot had already written.
     */
    public function test_a_mid_snapshot_failure_keeps_the_before_state(): void
    {
        $this->apply([$this->definition('9101')], '2026-08-17 12:00:00');

        $before = ProviderService::query()->sole()->getAttributes();

        try {
            $this->apply([
                $this->definition('9101', ['name' => '不該留下的名稱']),
                $this->definition('9102'),
                // ⛔ 空 name 會被 DB trigger 拒絕——在第三筆才失敗。
                $this->definition('9103', ['name' => '   ']),
            ], '2026-08-18 09:00:00');
            $this->fail('必須失敗');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(1, ProviderService::query()->count());
        $this->assertSame($before, ProviderService::query()->sole()->getAttributes());
    }

    public function test_an_empty_snapshot_is_refused(): void
    {
        $this->apply([$this->definition('9101')]);

        $this->expectException(InvalidArgumentException::class);

        // ⛔ 空 snapshot 不得把整個 catalog 標成不可用。
        $this->apply([]);
    }

    public function test_duplicate_ids_inside_one_snapshot_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->apply([$this->definition('9101'), $this->definition('9101')]);
    }

    public function test_arbitrary_arrays_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->apply([['service' => 9101, 'name' => '未經 parser 的原始資料']]);
    }

    /** ⛔ snapshot 只碰 provider_services：mapping 一 byte 都不能動。 */
    public function test_mappings_and_their_values_are_untouched(): void
    {
        $mapping = FulfillmentMapping::factory()->enabled()->create();
        $before = $mapping->fresh()->getAttributes();

        $this->apply([$this->definition('9101')]);
        $this->apply([$this->definition('9102')]);

        $this->assertSame(1, FulfillmentMapping::query()->count());
        $this->assertSame($before, $mapping->fresh()->getAttributes());
        $this->assertSame(0, DB::table('fulfillment_orders')->count());
        $this->assertSame(0, DB::table('fulfillment_events')->count());
    }

    /** catalog 不得自動建立或啟用 mapping。 */
    public function test_no_mapping_is_created_from_the_catalog(): void
    {
        $this->apply([$this->definition('9101')]);

        $this->assertSame(0, FulfillmentMapping::query()->count());
    }
}
