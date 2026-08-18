<?php

namespace Tests\Feature\Operations;

use App\Contracts\FulfillmentGateway;
use App\Services\Fulfillment\DisabledFulfillmentGateway;
use App\Services\Fulfillment\FulfillmentDispatchGate;
use App\Services\Fulfillment\TheMostPanelFulfillmentGateway;
use App\Services\Fulfillment\TheMostPanelStagingCredentialSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The environment × driver × flag matrix, exhaustively fail closed.
 *
 * ⛔ Production is Disabled whatever the config says; local can never obtain
 * a real dispatch driver; staging is default-off and only the full stack of
 * conditions resolves the real adapter.
 */
class StagingGateMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function gatewayIn(string $env, array $config = []): FulfillmentGateway
    {
        $this->app['env'] = $env;

        foreach ($config as $key => $value) {
            config()->set($key, $value);
        }

        $this->app->forgetInstance(FulfillmentGateway::class);

        $gateway = $this->app->make(FulfillmentGateway::class);

        $this->app['env'] = 'testing';

        return $gateway;
    }

    /** ⛔ production:全部旗標開到最大仍是 Disabled。 */
    public function test_production_is_disabled_no_matter_what(): void
    {
        $gateway = $this->gatewayIn('production', [
            'fulfillment.driver' => 'themostpanel',
            'fulfillment.dispatch_enabled' => true,
            'fulfillment.staging.themostpanel_dispatch_enabled' => true,
        ]);

        $this->assertInstanceOf(DisabledFulfillmentGateway::class, $gateway);

        $this->app['env'] = 'production';
        config()->set('fulfillment.driver', 'themostpanel');
        config()->set('fulfillment.dispatch_enabled', true);
        config()->set('fulfillment.staging.themostpanel_dispatch_enabled', true);
        $this->assertFalse(FulfillmentDispatchGate::enabled());
        $this->app['env'] = 'testing';
    }

    /** ⛔ local:即使 staging flag 全開,也永遠拿不到真實 dispatch driver。 */
    public function test_local_never_resolves_the_real_adapter(): void
    {
        $gateway = $this->gatewayIn('local', [
            'fulfillment.driver' => 'themostpanel',
            'fulfillment.dispatch_enabled' => true,
            'fulfillment.staging.themostpanel_dispatch_enabled' => true,
        ]);

        $this->assertInstanceOf(DisabledFulfillmentGateway::class, $gateway);
    }

    /** staging default-off:flag 不開就是 Disabled。 */
    public function test_staging_defaults_to_disabled(): void
    {
        $gateway = $this->gatewayIn('staging', [
            'fulfillment.driver' => 'themostpanel',
            'fulfillment.dispatch_enabled' => true,
            // staging dispatch flag 維持 default false
        ]);

        $this->assertInstanceOf(DisabledFulfillmentGateway::class, $gateway);
    }

    /** staging＋driver＋flag 全部成立:解析真 adapter(staging credential source)。 */
    public function test_staging_with_the_full_stack_resolves_the_real_adapter(): void
    {
        $gateway = $this->gatewayIn('staging', [
            'fulfillment.driver' => 'themostpanel',
            'fulfillment.dispatch_enabled' => true,
            'fulfillment.staging.themostpanel_dispatch_enabled' => true,
        ]);

        $this->assertInstanceOf(TheMostPanelFulfillmentGateway::class, $gateway);
    }

    /** 未知 environment 一律 Disabled。 */
    public function test_an_unknown_environment_is_disabled(): void
    {
        $gateway = $this->gatewayIn('qa', [
            'fulfillment.driver' => 'themostpanel',
            'fulfillment.dispatch_enabled' => true,
            'fulfillment.staging.themostpanel_dispatch_enabled' => true,
        ]);

        $this->assertInstanceOf(DisabledFulfillmentGateway::class, $gateway);
    }

    /** staging gate:driver=fake 仍照舊;flag 只影響 themostpanel。 */
    public function test_the_dispatch_gate_matrix(): void
    {
        $cases = [
            // [env, driver, staging_flag, expected]
            ['staging', 'themostpanel', false, false],
            ['staging', 'themostpanel', true, true],
            ['staging', 'disabled', true, false],
            ['local', 'themostpanel', true, false],
            ['testing', 'themostpanel', false, true], // 測試注入 seam 維持
            ['local', 'fake', false, true],
        ];

        foreach ($cases as [$env, $driver, $flag, $expected]) {
            $this->app['env'] = $env;
            config()->set('fulfillment.driver', $driver);
            config()->set('fulfillment.dispatch_enabled', true);
            config()->set('fulfillment.staging.themostpanel_dispatch_enabled', $flag);

            $this->assertSame($expected, FulfillmentDispatchGate::enabled(), "$env/$driver/flag=".var_export($flag, true));
        }

        $this->app['env'] = 'testing';
    }

    /** ⛔ staging 綁定使用 staging credential source(加密 setting 列),而非測試注入。 */
    public function test_the_staging_binding_uses_the_staging_credential_source(): void
    {
        $gateway = $this->gatewayIn('staging', [
            'fulfillment.driver' => 'themostpanel',
            'fulfillment.dispatch_enabled' => true,
            'fulfillment.staging.themostpanel_dispatch_enabled' => true,
        ]);

        $property = new \ReflectionProperty($gateway, 'credentials');

        $this->assertInstanceOf(TheMostPanelStagingCredentialSource::class, $property->getValue($gateway));
    }
}
