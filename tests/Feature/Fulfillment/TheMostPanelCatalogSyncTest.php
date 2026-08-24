<?php

namespace Tests\Feature\Fulfillment;

use App\Actions\Fulfillment\SyncTheMostPanelServiceCatalog;
use App\Contracts\TheMostPanelServiceCatalogSource;
use App\Data\Fulfillment\ProviderServiceCatalogSyncResult;
use App\Data\Fulfillment\TheMostPanelCatalogFetchResult;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Exceptions\TheMostPanelCatalogParseException;
use App\Models\FulfillmentMapping;
use App\Models\IntegrationSetting;
use App\Models\ProviderService;
use App\Services\Fulfillment\FulfillmentDispatchGate;
use App\Services\Fulfillment\TheMostPanelCurlCapability;
use App\Services\Fulfillment\TheMostPanelReadOnlyHttpProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * The catalog sync pipeline: gates, single-flight, one fetch, atomic apply.
 *
 * ⛔ Every response here is a public-doc-derived fictional fixture, every
 * credential a `FAKE-` marker in the memory database, and no request reaches
 * the network — `Http::preventStrayRequests()` is on for every test.
 */
class TheMostPanelCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://themostpanel.com/api/v2';

    private const KEY_MARKER = 'FAKE-API-KEY-MARKER-5566778';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config()->set('integrations.themostpanel_read_only.enabled', true);
        config()->set('integrations.themostpanel_catalog_sync.enabled', true);

        // ⛔ 本機 libcurl 7.85.0 會被 runtime 閘正確拒絕；注入支援的能力描述
        // 才能測其餘行為——不是放寬那道閘。
        $this->useCapability(TheMostPanelCurlCapability::supported());
    }

    private function useCapability(TheMostPanelCurlCapability $capability): void
    {
        $this->app->singleton(
            TheMostPanelServiceCatalogSource::class,
            fn () => new TheMostPanelReadOnlyHttpProbe($capability),
        );
    }

    private function withCredential(bool $enabled = false): IntegrationSetting
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::TheMostPanel, IntegrationEnvironment::Production)
            ->create();

        $setting->credentials = ['ApiKey' => self::KEY_MARKER];
        $setting->save();

        if ($enabled) {
            $setting->forceFill(['is_enabled' => true])->saveQuietly();
        }

        return $setting;
    }

    private function sync(): ProviderServiceCatalogSyncResult
    {
        return app(SyncTheMostPanelServiceCatalog::class)();
    }

    /** @return list<array<string, mixed>> */
    private static function fictionalServices(): array
    {
        return [
            [
                'service' => 9101,
                'name' => '虛構同步服務 A',
                'type' => 'Default',
                'category' => '虛構分類',
                'rate' => '0.90',
                'min' => '10',
                'max' => '10000',
                'refill' => true,
                'cancel' => false,
            ],
            [
                'service' => 9102,
                'name' => '虛構同步服務 B',
                'type' => 'Default',
                'category' => '虛構分類',
                'rate' => '1.25',
                'min' => '50',
                'max' => '5000',
                'refill' => false,
                'cancel' => false,
            ],
        ];
    }

    /** 計算 sync 期間 integration_settings 的 select 次數。 */
    private function countCredentialReads(callable $during): int
    {
        $reads = 0;
        $watching = true;

        DB::listen(function ($query) use (&$reads, &$watching) {
            if (
                $watching
                && str_contains($query->sql, 'integration_settings')
                && str_starts_with(trim($query->sql), 'select')
            ) {
                $reads++;
            }
        });

        $during();
        $watching = false;

        return $reads;
    }

    // ==================================== 1. 閘門順序：可提前判斷者先停

    public function test_the_catalog_flag_off_stops_before_lock_credential_and_http(): void
    {
        Http::fake();
        $this->withCredential();
        config()->set('integrations.themostpanel_catalog_sync.enabled', false);

        $reads = $this->countCredentialReads(function () {
            $result = $this->sync();

            $this->assertSame('blocked_catalog_sync_disabled', $result->outcome);
            $this->assertFalse($result->applied);
        });

        Http::assertNothingSent();
        $this->assertSame(0, $reads);
        // ⛔ 連 lock 都沒碰。
        $this->assertSame(0, DB::table('cache_locks')->count());
    }

    public function test_the_read_only_flag_off_still_blocks_the_transport(): void
    {
        Http::fake();
        $this->withCredential();
        config()->set('integrations.themostpanel_read_only.enabled', false);

        $result = $this->sync();

        $this->assertSame('blocked_disabled', $result->outcome);
        Http::assertNothingSent();
    }

    public function test_production_blocks_before_credential_and_http(): void
    {
        Http::fake();
        $this->withCredential();
        $this->app->detectEnvironment(fn () => 'production');

        $reads = $this->countCredentialReads(function () {
            $this->assertSame('blocked_production', $this->sync()->outcome);
        });

        Http::assertNothingSent();
        $this->assertSame(0, $reads);
    }

    /** ⛔ R1 之後「不支援」只剩一種：ext-curl 不存在,不再挑版本。 */
    public function test_an_unsupported_runtime_blocks_before_credential(): void
    {
        Http::fake();
        $this->withCredential();
        $this->useCapability(TheMostPanelCurlCapability::unsupported());

        $reads = $this->countCredentialReads(function () {
            $this->assertSame('blocked_unsupported_transport_cap', $this->sync()->outcome);
        });

        Http::assertNothingSent();
        $this->assertSame(0, $reads);
    }

    public function test_a_missing_app_key_blocks_before_credential(): void
    {
        Http::fake();
        $this->withCredential();
        config()->set('app.key', '');

        $reads = $this->countCredentialReads(function () {
            $this->assertSame('blocked_no_app_key', $this->sync()->outcome);
        });

        Http::assertNothingSent();
        $this->assertSame(0, $reads);
    }

    public function test_a_tampered_endpoint_sends_nothing(): void
    {
        Http::fake();
        $this->withCredential();
        config()->set('integrations.themostpanel_read_only.endpoint', 'https://evil.example.com/api/v2');

        $this->assertSame('blocked_endpoint', $this->sync()->outcome);
        Http::assertNothingSent();
    }

    public function test_a_missing_credential_sends_nothing(): void
    {
        Http::fake();

        $this->assertSame('blocked_no_credential', $this->sync()->outcome);
        Http::assertNothingSent();
    }

    /**
     * ⛔ 被異常啟用的 credential row 反而拒絕。
     *
     * `is_enabled` 武裝的是自動派單。catalog sync 在那個狀態下照跑，等於把
     * 「可以讀清單」與「已武裝派單」攪在同一個狀態裡。
     */
    public function test_an_enabled_credential_row_refuses_the_sync(): void
    {
        Http::fake();
        $this->withCredential(enabled: true);

        $result = $this->sync();

        $this->assertSame('blocked_credential_enabled', $result->outcome);
        Http::assertNothingSent();
        $this->assertSame(0, ProviderService::query()->count());
    }

    // ==================================== 2. request 精確形狀

    public function test_a_successful_sync_sends_exactly_one_minimal_request(): void
    {
        Http::fake([self::ENDPOINT => Http::response(self::fictionalServices())]);
        $this->withCredential();

        $result = $this->sync();

        $this->assertTrue($result->applied);
        $this->assertSame('catalog_applied', $result->outcome);

        // ⛔ 一次 command，一個 request，0 retry。
        Http::assertSentCount(1);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === self::ENDPOINT
                && $request->method() === 'POST'
                && $body['key'] === self::KEY_MARKER
                && $body['action'] === 'services'
                // ⛔ 就只有這兩個欄位：沒有 order、沒有其他任何東西。
                && count($body) === 2;
        });
    }

    public function test_the_source_contract_is_closed(): void
    {
        $methods = array_column(
            (new ReflectionClass(TheMostPanelServiceCatalogSource::class))->getMethods(),
            'name'
        );

        // ⛔ 只有 fetchServices()；沒有任意 action、沒有交易 method。
        $this->assertSame(['fetchServices'], $methods);
    }

    // ==================================== 3. 成功套用與後續 snapshot

    public function test_a_fictional_catalog_is_applied_atomically(): void
    {
        Http::fake([self::ENDPOINT => Http::response(self::fictionalServices())]);
        $this->withCredential();

        $this->sync();

        $this->assertSame(2, ProviderService::query()->count());

        $row = ProviderService::query()->where('provider_service_id', '9101')->sole();

        $this->assertTrue($row->is_available);
        $this->assertSame('虛構同步服務 A', $row->name);
        $this->assertSame('0.90', $row->rate_raw);
        $this->assertNotNull($row->first_seen_at);
        $this->assertNotNull($row->last_seen_at);
    }

    public function test_a_later_sync_updates_and_marks_missing_unavailable(): void
    {
        $this->withCredential();

        $second = self::fictionalServices()[0];
        $second['name'] = '虛構同步服務 A（改名）';

        // ⛔ 同一個 stub 依序回兩份不同回應：第二次只剩 9101，且改了名稱。
        Http::fakeSequence(self::ENDPOINT)
            ->push(self::fictionalServices())
            ->push([$second]);

        Date::setTestNow('2026-08-17 12:00:00');
        $this->sync();

        Date::setTestNow('2026-08-17 12:00:01');
        $result = $this->sync();

        $this->assertTrue($result->applied);

        $kept = ProviderService::query()->where('provider_service_id', '9101')->sole();
        $this->assertSame('虛構同步服務 A（改名）', $kept->name);
        $this->assertTrue($kept->is_available);

        $gone = ProviderService::query()->where('provider_service_id', '9102')->sole();
        // ⛔ 缺席只標不可用，不刪除。
        $this->assertFalse($gone->is_available);

        Date::setTestNow();
    }

    public function test_a_same_second_replay_is_refused_as_stale(): void
    {
        $this->withCredential();
        Date::setTestNow('2026-08-17 12:00:00');

        Http::fakeSequence(self::ENDPOINT)
            ->push(self::fictionalServices())
            ->push(self::fictionalServices());

        $this->sync();

        $before = ProviderService::query()->orderBy('id')->get()
            ->map(fn ($row) => $row->getAttributes())->all();

        // 同一秒的第二次 sync：CATALOG-A monotonic gate 保守拒絕。
        $result = $this->sync();

        $this->assertSame('catalog_stale_refused', $result->outcome);
        $this->assertFalse($result->applied);
        $this->assertSame(
            $before,
            ProviderService::query()->orderBy('id')->get()->map(fn ($row) => $row->getAttributes())->all()
        );

        Date::setTestNow();
    }

    // ==================================== 4. 失敗語意：before state 不變

    /** @return array<string, array{0: mixed, 1: int, 2: string}> */
    public static function unusableResponseProvider(): array
    {
        return [
            'malformed json' => ['[{"service": 1,', 200, 'catalog_rejected_by_parser'],
            'html error page' => ['<html>maintenance</html>', 200, 'catalog_rejected_by_parser'],
            'empty list' => ['[]', 200, 'catalog_rejected_by_parser'],
            'top-level object' => ['{"error":"fictional"}', 200, 'catalog_rejected_by_parser'],
            'invalid rate' => [[['service' => 9101, 'name' => 'n', 'type' => 't', 'category' => 'c', 'rate' => '1..2', 'min' => '1', 'max' => '2', 'refill' => true, 'cancel' => false]], 200, 'catalog_rejected_by_parser'],
            'inverted bounds' => [[['service' => 9101, 'name' => 'n', 'type' => 't', 'category' => 'c', 'rate' => '1', 'min' => '999', 'max' => '1', 'refill' => true, 'cancel' => false]], 200, 'catalog_rejected_by_parser'],
            'empty body' => ['', 200, 'empty_body'],
            'redirect' => ['', 302, 'redirect_refused'],
            'client error' => ['denied', 403, 'client_error'],
            'rate limited' => ['slow down', 429, 'rate_limited'],
            'server error' => ['oops', 500, 'server_error'],
        ];
    }

    #[DataProvider('unusableResponseProvider')]
    public function test_an_unusable_response_applies_nothing(mixed $body, int $status, string $expected): void
    {
        $this->withCredential();

        // ⛔ 同一個 stub 依序回：先一份合法 catalog，再回不可用的回應。
        Http::fakeSequence(self::ENDPOINT)
            ->push(self::fictionalServices())
            ->push($body, $status);

        // 先建立一份既有 catalog，證明失敗時它 byte-level 不變。
        Date::setTestNow('2026-08-17 12:00:00');
        $this->sync();
        Date::setTestNow('2026-08-17 12:00:05');

        $before = ProviderService::query()->orderBy('id')->get()
            ->map(fn ($row) => $row->getAttributes())->all();

        $result = $this->sync();

        $this->assertSame($expected, $result->outcome);
        $this->assertFalse($result->applied);
        // ⛔ 不 retry：兩次 sync 合計正好兩個 request。
        Http::assertSentCount(2);
        // ⛔ 前一份 snapshot 原封不動。
        $this->assertSame(
            $before,
            ProviderService::query()->orderBy('id')->get()->map(fn ($row) => $row->getAttributes())->all()
        );

        Date::setTestNow();
    }

    public function test_a_transport_failure_applies_nothing(): void
    {
        $this->withCredential();
        Http::fake([self::ENDPOINT => fn () => throw new ConnectionException('timeout')]);

        $result = $this->sync();

        $this->assertSame('transport_failed', $result->outcome);
        $this->assertFalse($result->applied);
        $this->assertSame(0, ProviderService::query()->count());
    }

    // ==================================== 5. database single-flight

    public function test_a_held_lock_blocks_without_invoking_the_source(): void
    {
        $this->withCredential();

        $counter = new \stdClass;
        $counter->invocations = 0;

        $this->app->singleton(
            TheMostPanelServiceCatalogSource::class,
            fn () => new class($counter) implements TheMostPanelServiceCatalogSource
            {
                public function __construct(private readonly \stdClass $counter) {}

                public function fetchServices(): TheMostPanelCatalogFetchResult
                {
                    $this->counter->invocations++;

                    return TheMostPanelCatalogFetchResult::blocked('blocked_disabled');
                }
            },
        );

        // 另一個 owner 先持有同名 lock。
        $held = Cache::store('database')->lock(SyncTheMostPanelServiceCatalog::LOCK_KEY, 900);
        $this->assertTrue($held->get());

        Http::fake();

        $reads = $this->countCredentialReads(function () {
            $result = $this->sync();

            $this->assertSame('blocked_sync_in_progress', $result->outcome);
            $this->assertFalse($result->applied);
        });

        // ⛔ source 一次都沒被叫、credential 0、HTTP 0、DB 不變。
        $this->assertSame(0, $counter->invocations);
        $this->assertSame(0, $reads);
        Http::assertNothingSent();
        $this->assertSame(0, ProviderService::query()->count());

        $held->release();
    }

    /** @return array<string, array{0: mixed, 1: int}> */
    public static function lockReleasePathProvider(): array
    {
        return [
            'success' => [null, 200],
            'parse failure' => ['[{"service": 1,', 200],
            'http failure' => ['oops', 500],
        ];
    }

    /** 每一條 exit path 都必須釋放 lock。 */
    #[DataProvider('lockReleasePathProvider')]
    public function test_the_lock_is_released_on_every_exit_path(mixed $body, int $status): void
    {
        $this->withCredential();
        Http::fake([self::ENDPOINT => Http::response($body ?? self::fictionalServices(), $status)]);

        $this->sync();

        // ⛔ lock residue 必須為 0：released row 會被刪除。
        $this->assertSame(0, DB::table('cache_locks')->count());
    }

    public function test_a_transport_failure_also_releases_the_lock(): void
    {
        $this->withCredential();
        Http::fake([self::ENDPOINT => fn () => throw new ConnectionException('timeout')]);

        $this->sync();

        $this->assertSame(0, DB::table('cache_locks')->count());
    }

    public function test_an_expired_lock_is_safely_reacquired(): void
    {
        $this->withCredential();

        // 模擬 crash：lock row 還在，但已過期。
        $stale = Cache::store('database')->lock(SyncTheMostPanelServiceCatalog::LOCK_KEY, 900);
        $this->assertTrue($stale->get());
        DB::table('cache_locks')->update(['expiration' => time() - 60]);

        Http::fake([self::ENDPOINT => Http::response(self::fictionalServices())]);

        $result = $this->sync();

        // ⛔ TTL 過期後可安全重新取得，不需要人工清 lock。
        $this->assertTrue($result->applied);
        $this->assertSame(2, ProviderService::query()->count());
    }

    public function test_an_unavailable_lock_backend_fails_closed_before_credential(): void
    {
        $this->withCredential();
        Http::fake();

        Schema::drop('cache_locks');

        $reads = $this->countCredentialReads(function () {
            $result = $this->sync();

            $this->assertSame('blocked_lock_unavailable', $result->outcome);
            $this->assertFalse($result->applied);
        });

        Http::assertNothingSent();
        $this->assertSame(0, $reads);
    }

    // ==================================== 6. raw body one-shot 與 redaction

    public function test_the_fetch_result_body_can_only_be_consumed_once(): void
    {
        $result = TheMostPanelCatalogFetchResult::fetched('[{"fictional":true}]', 200, 12);

        $this->assertSame('[{"fictional":true}]', $result->consumeBody());

        $this->expectException(\RuntimeException::class);

        $result->consumeBody();
    }

    public function test_a_blocked_fetch_result_has_no_body_to_consume(): void
    {
        $this->expectException(\RuntimeException::class);

        TheMostPanelCatalogFetchResult::blocked('blocked_disabled')->consumeBody();
    }

    public function test_the_fetch_result_never_shows_the_body_or_key(): void
    {
        $marker = 'RAW-BODY-MARKER-'.self::KEY_MARKER;
        $result = TheMostPanelCatalogFetchResult::fetched('[{"x":"'.$marker.'"}]', 200, 12);

        // ⛔ 各種輸出管道都不得出現 body 或 key。
        $this->assertStringNotContainsString($marker, json_encode($result));
        $this->assertStringNotContainsString($marker, print_r($result, true));

        ob_start();
        var_dump($result);
        $dumped = (string) ob_get_clean();
        $this->assertStringNotContainsString($marker, $dumped);
        $this->assertStringContainsString('redacted', $dumped);
    }

    public function test_the_fetch_result_refuses_serialization(): void
    {
        $result = TheMostPanelCatalogFetchResult::fetched('[{"fictional":true}]', 200, 12);

        $this->expectException(\RuntimeException::class);

        serialize($result);
    }

    public function test_the_sync_result_carries_no_catalog_values(): void
    {
        Http::fake([self::ENDPOINT => Http::response(self::fictionalServices())]);
        $this->withCredential();

        $result = $this->sync();

        $encoded = json_encode($result->toArray(), JSON_UNESCAPED_UNICODE)
            .print_r($result, true);

        // ⛔ 安全結果只有 outcome／applied／status／elapsed。
        $this->assertStringNotContainsString('虛構同步服務', $encoded);
        $this->assertStringNotContainsString('0.90', $encoded);
        $this->assertStringNotContainsString('9101', $encoded);
        $this->assertStringNotContainsString(self::KEY_MARKER, $encoded);
    }

    // ==================================== 7. 不觸碰任何既有系統

    public function test_the_sync_touches_nothing_outside_the_catalog(): void
    {
        $mapping = FulfillmentMapping::factory()->enabled()->create();
        $mappingBefore = $mapping->fresh()->getAttributes();

        $setting = $this->withCredential();
        $settingBefore = DB::table('integration_settings')->where('id', $setting->id)->first();

        Http::fake([self::ENDPOINT => Http::response(self::fictionalServices())]);

        $this->sync();

        $this->assertSame($mappingBefore, $mapping->fresh()->getAttributes());
        $this->assertEquals(
            $settingBefore,
            DB::table('integration_settings')->where('id', $setting->id)->first()
        );
        $this->assertSame(0, DB::table('fulfillment_orders')->count());
        $this->assertSame(0, DB::table('fulfillment_events')->count());
        // ⛔ 讀 catalog 不會武裝派單:R1 之後由 Owner 的總開關決定,而
        // 同步 catalog 這個動作沒有、也不得改動那個開關。
        $this->assertFalse(FulfillmentDispatchGate::enabled());
    }

    public function test_no_mapping_is_created_from_a_synced_catalog(): void
    {
        Http::fake([self::ENDPOINT => Http::response(self::fictionalServices())]);
        $this->withCredential();

        $this->sync();

        $this->assertSame(0, FulfillmentMapping::query()->count());
    }

    // ==================================== 8. factory debt

    public function test_the_available_factory_state_passes_the_temporal_guards(): void
    {
        // ⛔ DB temporal guard 會拒絕 available-without-observation；
        // create() 能成功本身就是證明。
        $row = ProviderService::factory()->available()->create();

        $this->assertTrue($row->is_available);
        $this->assertNotNull($row->first_seen_at);
        $this->assertSame(
            $row->first_seen_at->format('Y-m-d H:i:s'),
            $row->last_seen_at->format('Y-m-d H:i:s'),
        );
    }

    // ==================================== 9. R1：outcome 封閉 allowlist

    /** @return array<string, array{0: string}> */
    public static function arbitraryOutcomeProvider(): array
    {
        return [
            'provider text' => ['Invalid API key '.self::KEY_MARKER.' for account 55123'],
            'key marker' => [self::KEY_MARKER],
            'newline and ansi' => ["line1\nline2\033[31mred"],
            'overlong' => [str_repeat('z', 5000).self::KEY_MARKER],
            'empty' => [''],
        ];
    }

    /** ⛔ 兩個 public factory 都不得保存任意字串；未知值降級、不 throw、不回顯。 */
    #[DataProvider('arbitraryOutcomeProvider')]
    public function test_the_fetch_result_factories_refuse_arbitrary_strings(string $arbitrary): void
    {
        foreach ([
            TheMostPanelCatalogFetchResult::blocked($arbitrary),
            TheMostPanelCatalogFetchResult::failed($arbitrary, 200, 12),
        ] as $result) {
            $this->assertSame(TheMostPanelCatalogFetchResult::UNCLASSIFIED, $result->outcome);

            ob_start();
            var_dump($result);
            $dump = json_encode($result).print_r($result, true).(string) ob_get_clean();

            $this->assertStringNotContainsString(self::KEY_MARKER, $dump);
            $this->assertStringNotContainsString('55123', $dump);
        }
    }

    #[DataProvider('arbitraryOutcomeProvider')]
    public function test_the_sync_result_refuses_arbitrary_outcomes(string $arbitrary): void
    {
        $result = ProviderServiceCatalogSyncResult::refused($arbitrary, 200, 12);

        // ⛔ 最後一道 closed allowlist：未知 outcome 一律固定 catalog_source_failed。
        $this->assertSame(ProviderServiceCatalogSyncResult::SOURCE_FAILED, $result->outcome);

        $dump = json_encode($result->toArray()).print_r($result, true);
        $this->assertStringNotContainsString(self::KEY_MARKER, $dump);
    }

    public function test_every_legitimate_code_still_passes_the_allowlists(): void
    {
        foreach (TheMostPanelCatalogFetchResult::REFUSAL_CODES as $code) {
            $this->assertSame($code, TheMostPanelCatalogFetchResult::failed($code)->outcome);
            // fetch 的合法 code 轉入 sync result 也必須原樣保留。
            $this->assertSame($code, ProviderServiceCatalogSyncResult::refused($code)->outcome);
        }

        $this->assertSame(
            'catalog_stale_refused',
            ProviderServiceCatalogSyncResult::refused('catalog_stale_refused')->outcome
        );
    }

    /** 壞掉／被替換的 source 回任意 outcome：end-to-end 只看得到固定碼。 */
    public function test_an_arbitrary_source_outcome_is_degraded_end_to_end(): void
    {
        $this->app->singleton(
            TheMostPanelServiceCatalogSource::class,
            fn () => new class implements TheMostPanelServiceCatalogSource
            {
                public function fetchServices(): TheMostPanelCatalogFetchResult
                {
                    // factory 本身就會降級——這正是要證明的第一層。
                    return TheMostPanelCatalogFetchResult::blocked('PROVIDER-RAW-SECRET-MARKER');
                }
            },
        );

        $result = $this->sync();

        $this->assertSame(TheMostPanelCatalogFetchResult::UNCLASSIFIED, $result->outcome);
        $this->assertStringNotContainsString(
            'PROVIDER-RAW-SECRET-MARKER',
            json_encode($result->toArray()).print_r($result, true)
        );
    }

    // ==================================== 10. R1：credential／source 例外 fail closed

    public function test_an_unreadable_ciphertext_fails_closed_before_http(): void
    {
        Http::fake();

        $setting = $this->withCredential();
        // ⛔ raw DB 竄改成無效 ciphertext：decrypt 會丟 DecryptException。
        DB::table('integration_settings')
            ->where('id', $setting->id)
            ->update(['credentials' => 'not-a-valid-ciphertext']);

        $result = $this->sync();

        $this->assertSame('blocked_credential_unreadable', $result->outcome);
        $this->assertFalse($result->applied);
        Http::assertNothingSent();
        $this->assertSame(0, ProviderService::query()->count());
        // lock 照常 owner-safe release。
        $this->assertSame(0, DB::table('cache_locks')->count());
    }

    /**
     * ⛔ 異常啟用的 row 在**解密之前**就被拒絕。
     *
     * 同一列同時是 enabled 又是壞 ciphertext 時，回的必須是
     * `blocked_credential_enabled`——證明 is_enabled 檢查先於 decrypt。
     */
    public function test_an_enabled_row_is_refused_before_any_decrypt(): void
    {
        Http::fake();

        $setting = $this->withCredential(enabled: true);
        DB::table('integration_settings')
            ->where('id', $setting->id)
            ->update(['credentials' => 'not-a-valid-ciphertext']);

        $this->assertSame('blocked_credential_enabled', $this->sync()->outcome);
        Http::assertNothingSent();
    }

    public function test_a_throwing_source_fails_closed_with_the_lock_released(): void
    {
        $this->withCredential();

        $this->app->singleton(
            TheMostPanelServiceCatalogSource::class,
            fn () => new class implements TheMostPanelServiceCatalogSource
            {
                public function fetchServices(): TheMostPanelCatalogFetchResult
                {
                    // ⛔ 違反 never-throws contract 的 source。
                    throw new \RuntimeException('SOURCE-EXCEPTION-MARKER-314159');
                }
            },
        );

        Http::fake();

        $result = $this->sync();

        $this->assertSame(ProviderServiceCatalogSyncResult::SOURCE_FAILED, $result->outcome);
        $this->assertFalse($result->applied);
        // ⛔ exception class／message 不外流。
        $this->assertStringNotContainsString(
            'SOURCE-EXCEPTION-MARKER-314159',
            json_encode($result->toArray()).print_r($result, true)
        );
        Http::assertNothingSent();
        $this->assertSame(0, ProviderService::query()->count());
        // lock 在 finally 照常釋放。
        $this->assertSame(0, DB::table('cache_locks')->count());
    }

    // ==================================== 11. R1：拒收 credential echo

    /** @return array<string, array{0: string}> */
    public static function credentialEchoProvider(): array
    {
        $item = fn (array $overrides = []) => array_merge([
            'service' => 9101,
            'name' => '虛構回顯服務',
            'type' => 'Default',
            'category' => '虛構分類',
            'rate' => '0.90',
            'min' => '10',
            'max' => '10000',
            'refill' => false,
            'cancel' => false,
        ], $overrides);

        // ⛔ key 逐字元轉成 \uXXXX：raw scan 看不到，decode 後才會現形。
        $escapedKey = implode('', array_map(
            fn (string $char) => sprintf('\\u%04x', ord($char)),
            str_split(self::KEY_MARKER)
        ));

        return [
            'key in name' => [json_encode([$item(['name' => 'X '.self::KEY_MARKER])])],
            'key in category' => [json_encode([$item(['category' => self::KEY_MARKER])])],
            'key in ignored extra field' => [json_encode([$item(['debug_echo' => self::KEY_MARKER])])],
            'key as json property name' => [json_encode([$item([self::KEY_MARKER => 'x'])])],
            'unicode-escaped key in name' => [
                '[{"service":9101,"name":"'.$escapedKey.'","type":"Default","category":"c",'
                .'"rate":"0.90","min":"10","max":"10000","refill":false,"cancel":false}]',
            ],
        ];
    }

    /**
     * ⛔ P0：provider 把我們的 key 放進合法欄位時，整份拒收。
     *
     * CATALOG-A parser 對 name／category 只驗型別、長度與控制字元——沒有這
     * 一層，echo 回來的 key 會被原樣保存進 `provider_services`。
     */
    #[DataProvider('credentialEchoProvider')]
    public function test_a_credential_echo_is_refused_whole(string $rawBody): void
    {
        Http::fake([self::ENDPOINT => Http::response($rawBody, 200, ['Content-Type' => 'application/json'])]);
        $this->withCredential();

        $result = $this->sync();

        $this->assertSame('credential_echo_refused', $result->outcome);
        $this->assertFalse($result->applied);
        // ⛔ 最多一個 request、目錄 0 筆、result 無 marker。
        Http::assertSentCount(1);
        $this->assertSame(0, ProviderService::query()->count());
        $this->assertStringNotContainsString(
            self::KEY_MARKER,
            json_encode($result->toArray()).print_r($result, true)
        );
        $this->assertSame(0, DB::table('cache_locks')->count());
    }

    /**
     * ⛔ R2：assoc decode 會把純數字 property name 轉成 integer array key，
     * 讓 Unicode-escaped 的**純數字** API Key 逃過 `is_string($name)`。
     * 這個 regression 用固定純數字 fake key `9876543210`，以逐字元 `\uXXXX`
     * 藏在合法 services body 的額外 object property name 裡。
     */
    public function test_a_unicode_escaped_numeric_key_as_property_name_is_still_refused(): void
    {
        $numericKey = '9876543210';

        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::TheMostPanel, IntegrationEnvironment::Production)
            ->create();
        $setting->credentials = ['ApiKey' => $numericKey];
        $setting->save();

        $escapedKey = implode('', array_map(
            fn (string $char) => sprintf('\\u%04x', ord($char)),
            str_split($numericKey)
        ));

        // raw scan 看不到（escape 序列不含連續十位數字），只有 decode 路徑能抓。
        $rawBody = '[{"service":9101,"name":"合法虛構名稱","type":"Default","category":"c",'
            .'"rate":"0.90","min":"10","max":"10000","refill":false,"cancel":false,'
            .'"'.$escapedKey.'":"x"}]';

        $this->assertStringNotContainsString($numericKey, $rawBody, '前提：raw body 不含明文 key');

        Http::fake([self::ENDPOINT => Http::response($rawBody, 200, ['Content-Type' => 'application/json'])]);

        $result = $this->sync();

        $this->assertSame('credential_echo_refused', $result->outcome);
        $this->assertFalse($result->applied);
        Http::assertSentCount(1);
        $this->assertSame(0, ProviderService::query()->count());
        $this->assertStringNotContainsString(
            $numericKey,
            json_encode($result->toArray()).print_r($result, true)
        );
        $this->assertSame(0, DB::table('cache_locks')->count());
    }

    public function test_a_clean_fictional_catalog_is_not_caught_by_the_echo_guard(): void
    {
        Http::fake([self::ENDPOINT => Http::response(self::fictionalServices())]);
        $this->withCredential();

        // ⛔ 這層只做 secret containment；正常虛構 catalog 必須照常成功。
        $result = $this->sync();

        $this->assertTrue($result->applied);
        $this->assertSame(2, ProviderService::query()->count());
    }

    // ==================================== 12. B4-A：parser 安全診斷

    /** @return array<string, array{0: mixed, 1: string, 2: ?string, 3: ?string}> */
    public static function parserDiagnosticProvider(): array
    {
        $item = self::fictionalServices()[0];

        $wrongRateType = $item;
        $wrongRateType['rate'] = 0.9;

        // ⛔ B4-C-C-A 後 min 接受 integer;integer 診斷覆蓋移到仍禁止 integer 的 name。
        $integerName = $item;
        $integerName['name'] = 123;

        $missingCancel = $item;
        unset($missingCancel['cancel']);

        $invertedBounds = $item;
        $invertedBounds['min'] = '999';
        $invertedBounds['max'] = '1';

        $badServiceId = $item;
        $badServiceId['service'] = '9101';

        return [
            'malformed json' => ['[{"service": 1,', 'catalog_malformed_json', null, null],
            'top-level object' => ['{"error":"fictional"}', 'catalog_top_level_not_list', null, null],
            'empty list' => ['[]', 'catalog_empty_list', null, null],
            'wrong rate type' => [[$wrongRateType], 'catalog_wrong_type', 'rate', 'float'],
            'integer name' => [[$integerName], 'catalog_wrong_type', 'name', 'integer'],
            'missing cancel' => [[$missingCancel], 'catalog_missing_field', 'cancel', null],
            'inverted bounds' => [[$invertedBounds], 'catalog_quantity_bounds_inverted', 'max', null],
            'service id as string' => [[$badServiceId], 'catalog_invalid_service_id', 'service', null],
        ];
    }

    /**
     * ⛔ B3 之後的修正：parser 拒絕不再只剩一個籠統代碼。result 帶出 parser
     * 自己的本地 allowlisted reason、文件欄位名與（B4-C-A 起、僅限
     * WRONG_TYPE）allowlisted JSON type——而且只有這三樣。
     */
    #[DataProvider('parserDiagnosticProvider')]
    public function test_a_parser_rejection_carries_the_safe_reason_and_field(
        mixed $body,
        string $expectedReason,
        ?string $expectedField,
        ?string $expectedObservedType,
    ): void {
        Http::fake([self::ENDPOINT => Http::response($body)]);
        $this->withCredential();

        $result = $this->sync();

        $this->assertSame('catalog_rejected_by_parser', $result->outcome);
        $this->assertFalse($result->applied);
        $this->assertSame($expectedReason, $result->parserReason);
        $this->assertSame($expectedField, $result->parserField);
        $this->assertSame($expectedObservedType, $result->parserObservedType);

        $encoded = json_encode($result->toArray(), JSON_UNESCAPED_UNICODE);

        // toArray 帶 reason；field／type 只在有值時出現。
        $this->assertSame($expectedReason, $result->toArray()['parser_reason']);

        if ($expectedObservedType !== null) {
            $this->assertSame($expectedObservedType, $result->toArray()['parser_observed_type']);
        } else {
            $this->assertArrayNotHasKey('parser_observed_type', $result->toArray());
        }

        // ⛔ provider 值與 key 不出現。
        $this->assertStringNotContainsString(self::KEY_MARKER, $encoded);
        $this->assertStringNotContainsString('虛構', $encoded);

        $this->assertSame(0, ProviderService::query()->count());
    }

    /** 非 parser 的 refusal 不得帶 parser 欄位。 */
    public function test_non_parser_refusals_carry_no_parser_fields(): void
    {
        Http::fake([self::ENDPOINT => Http::response('oops', 500)]);
        $this->withCredential();

        $result = $this->sync();

        $this->assertSame('server_error', $result->outcome);
        $this->assertNull($result->parserReason);
        $this->assertNull($result->parserField);
        $this->assertNull($result->parserObservedType);
        $this->assertArrayNotHasKey('parser_reason', $result->toArray());
        $this->assertArrayNotHasKey('parser_field', $result->toArray());
        $this->assertArrayNotHasKey('parser_observed_type', $result->toArray());
    }

    /**
     * ⛔ 診斷欄位的唯一入口是 typed factory。exception 的建構已被雙 allowlist
     * 鎖死(不合法 reason／field 根本建構不出來),所以任意 provider 字串
     * 沒有任何路徑可進 parser_reason／parser_field。
     */
    public function test_the_diagnostic_entrance_is_typed_and_allowlisted(): void
    {
        $exception = TheMostPanelCatalogParseException::because(
            TheMostPanelCatalogParseException::WRONG_TYPE,
            'rate',
            'float'
        );

        $result = ProviderServiceCatalogSyncResult::rejectedByParser($exception, 200, 12);

        $this->assertSame('catalog_wrong_type', $result->parserReason);
        $this->assertSame('rate', $result->parserField);
        $this->assertSame('float', $result->parserObservedType);

        // 非 WRONG_TYPE 的 exception 不帶 type,result 也不得出現該欄位。
        $nonType = ProviderServiceCatalogSyncResult::rejectedByParser(
            TheMostPanelCatalogParseException::because(
                TheMostPanelCatalogParseException::MISSING_FIELD,
                'cancel'
            ),
            200,
            12,
        );
        $this->assertNull($nonType->parserObservedType);
        $this->assertArrayNotHasKey('parser_observed_type', $nonType->toArray());

        // allowlist 之外的 reason／field 在 exception 層就 fail closed。
        $this->expectException(\InvalidArgumentException::class);
        TheMostPanelCatalogParseException::because('Invalid API key '.self::KEY_MARKER);
    }

    /** stale 拒絕維持原 code,也不帶 parser 欄位。 */
    public function test_a_stale_refusal_still_has_no_parser_fields(): void
    {
        $this->withCredential();
        Date::setTestNow('2026-08-17 12:00:00');

        Http::fakeSequence(self::ENDPOINT)
            ->push(self::fictionalServices())
            ->push(self::fictionalServices());

        $this->sync();
        $result = $this->sync();

        $this->assertSame('catalog_stale_refused', $result->outcome);
        $this->assertNull($result->parserReason);
        $this->assertNull($result->parserObservedType);
        $this->assertArrayNotHasKey('parser_reason', $result->toArray());
        $this->assertArrayNotHasKey('parser_observed_type', $result->toArray());

        Date::setTestNow();
    }

    // ==================================== 13. B4-C-C-A:quantity integer 正規化

    /**
     * ⛔ B4-C-B live shape 的完整路徑:integer `min`(與 mixed `max`)現在
     * 通過 parser 正規化,catalog 原子套用,DB 只保存 canonical digit
     * string——沒有任何欄位記得原本是 integer。
     */
    public function test_integer_quantities_apply_end_to_end_as_canonical_strings(): void
    {
        $services = self::fictionalServices();
        $services[0]['min'] = 10;      // B4-C-B 實測形狀
        $services[1]['max'] = 5000;    // mixed:string min＋integer max

        Http::fake([self::ENDPOINT => Http::response($services)]);
        $this->withCredential();

        $result = $this->sync();

        $this->assertSame('catalog_applied', $result->outcome);
        $this->assertTrue($result->applied);
        $this->assertNull($result->parserReason);
        $this->assertNull($result->parserObservedType);

        $this->assertSame(2, ProviderService::query()->count());

        $first = ProviderService::query()->where('provider_service_id', '9101')->sole();
        $this->assertSame('10', $first->minimum_quantity_raw);
        $this->assertSame('10000', $first->maximum_quantity_raw);

        $second = ProviderService::query()->where('provider_service_id', '9102')->sole();
        $this->assertSame('50', $second->minimum_quantity_raw);
        $this->assertSame('5000', $second->maximum_quantity_raw);
    }
}
