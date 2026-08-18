<?php

namespace Tests\Feature\Operations;

use App\Console\Commands\StagingReadinessCommand;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `app:staging-readiness`: truthful, safe, and stable.
 *
 * ⛔ blocker と blocked are different words on purpose: a capability that is
 * intentionally off is the expected default, not an error — until
 * `--strict-live-readiness` asks whether live lanes could actually run.
 */
class StagingReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_MARKER = 'FAKE-READINESS-KEY-MARKER-880022';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** 模擬一個設定完善的 staging 部署(capability 全部維持 default off)。 */
    private function healthyStagingConfig(): void
    {
        $this->app['env'] = 'staging';
        config()->set('app.url', 'https://staging.example.invalid');
        config()->set('app.debug', false);
        config()->set('seo.allow_indexing', false);
        config()->set('queue.default', 'database');
    }

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';

        parent::tearDown();
    }

    public function test_a_healthy_staging_deployment_exits_zero_with_capabilities_blocked(): void
    {
        $this->healthyStagingConfig();

        $this->artisan('app:staging-readiness')->assertSuccessful();
    }

    public function test_blockers_exit_non_zero(): void
    {
        $this->healthyStagingConfig();
        config()->set('app.url', 'http://staging.example.invalid'); // http → blocker

        $this->artisan('app:staging-readiness')->assertFailed();
    }

    /** ⛔ 可索引的 staging 是 blocker。 */
    public function test_an_indexable_staging_is_a_blocker(): void
    {
        $this->healthyStagingConfig();
        config()->set('seo.allow_indexing', true);

        $this->artisan('app:staging-readiness')->assertFailed();
    }

    public function test_a_sync_queue_is_a_blocker(): void
    {
        $this->healthyStagingConfig();
        config()->set('queue.default', 'sync');

        $this->artisan('app:staging-readiness')->assertFailed();
    }

    /** strict 才把未開啟的付款/發票/派單能力視為失敗。 */
    public function test_strict_mode_turns_disabled_capabilities_into_blockers(): void
    {
        $this->healthyStagingConfig();

        $this->artisan('app:staging-readiness')->assertSuccessful();
        $this->artisan('app:staging-readiness --strict-live-readiness')->assertFailed();
    }

    /** JSON schema 穩定且不含 secret。 */
    public function test_the_json_output_is_stable_and_secret_free(): void
    {
        $this->healthyStagingConfig();

        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::TheMostPanel, IntegrationEnvironment::Production)
            ->create();
        $setting->credentials = ['ApiKey' => self::KEY_MARKER];
        $setting->save();

        $this->artisan('app:staging-readiness --json')->assertSuccessful();

        $report = StagingReadinessCommand::report();

        foreach (['strict', 'checks', 'blockers', 'blocked'] as $key) {
            $this->assertArrayHasKey($key, $report);
        }
        foreach ($report['checks'] as $check) {
            foreach (['key', 'label', 'value', 'status'] as $field) {
                $this->assertArrayHasKey($field, $check);
            }
            $this->assertContains($check['status'], ['ok', 'blocked', 'blocker']);
        }

        // ⛔ secret 0 出現;credential 只以 presence 呈現。
        $encoded = json_encode($report, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(self::KEY_MARKER, $encoded);
        $this->assertStringContainsString('present;enabled=no', $encoded);
    }
}
