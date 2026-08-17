<?php

namespace Tests\Feature\Fulfillment;

use App\Contracts\TheMostPanelServiceCatalogSource;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The CLI: the only door, and a door that demands explicit acknowledgement.
 *
 * ⛔ Fixtures are fictional, credentials are `FAKE-` markers, no request
 * reaches the network.
 */
class TheMostPanelCatalogSyncCommandTest extends TestCase
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

        $this->app->singleton(
            TheMostPanelServiceCatalogSource::class,
            fn () => new TheMostPanelReadOnlyHttpProbe(TheMostPanelCurlCapability::supported()),
        );
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

    public function test_the_missing_acknowledgement_makes_zero_calls(): void
    {
        Http::fake();
        $this->withCredential();

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

        $this->artisan('themostpanel:catalog-sync')
            ->expectsOutputToContain('--approved-once')
            ->assertFailed();

        $watching = false;

        // ⛔ 缺 acknowledgement：credential 0、HTTP 0、目錄 0。
        $this->assertSame(0, $reads);
        Http::assertNothingSent();
        $this->assertSame(0, ProviderService::query()->count());
    }

    public function test_the_catalog_flag_off_fails_safely_even_with_acknowledgement(): void
    {
        Http::fake();
        $this->withCredential();
        config()->set('integrations.themostpanel_catalog_sync.enabled', false);

        $this->artisan('themostpanel:catalog-sync', ['--approved-once' => true])
            ->expectsOutputToContain('blocked_catalog_sync_disabled')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_a_fake_success_prints_the_safe_outcome_only(): void
    {
        Http::fake([self::ENDPOINT => Http::response(self::fictionalServices())]);
        $this->withCredential();

        $this->artisan('themostpanel:catalog-sync', ['--approved-once' => true])
            ->expectsOutputToContain('outcome: catalog_applied')
            ->expectsOutputToContain('catalog_applied: true')
            // ⛔ 目錄內容不進 console：名稱、rate、service ID 一律不印。
            ->doesntExpectOutputToContain('虛構命令列服務')
            ->doesntExpectOutputToContain('0.90')
            ->doesntExpectOutputToContain('9101')
            ->doesntExpectOutputToContain(self::KEY_MARKER)
            ->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(1, ProviderService::query()->count());
    }

    public function test_a_failed_sync_exits_nonzero_with_the_safe_code(): void
    {
        Http::fake([self::ENDPOINT => Http::response('oops', 500)]);
        $this->withCredential();

        $this->artisan('themostpanel:catalog-sync', ['--approved-once' => true])
            ->expectsOutputToContain('outcome: server_error')
            ->doesntExpectOutputToContain('oops')
            ->assertFailed();

        $this->assertSame(0, ProviderService::query()->count());
    }

    public function test_the_command_accepts_no_other_arguments_or_options(): void
    {
        $command = Artisan::all()['themostpanel:catalog-sync'];
        $definition = $command->getDefinition();

        // ⛔ 沒有 provider／action／endpoint／key／body 參數。
        $this->assertSame([], $definition->getArguments());
        $this->assertSame(['approved-once'], array_keys($definition->getOptions()));
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

        // ⛔ 沒有 scheduler：每一次同步都必須是人在終端機的決定。
        $this->assertCount(0, $scheduled);
    }
}
