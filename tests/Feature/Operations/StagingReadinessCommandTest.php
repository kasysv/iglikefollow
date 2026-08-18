<?php

namespace Tests\Feature\Operations;

use App\Console\Commands\StagingReadinessCommand;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\FulfillmentMapping;
use App\Models\IntegrationSetting;
use App\Models\ProviderService;
use App\Models\ServiceVariant;
use App\Services\Invoices\InvoiceSandboxGuard;
use App\Services\Payments\SandboxGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * ⛔ R1(P0-1)cross-seam:readiness 的付款/發票狀態必須與真正的
     * runtime guards 同源同值——同一 flag matrix 下逐格對照。
     */
    public function test_payment_and_invoice_readiness_match_the_runtime_guards(): void
    {
        $this->healthyStagingConfig();

        foreach ([[false, false], [true, false], [false, true], [true, true]] as [$payment, $invoice]) {
            config()->set('integrations.payments.sandbox_enabled', $payment);
            config()->set('integrations.invoice.sandbox_enabled', $invoice);

            $report = StagingReadinessCommand::report();
            $checks = collect($report['checks']);

            $this->assertSame(
                SandboxGuard::enabled() ? 'ok' : 'blocked',
                $checks->firstWhere('key', 'sandbox_payment')['status'],
            );
            $this->assertSame(
                InvoiceSandboxGuard::enabled() ? 'ok' : 'blocked',
                $checks->firstWhere('key', 'sandbox_invoice')['status'],
            );
        }

        // production:guard hard stop → readiness 同步為 not enabled。
        $this->app['env'] = 'production';
        config()->set('integrations.payments.sandbox_enabled', true);
        $this->assertFalse(SandboxGuard::enabled());
        $report = StagingReadinessCommand::report();
        $this->assertSame('not enabled', collect($report['checks'])->firstWhere('key', 'sandbox_payment')['value']);
        $this->app['env'] = 'staging';
    }

    /** R1(3.4):mapping readiness 誠實呈現目前真相,strict 時為 blocker。 */
    public function test_mapping_readiness_reports_the_truth_and_blocks_strict(): void
    {
        $this->healthyStagingConfig();

        // 測試 DB:0 mapping → total=0;enabled=0;enabled_compatible=0。
        $report = StagingReadinessCommand::report();
        $check = collect($report['checks'])->firstWhere('key', 'fulfillment_mappings');
        $this->assertSame('total=0;enabled=0;enabled_compatible=0', $check['value']);
        $this->assertSame('blocked', $check['status']);

        $strict = StagingReadinessCommand::report(true);
        $this->assertSame('blocker', collect($strict['checks'])->firstWhere('key', 'fulfillment_mappings')['status']);
    }

    /** ⛔ enabled 但不相容/不可用的 mapping 不算 enabled_compatible。 */
    public function test_enabled_compatible_reverifies_against_the_catalog(): void
    {
        $this->healthyStagingConfig();

        $variant = ServiceVariant::factory()->create();

        // enabled mapping 指向不存在的 provider row → fail closed,不算 compatible。
        $stale = FulfillmentMapping::factory()->create([
            'service_variant_id' => $variant->id,
            'provider_service_id' => '99901',
        ]);
        DB::table('fulfillment_mappings')->where('id', $stale->id)->update(['is_enabled' => 1]);

        $report = StagingReadinessCommand::report();
        $this->assertSame(
            'total=1;enabled=1;enabled_compatible=0',
            collect($report['checks'])->firstWhere('key', 'fulfillment_mappings')['value'],
        );

        // 補上 available＋相容的 provider row → compatible=1。
        ProviderService::factory()->available()->create([
            'provider_service_id' => '99901',
            'minimum_quantity_raw' => '10',
            'maximum_quantity_raw' => '20000',
        ]);

        $report = StagingReadinessCommand::report();
        $check = collect($report['checks'])->firstWhere('key', 'fulfillment_mappings');
        $this->assertSame('total=1;enabled=1;enabled_compatible=1', $check['value']);
        $this->assertSame('ok', $check['status']);
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
        $this->assertStringContainsString('present;enabled=no;encrypted_payload=stored;identifier=not-required', $encoded);
    }
}
