<?php

namespace Tests\Feature\Fulfillment;

use App\Actions\Fulfillment\PrepareFulfillmentForOrder;
use App\Actions\Fulfillment\QueueFulfillmentStatusSync;
use App\Actions\Fulfillment\SubmitFulfillment;
use App\Contracts\FulfillmentGateway;
use App\Data\Fulfillment\FulfillmentSubmission;
use App\Enums\FulfillmentStatus;
use App\Enums\IntegrationProvider;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\FulfillmentMapping;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ServiceVariant;
use App\Services\Fulfillment\DisabledFulfillmentGateway;
use App\Services\Fulfillment\TheMostPanelCurlCapability;
use App\Services\Fulfillment\TheMostPanelFulfillmentGateway;
use App\Services\Fulfillment\TheMostPanelLiveCredentialSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * R2: the Owner's switch must take effect inside a LONG-LIVED worker.
 *
 * GPT 的獨立反證抓到 P0:`FulfillmentGateway` 綁成 singleton,queue worker
 * 是長駐程序——worker 在 OFF 時先解析過一次,singleton 就把 Disabled 凍成
 * 整個 worker 生命週期的事實;Owner 之後在後台切 ON,同一個 worker 仍拿舊
 * 的 Disabled,直到人工 `queue:restart`。那正是「按了開關卻沒有反應」的
 * queue 版本。
 *
 * ⛔ 這一檔的每個測試都在**同一個 application container** 內模擬長駐 worker:
 * 先解析、改 DB、再解析/再使用——⛔ 全程不呼叫 `forgetInstance()`、不重建
 * container、不清 cache。修法(transient binding)必須讓「下一次解析」看到
 * 新狀態;而「已解析的舊 instance」則由 credential source 每次重查 DB 在
 * 網路前停住。
 *
 * ⛔ 全程 `Http::preventStrayRequests()`;所有 provider 回應都是 fixture。
 * supported runtime 一律明確描述(這台機器的 libcurl 其實不支援)。
 */
class FulfillmentGatewayWorkerToggleTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    private const ENDPOINT = 'https://themostpanel.com/api/v2';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** 同一 container 反覆解析;⛔ 絕不 forgetInstance——那正是被禁止的假修。 */
    private function resolveGateway(): FulfillmentGateway
    {
        return $this->app->make(FulfillmentGateway::class);
    }

    // ==================================== 1. OFF 先解析 → ON → 下一次解析生效

    /**
     * GPT 反證的精確重現,現在必須 PASS:
     *
     *   1. production row 完整但 is_enabled=false
     *   2. resolve FulfillmentGateway → Disabled
     *   3. DB 切 is_enabled=true(模擬 Owner 在另一個 HTTP request 切 ON)
     *   4. 同一 container 再 resolve → 必須是 live adapter
     *
     * ⛔ 沒有 queue:restart、沒有 forgetInstance、沒有 cache clear。
     */
    public function test_a_worker_that_resolved_while_off_sees_the_switch_turn_on(): void
    {
        $this->withSupportedDispatchRuntime();
        $this->configureChannelWithoutEnabling(IntegrationProvider::TheMostPanel);
        $this->app['env'] = 'staging';

        // worker 在 OFF 時先碰過一筆履約工作。
        $this->assertInstanceOf(DisabledFulfillmentGateway::class, $this->resolveGateway());

        // Owner 在後台切 ON(另一個程序,只共享 DB)。
        DB::table('integration_settings')->where('provider', 'themostpanel')->update(['is_enabled' => true]);

        // ⛔ 同一 container 的下一次解析必須立即看到新狀態。
        $this->assertInstanceOf(TheMostPanelFulfillmentGateway::class, $this->resolveGateway());

        $this->app['env'] = 'testing';
    }

    /** 反方向也一樣:ON 先解析 → OFF → 下一次解析回到 Disabled。 */
    public function test_a_worker_that_resolved_while_on_sees_the_switch_turn_off(): void
    {
        $this->withSupportedDispatchRuntime();
        $this->enableDispatchSwitch();
        $this->app['env'] = 'staging';

        $this->assertInstanceOf(TheMostPanelFulfillmentGateway::class, $this->resolveGateway());

        DB::table('integration_settings')->where('provider', 'themostpanel')->update(['is_enabled' => false]);

        $this->assertInstanceOf(DisabledFulfillmentGateway::class, $this->resolveGateway());

        $this->app['env'] = 'testing';
    }

    // ==================================== 2. 已解析的舊 live instance:OFF 後 0 request

    /**
     * ⛔ transient 只保證「下一次解析」;這個測試保證另一半——worker 手上
     * 已經解析好的 live instance,在 Owner 切 OFF 後呼叫 submit 也是
     * 0 request:credential source 每次重查 DB,row 停用 → apiKey()=null
     * → 網路前 blocked。
     */
    public function test_an_already_resolved_live_instance_sends_nothing_after_switch_off(): void
    {
        $this->withSupportedDispatchRuntime();
        $this->enableDispatchSwitch();
        $this->app['env'] = 'staging';

        $gateway = $this->resolveGateway();
        $this->assertInstanceOf(TheMostPanelFulfillmentGateway::class, $gateway);

        // Owner 切 OFF;worker 手上還是舊 instance。
        DB::table('integration_settings')->where('provider', 'themostpanel')->update(['is_enabled' => false]);

        Http::fake();

        $result = $gateway->submit(new FulfillmentSubmission('4501', 'https://example.invalid/post', 1000));
        $this->assertTrue($result->isBlocked());

        $sync = $gateway->sync('23501');
        $this->assertFalse($sync->isRecognised());

        // ⛔ submit 與 sync 都一個 request 都沒送。
        Http::assertNothingSent();

        $this->app['env'] = 'testing';
    }

    // ==================================== 3. OFF→ON 後的端到端:mapping 雙閘不變

    private function paidOrderWithItem(ServiceVariant $variant): Order
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'total_amount' => 590,
            'paid_at' => now(),
        ])->fresh();

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_variant_id' => $variant->id,
        ]);

        return $order;
    }

    /**
     * OFF 先解析過的 worker,ON 之後:mapping ON＋可信 paid 的新訂單
     * **恰好一次 add**(fake HTTP);全程同一 container、無 restart。
     */
    public function test_after_turning_on_a_new_paid_order_is_dispatched_exactly_once(): void
    {
        $this->withSupportedDispatchRuntime();
        $this->configureChannelWithoutEnabling(IntegrationProvider::TheMostPanel);
        $this->app['env'] = 'staging';

        // worker 先在 OFF 時碰過一次。
        $this->assertInstanceOf(DisabledFulfillmentGateway::class, $this->resolveGateway());

        // Owner 切 ON。
        DB::table('integration_settings')->where('provider', 'themostpanel')->update(['is_enabled' => true]);

        // 開啟後才付款成功的新訂單;mapping ON 且可用。
        // ⛔ 真 adapter 要求 canonical 數字 service id;'4501' 是虛構測試值。
        $variant = ServiceVariant::factory()->create();
        FulfillmentMapping::factory()->enabled()->create([
            'service_variant_id' => $variant->id,
            'provider_service_id' => '4501',
        ]);
        $order = $this->paidOrderWithItem($variant);

        Http::fake([self::ENDPOINT => Http::response(['order' => 23501])]);

        $rows = app(PrepareFulfillmentForOrder::class)->handle($order->fresh());
        $this->assertCount(1, $rows);
        $this->assertSame(FulfillmentStatus::Ready, $rows[0]->status);

        // ⛔ SubmitFulfillment 由 container 解析——它拿到的 gateway 就是
        // 長駐 worker 下一筆 job 會拿到的那一個。
        $row = app(SubmitFulfillment::class)->handle($rows[0]);

        $this->assertSame(FulfillmentStatus::Submitted, $row->status);
        $this->assertSame('23501', $row->provider_order_id);

        // ⛔ 恰好一次 add,而且只到白名單端點。
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === self::ENDPOINT);

        $this->app['env'] = 'testing';
    }

    /** ⛔ 同一情境但 mapping OFF:總開關 ON 也是 0 add——雙閘缺一不可。 */
    public function test_after_turning_on_a_disabled_mapping_still_blocks_dispatch(): void
    {
        $this->withSupportedDispatchRuntime();
        $this->configureChannelWithoutEnabling(IntegrationProvider::TheMostPanel);
        $this->app['env'] = 'staging';

        $this->resolveGateway(); // OFF 時先解析。
        DB::table('integration_settings')->where('provider', 'themostpanel')->update(['is_enabled' => true]);

        $variant = ServiceVariant::factory()->create();
        FulfillmentMapping::factory()->create(['service_variant_id' => $variant->id]); // ⛔ disabled
        $order = $this->paidOrderWithItem($variant);

        Http::fake();

        // ⛔ handle() 只回傳 Ready 列;pending 列從 DB 讀。
        $this->assertSame([], app(PrepareFulfillmentForOrder::class)->handle($order->fresh()));

        $this->assertSame(FulfillmentStatus::ConfigurationPending, FulfillmentOrder::sole()->status);
        Http::assertNothingSent();

        $this->app['env'] = 'testing';
    }

    // ==================================== 4. local／testing:DB ON＋正式端點仍 0 外呼

    /**
     * ⛔ local:Owner 開關 ON、端點正式、runtime 支援——container 仍給
     * Disabled;直接手工組 live adapter 也在網路前被環境邊界擋下。
     */
    public function test_local_sends_nothing_even_with_the_switch_on_and_the_real_endpoint(): void
    {
        $this->withSupportedDispatchRuntime();
        $this->enableDispatchSwitch();
        $this->app['env'] = 'local';

        $this->assertInstanceOf(DisabledFulfillmentGateway::class, $this->resolveGateway());

        Http::fake();

        // 有人在本機硬把 live adapter 接起來:仍 0 request。
        $rogue = new TheMostPanelFulfillmentGateway(
            new TheMostPanelLiveCredentialSource,
            TheMostPanelCurlCapability::supported(),
        );

        $this->assertTrue(
            $rogue->submit(new FulfillmentSubmission('4501', 'https://example.invalid/post', 1000))->isBlocked(),
        );
        Http::assertNothingSent();

        $this->app['env'] = 'testing';
    }

    // ==================================== 5. status polling 在同一 worker 內跟著開關

    /**
     * 同一 container:ON 時排入、切 OFF 後下一輪 0——polling 的判斷是逐次
     * function call,本來就不被 singleton 凍住,這裡釘住它保持如此。
     */
    public function test_polling_in_one_long_lived_worker_follows_the_switch(): void
    {
        Queue::fake();
        $this->withSupportedDispatchRuntime();
        $this->enableDispatchSwitch();
        FulfillmentOrder::factory()->submitted('91001')->create();

        $this->app['env'] = 'staging';

        // ON:排入 1。
        $this->assertSame(1, app(QueueFulfillmentStatusSync::class)->handle());

        // Owner 切 OFF;同一 container 的下一輪必須 0。
        DB::table('integration_settings')->where('provider', 'themostpanel')->update(['is_enabled' => false]);
        $this->assertSame(0, app(QueueFulfillmentStatusSync::class)->handle());

        $this->app['env'] = 'testing';
    }
}
