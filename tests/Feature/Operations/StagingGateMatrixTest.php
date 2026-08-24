<?php

namespace Tests\Feature\Operations;

use App\Contracts\FulfillmentGateway;
use App\Services\Fulfillment\DisabledFulfillmentGateway;
use App\Services\Fulfillment\FulfillmentDispatchGate;
use App\Services\Fulfillment\TheMostPanelFulfillmentGateway;
use App\Services\Fulfillment\TheMostPanelLiveCredentialSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * The environment × owner-switch × runtime matrix, exhaustively fail closed.
 *
 * ⛔ R1:唯一的營運開關是 Owner 的 production `integration_settings.is_enabled`;
 * driver 與舊 env 旗標對 staging／production 完全沒有作用。live 路徑另需
 * exact endpoint 與 runtime 傳輸能力——本檔逐格證明缺任何一項都是 Disabled,
 * 而且已 deprecated 的旗標往哪個方向扳都改變不了結果。
 *
 * ⛔ supported runtime 一律明確描述:這台機器的 libcurl 其實不支援,靠它
 * 碰巧通過的測試在別台機器上會翻面。
 */
class StagingGateMatrixTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** @param array<string, mixed> $config */
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

    // ==================================== live 路徑(staging／production)

    /** staging＋Owner 開關＋supported runtime:解析真 adapter。 */
    public function test_staging_with_the_owner_switch_resolves_the_real_adapter(): void
    {
        $this->enableDispatchSwitch();
        $this->withSupportedDispatchRuntime();

        $gateway = $this->gatewayIn('staging');

        $this->assertInstanceOf(TheMostPanelFulfillmentGateway::class, $gateway);
    }

    /** R1:production 與 staging 同一條路——Owner 開了就解析真 adapter。 */
    public function test_production_with_the_owner_switch_resolves_the_real_adapter(): void
    {
        $this->enableDispatchSwitch();
        $this->withSupportedDispatchRuntime();

        $gateway = $this->gatewayIn('production');

        $this->assertInstanceOf(TheMostPanelFulfillmentGateway::class, $gateway);
    }

    /** ⛔ Owner 開關關閉:staging／production 都是 Disabled,舊旗標開滿也沒用。 */
    public function test_the_owner_switch_off_yields_disabled_whatever_the_flags_say(): void
    {
        $this->withSupportedDispatchRuntime();

        foreach (['staging', 'production'] as $env) {
            $gateway = $this->gatewayIn($env, [
                // ⛔ 已 deprecated:值一律被忽略。
                'fulfillment.driver' => 'themostpanel',
                'fulfillment.dispatch_enabled' => true,
                'fulfillment.staging.themostpanel_dispatch_enabled' => true,
                'fulfillment.status_polling_enabled' => true,
            ]);

            $this->assertInstanceOf(DisabledFulfillmentGateway::class, $gateway, $env);
        }
    }

    /** ⛔ runtime 不支援(如本機 libcurl 7.85):Owner 開了也是 Disabled。 */
    public function test_an_unsupported_runtime_yields_disabled_even_with_the_switch_on(): void
    {
        $this->enableDispatchSwitch();
        $this->withUnsupportedDispatchRuntime();

        foreach (['staging', 'production'] as $env) {
            $this->assertInstanceOf(
                DisabledFulfillmentGateway::class,
                $this->gatewayIn($env),
                $env,
            );
        }
    }

    /** ⛔ 端點被竄改:Owner 開了、runtime 支援,仍是 Disabled。 */
    public function test_a_tampered_endpoint_yields_disabled(): void
    {
        $this->enableDispatchSwitch();
        $this->withSupportedDispatchRuntime();

        $gateway = $this->gatewayIn('staging', [
            'integrations.endpoints.themostpanel.production' => 'https://evil.invalid/api',
        ]);

        $this->assertInstanceOf(DisabledFulfillmentGateway::class, $gateway);
    }

    // ==================================== local／testing 只有測試 stub

    /** ⛔ local:Owner 開關開著、driver 亂填,也永遠拿不到真實 dispatch adapter。 */
    public function test_local_never_resolves_the_real_adapter(): void
    {
        $this->enableDispatchSwitch();
        $this->withSupportedDispatchRuntime();

        $gateway = $this->gatewayIn('local', [
            'fulfillment.driver' => 'themostpanel',
        ]);

        $this->assertInstanceOf(DisabledFulfillmentGateway::class, $gateway);
    }

    /** 未知 environment 一律 Disabled。 */
    public function test_an_unknown_environment_is_disabled(): void
    {
        $this->enableDispatchSwitch();
        $this->withSupportedDispatchRuntime();

        $gateway = $this->gatewayIn('qa', [
            'fulfillment.driver' => 'themostpanel',
        ]);

        $this->assertInstanceOf(DisabledFulfillmentGateway::class, $gateway);
    }

    // ==================================== gate 矩陣

    /**
     * gate 逐格:Owner 開關 × 環境 × driver(僅 local/testing 有意義)。
     *
     * ⛔ gate 與 container binding 必須逐格一致:gate 說可以、binding 給
     * Disabled,列會在 ready 與 blocked 之間空轉。
     */
    public function test_the_dispatch_gate_matrix(): void
    {
        $this->enableDispatchSwitch();
        $this->withSupportedDispatchRuntime();

        $cases = [
            // [env, driver, expected]
            ['staging', 'disabled', true],       // ⛔ driver 對 live 路徑無作用
            ['staging', 'themostpanel', true],
            ['production', 'disabled', true],
            ['local', 'themostpanel', false],    // local 只有 fake stub
            ['local', 'fake', true],
            ['testing', 'themostpanel', true],   // 注入式 fake transport 的 e2e seam
            ['testing', 'fake', true],
            ['testing', 'disabled', false],
            ['qa', 'fake', false],               // 未知環境一律 false
        ];

        foreach ($cases as [$env, $driver, $expected]) {
            $this->app['env'] = $env;
            config()->set('fulfillment.driver', $driver);

            $this->assertSame($expected, FulfillmentDispatchGate::enabled(), "$env/$driver");
        }

        $this->app['env'] = 'testing';
    }

    /** ⛔ Owner 開關關閉時,gate 在每一個環境都是 false——包含測試 stub 路徑。 */
    public function test_the_gate_is_false_everywhere_with_the_switch_off(): void
    {
        $this->withSupportedDispatchRuntime();
        config()->set('fulfillment.driver', 'fake');

        foreach (['staging', 'production', 'local', 'testing'] as $env) {
            $this->app['env'] = $env;
            $this->assertFalse(FulfillmentDispatchGate::enabled(), $env);
        }

        $this->app['env'] = 'testing';
    }

    // ==================================== live binding 細節

    /** ⛔ live 綁定使用 live credential source(加密 production 列),而非測試注入。 */
    public function test_the_live_binding_uses_the_live_credential_source(): void
    {
        $this->enableDispatchSwitch();
        $this->withSupportedDispatchRuntime();

        $gateway = $this->gatewayIn('staging');

        $property = new \ReflectionProperty($gateway, 'credentials');

        $this->assertInstanceOf(TheMostPanelLiveCredentialSource::class, $property->getValue($gateway));
    }

    /** ⛔ 解析 container 本身 0 HTTP;gate 判斷只讀 DB 與本機 runtime。 */
    public function test_resolving_the_gateway_sends_nothing(): void
    {
        $this->enableDispatchSwitch();
        $this->withSupportedDispatchRuntime();

        $this->gatewayIn('staging');
        $this->gatewayIn('production');

        Http::assertNothingSent();
    }

    /** ⛔ Owner 關掉開關後重新解析:必須回到 Disabled(binding 每次重新判斷)。 */
    public function test_switching_off_then_resolving_again_yields_disabled(): void
    {
        $this->enableDispatchSwitch();
        $this->withSupportedDispatchRuntime();

        $this->assertInstanceOf(TheMostPanelFulfillmentGateway::class, $this->gatewayIn('staging'));

        DB::table('integration_settings')->where('provider', 'themostpanel')->update(['is_enabled' => false]);

        $this->assertInstanceOf(DisabledFulfillmentGateway::class, $this->gatewayIn('staging'));
    }
}
