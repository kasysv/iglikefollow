<?php

namespace Tests\Feature\Operations;

use App\Console\Commands\StagingReadinessCommand;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\FulfillmentMapping;
use App\Models\IntegrationSetting;
use App\Models\ProviderService;
use App\Models\ServiceVariant;
use App\Services\Integrations\LiveIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresLiveIntegrations;
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
    use ConfiguresLiveIntegrations;
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
     * ⛔ cross-seam：readiness 的通道狀態必須與真正的 runtime 判斷同源同值。
     *
     * readiness 若自己算一份，就會出現 Owner 看到「已啟用」而 adapter 其實拒絕
     * ——或反過來。那讓 readiness 從診斷工具變成一個誤導來源。
     *
     * ⛔ M4C：逐個通道對照，矩陣改成「credential 齊全 × Owner 開關」。
     */
    public function test_channel_readiness_matches_the_runtime_truth(): void
    {
        $this->healthyStagingConfig();

        $matrix = [
            [IntegrationProvider::EcpayPayment, 'channel_ecpay_payment', '3000001'],
            [IntegrationProvider::LinePay, 'channel_line_pay', 'channel-0001'],
            [IntegrationProvider::EcpayInvoice, 'channel_ecpay_invoice', '3000001'],
        ];

        foreach ($matrix as [$provider, $key, $identifier]) {
            // 1. 完全沒有設定。
            $checks = collect(StagingReadinessCommand::report()['checks']);
            $this->assertSame('blocked', $checks->firstWhere('key', $key)['status'], $key.' 未設定');
            $this->assertStringContainsString('live=no', $checks->firstWhere('key', $key)['value']);

            // 2. credential 齊全但 Owner 沒開。
            $this->configureChannelWithoutEnabling($provider, $identifier);
            $value = collect(StagingReadinessCommand::report()['checks'])->firstWhere('key', $key);
            $this->assertSame('blocked', $value['status'], $key.' 未啟用');
            $this->assertStringContainsString('credential=complete', $value['value']);
            $this->assertStringContainsString('owner_switch=off', $value['value']);

            // 3. Owner 開了。
            $this->enableChannel($provider, $identifier);
            $value = collect(StagingReadinessCommand::report()['checks'])->firstWhere('key', $key);
            $this->assertSame('ok', $value['status'], $key.' 已啟用');
            $this->assertStringContainsString('live=yes', $value['value']);

            // ⛔ 與 runtime 同源：readiness 說可用，adapter 就必須也說可用。
            $this->assertTrue(LiveIntegration::availableToCustomer($provider), $key.' 與 runtime 不一致');
        }
    }

    /**
     * ⛔ 本機環境：通道就算全開，readiness 也必須說「不會對外送出」。
     *
     * 這一格最容易被寫錯成「開關開了就是 ok」，而那會讓人在開發機器上看到
     * 一份說自己正在收款的 readiness 報告。
     */
    public function test_a_local_machine_reports_outbound_as_blocked(): void
    {
        $this->healthyStagingConfig();
        $this->enableAllChannels();

        $this->app['env'] = 'local';

        $checks = collect(StagingReadinessCommand::report()['checks']);

        $this->assertSame('blocked', $checks->firstWhere('key', 'outbound_allowed')['status']);

        foreach (['channel_ecpay_payment', 'channel_line_pay', 'channel_ecpay_invoice'] as $key) {
            $value = $checks->firstWhere('key', $key);
            $this->assertSame('blocked', $value['status'], $key);
            // ⛔ credential 齊全、開關也開著，但 live=no——三件事分開回報。
            $this->assertStringContainsString('owner_switch=on', $value['value']);
            $this->assertStringContainsString('live=no', $value['value']);
        }

        $this->app['env'] = 'staging';
    }

    /**
     * ⛔ 已 deprecated 的 sandbox 旗標不得再影響 readiness。
     *
     * 這是 Owner 那次「付款方式目前無法使用」的根因；釘住它，同一個問題就不會
     * 換個地方再發生一次。
     */
    public function test_the_deprecated_flags_do_not_affect_readiness(): void
    {
        $this->healthyStagingConfig();
        $this->enableAllChannels();

        config()->set('integrations.payments.sandbox_enabled', false);
        config()->set('integrations.invoice.sandbox_enabled', false);

        $checks = collect(StagingReadinessCommand::report()['checks']);

        foreach (['channel_ecpay_payment', 'channel_line_pay', 'channel_ecpay_invoice'] as $key) {
            $this->assertSame('ok', $checks->firstWhere('key', $key)['status'], $key);
        }
    }

    /** ⛔ 端點必須恰好是官方正式網址；不符即 blocker。 */
    public function test_a_tampered_endpoint_is_reported_as_a_blocker(): void
    {
        $this->healthyStagingConfig();

        $checks = collect(StagingReadinessCommand::report()['checks']);
        $this->assertSame('ok', $checks->firstWhere('key', 'endpoint_ecpay_payment')['status']);

        config()->set('integrations.endpoints.ecpay_payment.production', 'https://evil.example.com/pay');

        $checks = collect(StagingReadinessCommand::report()['checks']);
        $this->assertSame('blocker', $checks->firstWhere('key', 'endpoint_ecpay_payment')['status']);
        $this->assertSame('unexpected', $checks->firstWhere('key', 'endpoint_ecpay_payment')['value']);
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
