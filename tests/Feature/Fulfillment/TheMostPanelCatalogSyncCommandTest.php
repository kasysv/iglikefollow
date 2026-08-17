<?php

namespace Tests\Feature\Fulfillment;

use App\Contracts\TheMostPanelServiceCatalogSource;
use App\Data\Fulfillment\TheMostPanelCatalogFetchResult;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use App\Models\ProviderService;
use App\Services\Fulfillment\TheMostPanelCurlCapability;
use App\Services\Fulfillment\TheMostPanelReadOnlyHttpProbe;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The CLI: the only door, a door that demands explicit acknowledgement,
 * and — since B4-A — a door that will not open without a fresh, durably
 * recorded execution ID.
 *
 * ⛔ Fixtures are fictional, credentials are `FAKE-` markers, no request
 * reaches the network, and the ledger writes into an isolated temp storage
 * root — never the real storage tree.
 */
class TheMostPanelCatalogSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://themostpanel.com/api/v2';

    private const KEY_MARKER = 'FAKE-API-KEY-MARKER-5566778';

    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config()->set('integrations.themostpanel_read_only.enabled', true);
        config()->set('integrations.themostpanel_catalog_sync.enabled', true);

        /*
         * ⛔ 隔離 temp storage root:milestone 測試不得在正式 storage 留
         * ledger 檔。command 內的 storage_path() 會解析到這裡。
         */
        $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'b4a-storage-'.uniqid();
        File::ensureDirectoryExists($this->storageRoot);
        $this->app->useStoragePath($this->storageRoot);

        $this->app->singleton(
            TheMostPanelServiceCatalogSource::class,
            fn () => new TheMostPanelReadOnlyHttpProbe(TheMostPanelCurlCapability::supported()),
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageRoot);

        parent::tearDown();
    }

    private function ledgerPath(string $executionId): string
    {
        return $this->storageRoot
            .DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'private'
            .DIRECTORY_SEPARATOR.'themostpanel'.DIRECTORY_SEPARATOR.'catalog-sync-attempts'
            .DIRECTORY_SEPARATOR.$executionId.'.ndjson';
    }

    private function withCredential(): IntegrationSetting
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::TheMostPanel, IntegrationEnvironment::Production)
            ->create();

        $setting->credentials = ['ApiKey' => self::KEY_MARKER];
        $setting->save();

        return $setting;
    }

    /** @return list<array<string, mixed>> */
    private static function fictionalServices(): array
    {
        return [[
            'service' => 9101,
            'name' => '虛構命令列服務',
            'type' => 'Default',
            'category' => '虛構分類',
            'rate' => '0.90',
            'min' => '10',
            'max' => '10000',
            'refill' => false,
            'cancel' => false,
        ]];
    }

    /** 計算期間 integration_settings select 次數的旗標式 listener。 */
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

    // ==================================== 1. acknowledgement 與 execution ID 閘

    public function test_the_missing_acknowledgement_makes_zero_calls(): void
    {
        Http::fake();
        $this->withCredential();

        $reads = $this->countCredentialReads(function () {
            $this->artisan('themostpanel:catalog-sync', ['--execution-id' => 'B4A-CMD-0001'])
                ->expectsOutputToContain('--approved-once')
                ->assertFailed();
        });

        // ⛔ 缺 acknowledgement:credential 0、HTTP 0、目錄 0、ledger 0。
        $this->assertSame(0, $reads);
        Http::assertNothingSent();
        $this->assertSame(0, ProviderService::query()->count());
        $this->assertFileDoesNotExist($this->ledgerPath('B4A-CMD-0001'));
    }

    public function test_a_missing_execution_id_makes_zero_calls(): void
    {
        Http::fake();
        $this->withCredential();

        $reads = $this->countCredentialReads(function () {
            $this->artisan('themostpanel:catalog-sync', ['--approved-once' => true])
                ->expectsOutputToContain('--execution-id')
                ->assertFailed();
        });

        $this->assertSame(0, $reads);
        Http::assertNothingSent();
        $this->assertSame(0, ProviderService::query()->count());
    }

    public function test_an_invalid_execution_id_makes_zero_calls(): void
    {
        Http::fake();
        $this->withCredential();

        foreach (['short', 'lower-case-id', '../../EVIL-ID', str_repeat('A', 65)] as $bad) {
            $this->artisan('themostpanel:catalog-sync', [
                '--approved-once' => true,
                '--execution-id' => $bad,
            ])->assertFailed();
        }

        Http::assertNothingSent();
        $this->assertSame(0, ProviderService::query()->count());
    }

    /** ⛔ 同 ID 第二次執行:source 一次都不得被叫。 */
    public function test_a_duplicate_execution_id_never_reaches_the_source(): void
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

        Http::fake();

        $this->artisan('themostpanel:catalog-sync', [
            '--approved-once' => true, '--execution-id' => 'B4A-CMD-0002',
        ])->assertFailed();

        $this->assertSame(1, $counter->invocations);

        $reads = $this->countCredentialReads(function () {
            $this->artisan('themostpanel:catalog-sync', [
                '--approved-once' => true, '--execution-id' => 'B4A-CMD-0002',
            ])->expectsOutputToContain('exclusive-create')->assertFailed();
        });

        // ⛔ 第二次:source 仍是 1、credential 0、HTTP 0。
        $this->assertSame(1, $counter->invocations);
        $this->assertSame(0, $reads);
        Http::assertNothingSent();
    }

    // ==================================== 2. marker 先於 source

    /** ⛔ source callback 執行的當下,initial marker 必須已經在磁碟上。 */
    public function test_the_initial_marker_exists_before_the_source_runs(): void
    {
        $this->withCredential();

        $seen = new \stdClass;
        $seen->markerExisted = null;
        $seen->markerState = null;
        $path = $this->ledgerPath('B4A-CMD-0003');

        $this->app->singleton(
            TheMostPanelServiceCatalogSource::class,
            fn () => new class($seen, $path) implements TheMostPanelServiceCatalogSource
            {
                public function __construct(
                    private readonly \stdClass $seen,
                    private readonly string $path,
                ) {}

                public function fetchServices(): TheMostPanelCatalogFetchResult
                {
                    $this->seen->markerExisted = file_exists($this->path);

                    if ($this->seen->markerExisted) {
                        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->path))));
                        $this->seen->markerState = json_decode($lines[0], true)['state'] ?? null;
                    }

                    return TheMostPanelCatalogFetchResult::blocked('blocked_disabled');
                }
            },
        );

        Http::fake();

        $this->artisan('themostpanel:catalog-sync', [
            '--approved-once' => true, '--execution-id' => 'B4A-CMD-0003',
        ])->assertFailed();

        $this->assertTrue($seen->markerExisted, '⛔ source 執行時 marker 必須已落盤');
        $this->assertSame('consumed-before-command', $seen->markerState);
    }

    /** ⛔ source throw(模擬 crash)後 marker 仍在;同 ID 永久拒絕。 */
    public function test_a_throwing_source_leaves_the_marker_and_burns_the_id(): void
    {
        Http::fake();
        $this->withCredential();

        $this->app->singleton(
            TheMostPanelServiceCatalogSource::class,
            fn () => new class implements TheMostPanelServiceCatalogSource
            {
                public function fetchServices(): TheMostPanelCatalogFetchResult
                {
                    throw new \RuntimeException('SOURCE-EXCEPTION-MARKER-314159');
                }
            },
        );

        $this->artisan('themostpanel:catalog-sync', [
            '--approved-once' => true, '--execution-id' => 'B4A-CMD-0004',
        ])
            ->expectsOutputToContain('outcome: catalog_source_failed')
            ->doesntExpectOutputToContain('SOURCE-EXCEPTION-MARKER-314159')
            ->doesntExpectOutputToContain('RuntimeException')
            ->assertFailed();

        $this->assertFileExists($this->ledgerPath('B4A-CMD-0004'));

        // 同 ID 再跑必須拒絕。
        $this->artisan('themostpanel:catalog-sync', [
            '--approved-once' => true, '--execution-id' => 'B4A-CMD-0004',
        ])->expectsOutputToContain('exclusive-create')->assertFailed();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('cache_locks')->count());
    }

    // ==================================== 3. safe output 與 ledger 內容

    public function test_a_fake_success_prints_safe_outcome_and_writes_both_records(): void
    {
        Http::fake([self::ENDPOINT => Http::response(self::fictionalServices())]);
        $this->withCredential();

        $this->artisan('themostpanel:catalog-sync', [
            '--approved-once' => true, '--execution-id' => 'B4A-CMD-0005',
        ])
            ->expectsOutputToContain('outcome: catalog_applied')
            ->expectsOutputToContain('catalog_applied: true')
            // ⛔ 目錄內容不進 console。
            ->doesntExpectOutputToContain('虛構命令列服務')
            ->doesntExpectOutputToContain('0.90')
            ->doesntExpectOutputToContain('9101')
            ->doesntExpectOutputToContain(self::KEY_MARKER)
            ->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(1, ProviderService::query()->count());

        // ledger:initial＋final 兩行,同一 artifact,且不含任何秘密／商業值。
        $raw = (string) file_get_contents($this->ledgerPath('B4A-CMD-0005'));
        $lines = array_values(array_filter(explode("\n", $raw)));

        $this->assertCount(2, $lines);
        $this->assertSame('consumed-before-command', json_decode($lines[0], true)['state']);

        $final = json_decode($lines[1], true);
        $this->assertSame('completed', $final['state']);
        $this->assertSame('catalog_applied', $final['outcome']);
        $this->assertTrue($final['catalog_applied']);

        $this->assertStringNotContainsString(self::KEY_MARKER, $raw);
        $this->assertStringNotContainsString('虛構命令列服務', $raw);
        $this->assertStringNotContainsString('0.90', $raw);
    }

    /** ⛔ B4-A 核心:parser 拒絕時 CLI 與 ledger 拿得到安全本地原因。 */
    public function test_a_parser_rejection_surfaces_the_safe_reason_and_field(): void
    {
        // rate 型別錯誤(number 而非 string)→ wrong_type + field rate。
        $bad = self::fictionalServices();
        $bad[0]['rate'] = 0.9;

        Http::fake([self::ENDPOINT => Http::response($bad)]);
        $this->withCredential();

        $this->artisan('themostpanel:catalog-sync', [
            '--approved-once' => true, '--execution-id' => 'B4A-CMD-0006',
        ])
            ->expectsOutputToContain('outcome: catalog_rejected_by_parser')
            ->expectsOutputToContain('parser_reason: catalog_wrong_type')
            ->expectsOutputToContain('parser_field: rate')
            ->doesntExpectOutputToContain('0.9')
            ->assertFailed();

        $final = json_decode(
            array_values(array_filter(explode(
                "\n",
                (string) file_get_contents($this->ledgerPath('B4A-CMD-0006'))
            )))[1],
            true
        );

        $this->assertSame('catalog_wrong_type', $final['parser_reason']);
        $this->assertSame('rate', $final['parser_field']);
        $this->assertSame(0, ProviderService::query()->count());
    }

    public function test_a_failed_sync_exits_nonzero_with_the_safe_code(): void
    {
        Http::fake([self::ENDPOINT => Http::response('oops', 500)]);
        $this->withCredential();

        $this->artisan('themostpanel:catalog-sync', [
            '--approved-once' => true, '--execution-id' => 'B4A-CMD-0007',
        ])
            ->expectsOutputToContain('outcome: server_error')
            ->doesntExpectOutputToContain('oops')
            ->assertFailed();

        $this->assertSame(0, ProviderService::query()->count());
    }

    // ==================================== 4. 介面封閉不變

    public function test_the_command_accepts_no_other_arguments_or_options(): void
    {
        $command = Artisan::all()['themostpanel:catalog-sync'];
        $definition = $command->getDefinition();

        // ⛔ 沒有 provider／action／endpoint／key／body 參數;ID 是非秘密識別碼。
        $this->assertSame([], $definition->getArguments());
        $this->assertSame(['approved-once', 'execution-id'], array_keys($definition->getOptions()));
    }

    public function test_there_is_no_http_route_for_the_sync(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('catalog-sync', $route->uri());
        }
    }

    public function test_nothing_schedules_the_sync(): void
    {
        $scheduled = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'catalog-sync'));

        // ⛔ 沒有 scheduler:每一次同步都必須是人在終端機的決定。
        $this->assertCount(0, $scheduled);
    }
}
