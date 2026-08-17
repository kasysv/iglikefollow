<?php

namespace Tests\Feature\Fulfillment;

use App\Models\ProviderService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The database must reject what the parser rejects, even when the parser is
 * bypassed entirely — raw inserts, seeders, future refactors.
 */
class ProviderServicePersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** 一筆完全合法的 raw row；⛔ 全部是虛構值。 */
    private static function validRow(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'themostpanel',
            'provider_service_id' => '9101',
            'name' => '虛構測試服務',
            'service_type' => 'Default',
            'category' => '虛構分類',
            'rate_raw' => '0.90',
            'minimum_quantity_raw' => '10',
            'maximum_quantity_raw' => '10000',
            'supports_refill' => 1,
            'supports_cancel' => 0,
            'is_available' => 0,
            'created_at' => '2026-08-17 00:00:00',
            'updated_at' => '2026-08-17 00:00:00',
        ], $overrides);
    }

    public function test_a_valid_row_inserts_and_reads_back(): void
    {
        DB::table('provider_services')->insert(self::validRow());

        $row = ProviderService::query()->sole();

        $this->assertSame('9101', $row->provider_service_id);
        $this->assertTrue($row->supports_refill);
        $this->assertFalse($row->is_available);
        $this->assertNull($row->first_seen_at);
    }

    #[DataProvider('illegalRawRowProvider')]
    public function test_the_database_itself_rejects_illegal_rows(array $overrides): void
    {
        $this->expectException(QueryException::class);

        // ⛔ 直接 raw insert，繞過 parser 與 model：第二層防線必須自己擋。
        DB::table('provider_services')->insert(self::validRow($overrides));
    }

    public static function illegalRawRowProvider(): array
    {
        return [
            'unlisted provider' => [['provider' => 'someotherpanel']],
            'blank provider' => [['provider' => '   ']],
            'blank service id' => [['provider_service_id' => '']],
            'space service id' => [['provider_service_id' => ' ']],
            'zero service id' => [['provider_service_id' => '0']],
            'leading-zero service id' => [['provider_service_id' => '0042']],
            'negative service id' => [['provider_service_id' => '-9']],
            'non-numeric service id' => [['provider_service_id' => 'FAKE-9101']],
            'blank name' => [['name' => '   ']],
            'blank service_type' => [['service_type' => '']],
            'blank category' => [['category' => '  ']],
            'blank rate' => [['rate_raw' => '']],
            'textual rate' => [['rate_raw' => 'free']],
            'blank min quantity' => [['minimum_quantity_raw' => '']],
            'textual max quantity' => [['maximum_quantity_raw' => 'unlimited']],
            'boolean out of range' => [['supports_refill' => 2]],
            'textual boolean' => [['supports_cancel' => 'yes']],
            'available out of range' => [['is_available' => 5]],
            // ⛔ temporal guard：seen timestamps 不得單邊、不得倒置。
            'only first_seen set' => [['first_seen_at' => '2026-08-17 12:00:00']],
            'only last_seen set' => [['last_seen_at' => '2026-08-17 12:00:00']],
            'inverted seen timestamps' => [[
                'first_seen_at' => '2026-08-18 12:00:00',
                'last_seen_at' => '2026-08-17 12:00:00',
            ]],
            // ⛔ available 是「成功觀察過」的宣稱：沒有觀察時間就不能成立。
            'available without observation' => [['is_available' => 1]],
        ];
    }

    public function test_coherent_seen_timestamps_are_accepted(): void
    {
        DB::table('provider_services')->insert(self::validRow([
            'is_available' => 1,
            'first_seen_at' => '2026-08-17 12:00:00',
            'last_seen_at' => '2026-08-17 12:00:00',
        ]));

        $row = ProviderService::query()->sole();

        $this->assertTrue($row->is_available);
        $this->assertSame('2026-08-17 12:00:00', $row->first_seen_at->format('Y-m-d H:i:s'));
    }

    public function test_an_update_cannot_invert_the_timeline_either(): void
    {
        DB::table('provider_services')->insert(self::validRow([
            'first_seen_at' => '2026-08-17 12:00:00',
            'last_seen_at' => '2026-08-18 12:00:00',
        ]));

        $this->expectException(QueryException::class);

        // ⛔ UPDATE 也要被 temporal guard 擋：把 last_seen 拉回 first_seen 之前。
        DB::table('provider_services')->update(['last_seen_at' => '2026-08-16 12:00:00']);
    }

    public function test_an_update_cannot_mark_an_unobserved_row_available(): void
    {
        DB::table('provider_services')->insert(self::validRow());

        $this->expectException(QueryException::class);

        DB::table('provider_services')->update(['is_available' => 1]);
    }

    public function test_the_update_path_is_guarded_too(): void
    {
        DB::table('provider_services')->insert(self::validRow());

        $this->expectException(QueryException::class);

        // ⛔ INSERT 合法後再 UPDATE 成非法，一樣要被 DB 擋下。
        DB::table('provider_services')->update(['provider' => 'someotherpanel']);
    }

    public function test_the_same_service_id_cannot_exist_twice_per_provider(): void
    {
        DB::table('provider_services')->insert(self::validRow());

        $this->expectException(QueryException::class);

        DB::table('provider_services')->insert(self::validRow(['name' => '另一筆虛構服務']));
    }

    public function test_both_sqlite_guard_triggers_exist(): void
    {
        $present = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->pluck('name')
            ->all();

        foreach ([
            'provider_services_values_check_insert',
            'provider_services_values_check_update',
            'provider_services_temporal_guard_insert',
            'provider_services_temporal_guard_update',
        ] as $trigger) {
            $this->assertContains($trigger, $present, $trigger);
        }
    }

    /** forward → empty rollback → re-forward 必須可以來回（兩個 migration 一起）。 */
    public function test_the_empty_table_rolls_back_and_re_migrates(): void
    {
        $this->assertSame(0, DB::table('provider_services')->count());

        Artisan::call('migrate:rollback', ['--step' => 2]);

        $this->assertFalse(Schema::hasTable('provider_services'));

        Artisan::call('migrate');

        $this->assertTrue(Schema::hasTable('provider_services'));
        $this->assertSame(0, DB::table('provider_services')->count());

        // ⛔ 重跑後四個 trigger 也必須回來，不能只有表回來。
        $this->test_both_sqlite_guard_triggers_exist();
    }

    /**
     * ⛔ 有資料時 create-table migration 的 down 必須 fail closed。
     *
     * temporal guard 的 down 只移除守衛、獲准通過；接著輪到 `400000` 時被
     * 拒絕，表與資料原封不動。之後重跑 migrate 把 temporal guard 補回來。
     */
    public function test_a_populated_table_refuses_to_roll_back(): void
    {
        ProviderService::factory()->create();

        try {
            Artisan::call('migrate:rollback', ['--step' => 2]);
            $this->fail('有資料時 rollback 必須失敗');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('無法回滾 provider_services', $e->getMessage());
        }

        // 表和資料都還在。
        $this->assertTrue(Schema::hasTable('provider_services'));
        $this->assertSame(1, DB::table('provider_services')->count());

        // 把只被移除守衛的 temporal migration 重新跑回來，恢復完整狀態。
        Artisan::call('migrate');
        $this->test_both_sqlite_guard_triggers_exist();
    }

    /**
     * temporal migration 自己的 down：只移除守衛，⛔ 表與 rows 一根汗毛不動；
     * re-forward 後守衛回來。
     */
    public function test_the_temporal_guard_rolls_back_without_touching_data(): void
    {
        ProviderService::factory()->create();

        Artisan::call('migrate:rollback', ['--step' => 1]);

        $this->assertTrue(Schema::hasTable('provider_services'));
        $this->assertSame(1, DB::table('provider_services')->count());

        $triggers = DB::table('sqlite_master')->where('type', 'trigger')->pluck('name')->all();
        $this->assertNotContains('provider_services_temporal_guard_insert', $triggers);
        $this->assertNotContains('provider_services_temporal_guard_update', $triggers);
        // 400000 的值約束仍在。
        $this->assertContains('provider_services_values_check_insert', $triggers);

        Artisan::call('migrate');

        $this->assertSame(1, DB::table('provider_services')->count());
        $this->test_both_sqlite_guard_triggers_exist();
    }

    /**
     * ⛔ temporal guard 的 up 遇到既有違規資料必須 fail closed 並回報筆數，
     * 不得自動改寫或刪除。
     */
    public function test_the_temporal_migration_refuses_to_install_over_incoherent_rows(): void
    {
        // 先移除 temporal guard，才能製造出它要拒絕的狀態。
        Artisan::call('migrate:rollback', ['--step' => 1]);

        DB::table('provider_services')->insert(self::validRow([
            'first_seen_at' => '2026-08-18 12:00:00',
            'last_seen_at' => '2026-08-17 12:00:00',
        ]));

        try {
            Artisan::call('migrate');
            $this->fail('有違規資料時 temporal migration 必須拒絕');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('1 筆時間狀態不一致', $e->getMessage());
            // ⛔ 訊息只有筆數，沒有任何資料內容。
            $this->assertStringNotContainsString('9101', $e->getMessage());
            $this->assertStringNotContainsString('2026-08-18', $e->getMessage());
        }

        // ⛔ 資料未被改寫或刪除。
        $this->assertSame(1, DB::table('provider_services')->count());

        // 清掉違規資料後 migration 才能繼續。
        DB::table('provider_services')->delete();
        Artisan::call('migrate');
        $this->test_both_sqlite_guard_triggers_exist();
    }

    /**
     * ⛔ The refusal message may say how many rows exist — never what any of
     * them contains.
     */
    public function test_the_rollback_refusal_names_no_service_data(): void
    {
        ProviderService::factory()->create([
            'provider_service_id' => '424242',
            'name' => '不可外流的虛構名稱',
        ]);

        try {
            Artisan::call('migrate:rollback', ['--step' => 1]);
            $this->fail('必須拒絕');
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString('424242', $e->getMessage());
            $this->assertStringNotContainsString('不可外流的虛構名稱', $e->getMessage());
        }
    }

    /** M4A 三表的 rollback 守衛不因新表而改變行為。 */
    public function test_m4a_tables_are_untouched_by_the_new_migration(): void
    {
        foreach (['fulfillment_events', 'fulfillment_orders', 'fulfillment_mappings'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }
}
