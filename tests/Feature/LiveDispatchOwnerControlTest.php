<?php

namespace Tests\Feature;

use App\Actions\Fulfillment\SyncFulfillmentState;
use App\Actions\Integrations\ToggleIntegrationChannel;
use App\Console\Commands\StagingReadinessCommand;
use App\Enums\FulfillmentStatus;
use App\Enums\IntegrationProvider;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Pages\ManageIntegrationSettings;
use App\Jobs\SyncFulfillmentStatus;
use App\Models\AdminAuditLog;
use App\Models\FulfillmentMapping;
use App\Models\FulfillmentOrder;
use App\Models\IntegrationSetting;
use App\Models\Order;
use App\Models\User;
use App\Services\Fulfillment\FulfillmentDispatchGate;
use App\Services\Integrations\ProviderEndpoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * M4C R1: the Owner's auto-dispatch master switch, and what it cannot do.
 *
 * Owner 於 2026-08-24 明確推翻初版「自動派單另需 code 批准」:總開關與付款、
 * 發票同一個後台、同一套規則。⛔ 這一檔要同時證明兩件相反的事:
 *
 *   1. Owner 真的能自己開關,開了之後「總開關＋mapping＋可信付款」三者同時
 *      成立才派新訂單;
 *   2. 那個開關沒有順手變成別的東西——切換本身 0 外呼、0 訂單、0 mapping
 *      改寫、0 queue job;開啟不補派任何歷史訂單;關閉後已排入的 job 在
 *      網路前停止;端點仍是版本控制的 exact allowlist;API Key 仍只顯示遮罩。
 *
 * ⛔ 全程 `Http::preventStrayRequests()`:任何外部呼叫都會讓測試失敗。
 * ⛔ supported runtime 一律明確描述:這台機器的 libcurl 其實不支援。
 */
class LiveDispatchOwnerControlTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    private const KEY_MARKER = 'FAKE-DISPATCH-KEY-MARKER-R1-9911';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function toggle(): ToggleIntegrationChannel
    {
        return app(ToggleIntegrationChannel::class);
    }

    /** credential 齊全但 Owner 還沒開;API Key 用明顯假造的 marker。 */
    private function configuredDispatchRow(): IntegrationSetting
    {
        $setting = $this->configureChannelWithoutEnabling(IntegrationProvider::TheMostPanel);
        $setting->credentials = ['ApiKey' => self::KEY_MARKER];
        $setting->save();

        return $setting->fresh();
    }

    // ==================================== 1. Owner 的開關真的有作用

    /**
     * 核心:填完 API Key → 後台按啟用 → gate 成立。
     *
     * ⛔ 全程不改 `.env`、不改 code、不重新部署——舊 flags 維持 default false
     * 也擋不住,因為它們已完全不被讀取。
     */
    public function test_the_owner_can_arm_dispatch_from_the_admin_alone(): void
    {
        $this->withSupportedDispatchRuntime();
        $this->configuredDispatchRow();

        $this->assertFalse(FulfillmentDispatchGate::enabled());

        $this->assertTrue($this->toggle()->handle(IntegrationProvider::TheMostPanel, true));

        $this->runningAsLiveSite();
        $this->assertTrue(FulfillmentDispatchGate::enabled());
    }

    /** ⛔ 停用立即生效,gate 立刻變 false。 */
    public function test_disabling_takes_effect_immediately(): void
    {
        $this->withSupportedDispatchRuntime();
        $this->enableDispatchSwitch();
        $this->runningAsLiveSite();
        $this->assertTrue(FulfillmentDispatchGate::enabled());

        $this->assertFalse($this->toggle()->handle(IntegrationProvider::TheMostPanel, false));

        $this->assertFalse(FulfillmentDispatchGate::enabled());
    }

    /** ⛔ 停用永遠允許——credential 已損壞也一樣。 */
    public function test_disabling_works_even_with_corrupt_ciphertext(): void
    {
        $setting = $this->enableDispatchSwitch();

        DB::table('integration_settings')->where('id', $setting->id)
            ->update(['credentials' => 'corrupt-not-a-ciphertext']);

        $this->assertFalse($this->toggle()->handle(IntegrationProvider::TheMostPanel, false));
        $this->assertFalse((bool) DB::table('integration_settings')->where('id', $setting->id)->value('is_enabled'));
    }

    // ==================================== 2. 開啟的前提條件

    /** ⛔ 缺 API Key:拒絕,訊息點名欄位。 */
    public function test_enabling_without_a_key_is_refused(): void
    {
        $this->withSupportedDispatchRuntime();
        IntegrationSetting::factory()->forProvider(IntegrationProvider::TheMostPanel)->create();

        try {
            $this->toggle()->handle(IntegrationProvider::TheMostPanel, true);
            $this->fail('缺 API Key 不應該可以啟用');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('API Key', $e->validator->errors()->first());
        }
    }

    /** ⛔ 壞 ciphertext:拒絕,白話訊息,0 值外洩。 */
    public function test_enabling_with_corrupt_ciphertext_is_refused_plainly(): void
    {
        $this->withSupportedDispatchRuntime();
        $setting = $this->configuredDispatchRow();

        DB::table('integration_settings')->where('id', $setting->id)
            ->update(['credentials' => 'corrupt-not-a-ciphertext']);

        try {
            $this->toggle()->handle(IntegrationProvider::TheMostPanel, true);
            $this->fail('壞密文不應該可以啟用');
        } catch (ValidationException $e) {
            $message = $e->validator->errors()->first();
            $this->assertStringContainsString('無法讀取', $message);
            // ⛔ 不含密文本身,也不含 exception 細節。
            $this->assertStringNotContainsString('corrupt-not-a-ciphertext', $message);
            $this->assertStringNotContainsString('payload', $message);
        }

        $this->assertFalse((bool) $setting->fresh()->is_enabled);
    }

    /** ⛔ 端點設定被竄改:拒絕開啟。 */
    public function test_enabling_with_a_tampered_endpoint_is_refused(): void
    {
        $this->withSupportedDispatchRuntime();
        $this->configuredDispatchRow();
        config()->set('integrations.endpoints.themostpanel.production', 'https://evil.invalid/api');

        try {
            $this->toggle()->handle(IntegrationProvider::TheMostPanel, true);
            $this->fail('端點不符白名單不應該可以啟用');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('端點', $e->validator->errors()->first());
            // ⛔ 訊息不回顯被竄改的網址。
            $this->assertStringNotContainsString('evil.invalid', $e->validator->errors()->first());
        }
    }

    /** ⛔ runtime 不支援:拒絕開啟,顯示「主機環境不支援」。 */
    public function test_enabling_on_an_unsupported_runtime_is_refused(): void
    {
        $this->withUnsupportedDispatchRuntime();
        $this->configuredDispatchRow();

        try {
            $this->toggle()->handle(IntegrationProvider::TheMostPanel, true);
            $this->fail('runtime 不支援不應該可以啟用');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('主機環境不支援', $e->validator->errors()->first());
        }
    }

    // ==================================== 3. 切換本身零副作用

    /**
     * ⛔ 開/關 action 本身:0 外呼、0 訂單、0 履約列/事件、0 queue job、
     * mapping 一個 byte 都不動。
     *
     * 總開關不是「把所有 mapping 打開」的巨集——它只寫自己那一列。
     */
    public function test_toggling_has_zero_side_effects(): void
    {
        Queue::fake();
        Http::fake();
        $this->withSupportedDispatchRuntime();
        $this->configuredDispatchRow();

        FulfillmentMapping::factory()->enabled()->create();
        FulfillmentMapping::factory()->create(); // disabled

        $mappingsBefore = hash('sha256', DB::table('fulfillment_mappings')->orderBy('id')->get()->toJson());

        $this->toggle()->handle(IntegrationProvider::TheMostPanel, true);
        $this->toggle()->handle(IntegrationProvider::TheMostPanel, false);

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame(0, Order::count());
        $this->assertSame(0, FulfillmentOrder::count());
        $this->assertSame(0, DB::table('fulfillment_events')->count());
        $this->assertSame(
            $mappingsBefore,
            hash('sha256', DB::table('fulfillment_mappings')->orderBy('id')->get()->toJson()),
            '⛔ 總開關不得改寫任何 mapping',
        );
    }

    /** 切換寫入不含 secret 的稽核。 */
    public function test_dispatch_toggles_are_audited_without_the_key(): void
    {
        $this->withSupportedDispatchRuntime();
        $this->configuredDispatchRow();
        AdminAuditLog::query()->delete();

        $this->toggle()->handle(IntegrationProvider::TheMostPanel, true);
        $this->toggle()->handle(IntegrationProvider::TheMostPanel, false);

        $raw = AdminAuditLog::query()->get()->toJson();
        $this->assertSame(2, AdminAuditLog::count());
        $this->assertStringContainsString('is_enabled:on', $raw);
        $this->assertStringContainsString('is_enabled:off', $raw);
        $this->assertStringNotContainsString(self::KEY_MARKER, $raw);
        $this->assertStringNotContainsString('eyJpdiI6', $raw);
    }

    // ==================================== 4. 開啟不補派歷史訂單

    /**
     * ⛔ 開啟只影響其後新付款成功的訂單;任何既有狀態的歷史列都不被掃描、
     * 不被重送、不被改寫。
     *
     * 「開啟時順便把 pending 的補一補」看起來體貼,實際上是把一個開關變成
     * 一次不可預測的批次派單——包含那些等人工對帳的 submission_unknown,
     * 重送它們就是同一筆商品被下第二次單。
     */
    public function test_enabling_does_not_redispatch_any_historical_order(): void
    {
        Queue::fake();
        $this->withSupportedDispatchRuntime();
        $this->configuredDispatchRow();

        // 各種歷史狀態的履約列(合法轉移路徑建立)。
        $pending = FulfillmentOrder::factory()->create(); // configuration_pending
        $unknown = FulfillmentOrder::factory()->ready()->create();
        $unknown->forceFill(['status' => FulfillmentStatus::Submitting, 'attempt_count' => 1])->save();
        $unknown->forceFill(['status' => FulfillmentStatus::SubmissionUnknown])->save();
        $failed = FulfillmentOrder::factory()->submitted('77001')->create();
        $failed->forceFill(['status' => FulfillmentStatus::Failed])->save();
        $done = FulfillmentOrder::factory()->submitted('77002')->create();
        $done->forceFill(['status' => FulfillmentStatus::Completed])->save();

        // 一張已付款、但從未建立履約列的訂單。
        Order::factory()->create([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'total_amount' => 590,
            'paid_at' => now(),
        ]);

        $rowsBefore = DB::table('fulfillment_orders')->orderBy('id')->get()->toJson();

        $this->toggle()->handle(IntegrationProvider::TheMostPanel, true);

        // ⛔ 0 個 job、0 列改寫、0 新列。
        Queue::assertNothingPushed();
        $this->assertSame($rowsBefore, DB::table('fulfillment_orders')->orderBy('id')->get()->toJson());
        $this->assertSame(4, FulfillmentOrder::count());
        Http::assertNothingSent();
    }

    // ==================================== 5. 關閉後,已排入的 job 在網路前停止

    /** ⛔ 已排入、尚未執行的 status job:執行時再讀 gate,0 request、0 event。 */
    public function test_a_queued_status_job_stops_before_the_network_after_switch_off(): void
    {
        Http::fake();
        $this->withSupportedDispatchRuntime();
        $this->enableDispatchSwitch();
        config()->set('fulfillment.driver', 'fake');

        $row = FulfillmentOrder::factory()->submitted('88001')->create();
        $eventsBefore = DB::table('fulfillment_events')->count();

        // Owner 關掉開關;job 是在開著的時候排入的。
        DB::table('integration_settings')->where('provider', 'themostpanel')->update(['is_enabled' => false]);

        (new SyncFulfillmentStatus($row->id))->handle(app(SyncFulfillmentState::class));

        Http::assertNothingSent();
        $this->assertSame(FulfillmentStatus::Submitted, $row->fresh()->status);
        // ⛔ 靜默停止:不在 timeline 灌「讀不懂」。
        $this->assertSame($eventsBefore, DB::table('fulfillment_events')->count());
    }

    // ==================================== 6. 端點 allowlist

    /** ⛔ 每一個近似值都不是白名單裡的那一個字串,全部 fail closed。 */
    #[DataProvider('tamperedDispatchEndpointProvider')]
    public function test_a_tampered_dispatch_endpoint_fails_closed(string $value): void
    {
        config()->set('integrations.endpoints.themostpanel.production', $value);

        $this->assertNull(ProviderEndpoints::theMostPanelDispatch());
        $this->assertFalse(FulfillmentDispatchGate::liveCapable());
    }

    /** @return array<string, array{string}> */
    public static function tamperedDispatchEndpointProvider(): array
    {
        return [
            'blank' => [''],
            'http' => ['http://themostpanel.com/api/v2'],
            'lookalike host' => ['https://themostpanel.com.evil.example/api/v2'],
            'subdomain' => ['https://api.themostpanel.com/api/v2'],
            'arbitrary host' => ['https://evil.example/api/v2'],
            'userinfo' => ['https://user@themostpanel.com/api/v2'],
            'explicit port' => ['https://themostpanel.com:8443/api/v2'],
            'query' => ['https://themostpanel.com/api/v2?debug=1'],
            'fragment' => ['https://themostpanel.com/api/v2#f'],
            'trailing slash' => ['https://themostpanel.com/api/v2/'],
            'wrong path' => ['https://themostpanel.com/api/v1'],
            'uppercased path' => ['https://themostpanel.com/API/V2'],
        ];
    }

    /** 正向:版本控制的預設值恰好是官方端點。 */
    public function test_the_default_dispatch_endpoint_is_exactly_the_official_one(): void
    {
        $this->assertSame(
            'https://themostpanel.com/api/v2',
            ProviderEndpoints::theMostPanelDispatch(),
        );
    }

    // ==================================== 7. 後台顯示與遮罩

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    /** ⛔ API Key 只顯示固定遮罩;HTML 與 Livewire state 無真值。 */
    public function test_the_api_key_never_reaches_the_browser(): void
    {
        $this->configuredDispatchRow();

        $rendered = Livewire::actingAs($this->owner())->test(ManageIntegrationSettings::class);

        $this->assertStringNotContainsString(self::KEY_MARKER, $rendered->html());
        $this->assertStringContainsString(ManageIntegrationSettings::MASK, $rendered->html());
        $this->assertStringNotContainsString(self::KEY_MARKER, json_encode($rendered->get('data')));
    }

    /** 後台卡片:TheMostPanel 現在有自動派單總開關,可從頁面切換。 */
    public function test_the_admin_page_can_toggle_dispatch(): void
    {
        $this->withSupportedDispatchRuntime();
        $this->configuredDispatchRow();

        $page = Livewire::actingAs($this->owner())->test(ManageIntegrationSettings::class);

        $this->assertStringContainsString('自動派單總開關', $page->html());

        $page->call('toggleChannel', IntegrationProvider::TheMostPanel->value, true);
        $this->assertTrue((bool) DB::table('integration_settings')->where('provider', 'themostpanel')->value('is_enabled'));

        $page->call('toggleChannel', IntegrationProvider::TheMostPanel->value, false);
        $this->assertFalse((bool) DB::table('integration_settings')->where('provider', 'themostpanel')->value('is_enabled'));
    }

    /** runtime 不支援:卡片以白話顯示「主機環境不支援」,不顯示技術細節。 */
    public function test_the_admin_page_explains_an_unsupported_runtime_plainly(): void
    {
        $this->withUnsupportedDispatchRuntime();
        $this->configuredDispatchRow();

        $html = Livewire::actingAs($this->owner())->test(ManageIntegrationSettings::class)->html();

        $this->assertStringContainsString('主機環境不支援', $html);
        // ⛔ 沒有 raw exception、config 值或 provider 回應。
        $this->assertStringNotContainsString('Exception', $html);
        $this->assertStringNotContainsString('curl_version', $html);
    }

    /** ⛔ 非 Owner 依然一律擋在門外(繞過畫面直接呼叫)。 */
    public function test_a_non_owner_cannot_toggle_dispatch(): void
    {
        $this->withSupportedDispatchRuntime();
        $this->configuredDispatchRow();

        $editor = User::factory()->create(['role' => 'editor', 'is_active' => true]);
        $this->actingAs($editor);

        $page = new ManageIntegrationSettings;

        try {
            $page->toggleChannel(IntegrationProvider::TheMostPanel->value, true, $this->toggle());
            $this->fail('editor 不應該能切換自動派單');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertFalse((bool) DB::table('integration_settings')->where('provider', 'themostpanel')->value('is_enabled'));
    }

    // ==================================== 8. readiness 與 gate 同源

    /** readiness 的 dispatch 逐項回報與 gate 狀態一致(staging)。 */
    public function test_readiness_reports_dispatch_in_step_with_the_gate(): void
    {
        $this->withSupportedDispatchRuntime();
        $this->app['env'] = 'staging';

        // 1. 沒有開關:blocked。
        $checks = collect(StagingReadinessCommand::report()['checks']);
        $this->assertSame('blocked', $checks->firstWhere('key', 'themostpanel_dispatch')['status']);
        $this->assertStringContainsString('owner_switch=off', $checks->firstWhere('key', 'themostpanel_dispatch')['value']);
        $this->assertSame('not enabled', $checks->firstWhere('key', 'status_polling')['value']);

        // 2. Owner 開了:ok,live=yes;輪詢同步 enabled。
        $this->enableDispatchSwitch();
        $checks = collect(StagingReadinessCommand::report()['checks']);
        $this->assertSame('ok', $checks->firstWhere('key', 'themostpanel_dispatch')['status']);
        $this->assertStringContainsString('live=yes', $checks->firstWhere('key', 'themostpanel_dispatch')['value']);
        $this->assertSame('enabled', $checks->firstWhere('key', 'status_polling')['value']);
        $this->assertTrue(FulfillmentDispatchGate::enabled());

        // 3. 端點被竄改:endpoint 列 blocker,live 回到 no。
        config()->set('integrations.endpoints.themostpanel.production', 'https://evil.invalid/api');
        $checks = collect(StagingReadinessCommand::report()['checks']);
        $this->assertSame('blocker', $checks->firstWhere('key', 'themostpanel_endpoint')['status']);
        $this->assertStringContainsString('live=no', $checks->firstWhere('key', 'themostpanel_dispatch')['value']);

        $this->app['env'] = 'testing';
    }
}
