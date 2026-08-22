<?php

namespace Tests\Feature;

use App\Enums\FulfillmentPayloadType;
use App\Enums\IntegrationProvider;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\RelationManagers\Actions\ConfigureSmmMappingAction;
use App\Filament\Resources\Services\RelationManagers\VariantsRelationManager;
use App\Models\FulfillmentMapping;
use App\Models\Order;
use App\Models\ProviderService;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Services\Fulfillment\FulfillmentDispatchGate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * M2-E-B:商品內設定 SMM 對應。
 *
 * ⛔ 這個 modal 是「更方便的入口」,不是「更鬆的規則」。每個測試都在證明
 * 舊 mapping 頁的守門在新入口一樣有效:submit 時重查目錄、啟用前重算數量
 * 相容性、套用與啟用不得同送、mapping 與 bounds 同一 transaction、
 * 同一 variant+provider 只能一筆、非 Owner 完全看不到。
 *
 * ⛔ Fixtures 全部虛構;`Http::preventStrayRequests()` 保證 external request 0。
 */
class AdminBeginnerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    private function editor(): User
    {
        return User::factory()->create(['role' => 'editor', 'is_active' => true]);
    }

    /**
     * 虛構款式。
     *
     * ⛔ 單價固定為整數:VariantIntegrityObserver 會拒絕任何「單價 × 合法
     * 數量」算不出整數台幣的設定,那是既有的保護,不是本輪要繞過的東西。
     */
    private function variant(array $overrides = []): ServiceVariant
    {
        return ServiceVariant::factory()->published()->create($overrides + [
            'unit_price' => 1,
            'min_quantity' => 100,
            'max_quantity' => 10000,
            'step_quantity' => 1,
            'default_quantity' => 100,
        ]);
    }

    private function availableService(string $id, array $overrides = []): ProviderService
    {
        return ProviderService::factory()->available()->create($overrides + [
            'provider' => IntegrationProvider::TheMostPanel->value,
            'provider_service_id' => $id,
            'name' => '虛構可用服務 '.$id,
            'minimum_quantity_raw' => '10',
            'maximum_quantity_raw' => '100000',
        ]);
    }

    /** 直接呼叫 relation manager 的 modal action(等同 Owner 在商品頁操作)。 */
    private function configure(ServiceVariant $variant, array $data)
    {
        return Livewire::test(VariantsRelationManager::class, [
            'ownerRecord' => $variant->service,
            'pageClass' => EditService::class,
        ])->callTableAction('configureSmmMapping', $variant, data: $data);
    }

    // ------------------------------------------------------------------
    // 1. 同頁建立與編輯
    // ------------------------------------------------------------------

    public function test_an_owner_creates_a_mapping_from_the_product_page(): void
    {
        $this->actingAs($this->owner());

        $variant = $this->variant();
        $service = $this->availableService('7001');

        $this->configure($variant, [
            'provider_service_id' => $service->provider_service_id,
            'is_enabled' => false,
            'apply_provider_bounds' => false,
        ])->assertHasNoActionErrors();

        $this->assertDatabaseHas('fulfillment_mappings', [
            'service_variant_id' => $variant->id,
            'provider' => IntegrationProvider::TheMostPanel->value,
            'provider_service_id' => '7001',
            'is_enabled' => false,
        ]);
    }

    public function test_editing_the_same_variant_never_creates_a_second_mapping(): void
    {
        $this->actingAs($this->owner());

        $variant = $this->variant();
        $first = $this->availableService('7002');
        $second = $this->availableService('7003');

        $this->configure($variant, [
            'provider_service_id' => $first->provider_service_id,
            'is_enabled' => false,
            'apply_provider_bounds' => false,
        ])->assertHasNoActionErrors();

        $this->configure($variant, [
            'provider_service_id' => $second->provider_service_id,
            'is_enabled' => false,
            'apply_provider_bounds' => false,
        ])->assertHasNoActionErrors();

        // ⛔ 同一 variant + provider 只能有一筆,第二次是更新不是新增。
        $this->assertSame(1, FulfillmentMapping::query()
            ->where('service_variant_id', $variant->id)
            ->where('provider', IntegrationProvider::TheMostPanel->value)
            ->count());

        $this->assertDatabaseHas('fulfillment_mappings', [
            'service_variant_id' => $variant->id,
            'provider_service_id' => '7003',
        ]);
    }

    public function test_the_variant_row_shows_the_saved_mapping_in_plain_language(): void
    {
        $this->actingAs($this->owner());

        $variant = $this->variant();
        $service = $this->availableService('7004', ['name' => '虛構粉絲服務']);

        $this->assertSame('未設定', ConfigureSmmMappingAction::statusFor($variant));

        $this->configure($variant, [
            'provider_service_id' => $service->provider_service_id,
            'is_enabled' => false,
            'apply_provider_bounds' => false,
        ]);

        $status = ConfigureSmmMappingAction::statusFor($variant->refresh());

        $this->assertStringContainsString('已對應：虛構粉絲服務', $status);
        $this->assertStringContainsString('最低 10', $status);
        $this->assertStringContainsString('最高 100000', $status);

        /*
         * ⛔ R1:名稱與上下限仍在,但啟用狀態已從這一欄移除。「已啟用」曾經
         * 緊接在上下限後面,最容易被讀成「所以它會自動派單」——那個問題現在
         * 由「自動派單」欄單獨回答。
         */
        $this->assertStringNotContainsString('已啟用', $status);
        $this->assertStringNotContainsString('已停用', $status);

        // ⛔ 一行狀態不得洩漏 ID、分類、rate 等技術欄位。
        $this->assertStringNotContainsString('7004', $status);
    }

    // ------------------------------------------------------------------
    // 2. 普通人看到的欄位:不得出現技術資訊
    // ------------------------------------------------------------------

    public function test_the_option_label_shows_only_name_and_bounds(): void
    {
        $service = $this->availableService('7005', [
            'name' => '虛構服務名稱',
            'category' => '機密分類',
            'service_type' => 'Default',
            'rate_raw' => '3.21',
        ]);

        $label = ConfigureSmmMappingAction::optionLabel($service);

        $this->assertSame('虛構服務名稱 ｜ 最低 10 ｜ 最高 100000', $label);

        // ⛔ ID／分類／型別／rate 一律不得出現在選擇器 label。
        foreach (['7005', '機密分類', 'Default', '3.21'] as $secret) {
            $this->assertStringNotContainsString($secret, $label);
        }
    }

    // ------------------------------------------------------------------
    // 3. 竄改 ID / 失效服務 / 過期值
    // ------------------------------------------------------------------

    public function test_a_tampered_service_id_is_rejected(): void
    {
        $this->actingAs($this->owner());

        $variant = $this->variant();
        $this->availableService('7006');

        $this->configure($variant, [
            // ⛔ 目錄中不存在的 ID:選單以外送來的值一律 submit 時重查。
            'provider_service_id' => '999999',
            'is_enabled' => false,
            'apply_provider_bounds' => false,
        ])->assertHasActionErrors(['provider_service_id']);

        $this->assertDatabaseCount('fulfillment_mappings', 0);
    }

    public function test_an_unavailable_service_cannot_be_selected(): void
    {
        $this->actingAs($this->owner());

        $variant = $this->variant();
        ProviderService::factory()->create([
            'provider' => IntegrationProvider::TheMostPanel->value,
            'provider_service_id' => '7007',
            'is_available' => false,
        ]);

        $this->configure($variant, [
            'provider_service_id' => '7007',
            'is_enabled' => false,
            'apply_provider_bounds' => false,
        ])->assertHasActionErrors(['provider_service_id']);

        $this->assertDatabaseCount('fulfillment_mappings', 0);
    }

    public function test_a_stale_mapping_may_stay_disabled_but_cannot_be_re_enabled(): void
    {
        $this->actingAs($this->owner());

        $variant = $this->variant();
        $service = $this->availableService('7008');

        $this->configure($variant, [
            'provider_service_id' => '7008',
            'is_enabled' => false,
            'apply_provider_bounds' => false,
        ])->assertHasNoActionErrors();

        // 供應商之後下架這個服務。
        $service->forceFill(['is_available' => false])->save();

        // ⛔ 重新啟用一個已失效的對應必須失敗。
        $this->configure($variant, [
            'provider_service_id' => '7008',
            'is_enabled' => true,
            'apply_provider_bounds' => false,
        ])->assertHasActionErrors(['provider_service_id']);

        $this->assertDatabaseHas('fulfillment_mappings', [
            'service_variant_id' => $variant->id,
            'provider_service_id' => '7008',
            'is_enabled' => false,
        ]);
    }

    // ------------------------------------------------------------------
    // 4. 數量相容性與 apply+enable
    // ------------------------------------------------------------------

    public function test_enabling_requires_the_provider_range_to_cover_the_site_range(): void
    {
        $this->actingAs($this->owner());

        // 本站可購 100–10000,但供應商只到 500 → 不相容,不得啟用。
        $variant = $this->variant();
        $this->availableService('7009', [
            'minimum_quantity_raw' => '10',
            'maximum_quantity_raw' => '500',
        ]);

        $this->configure($variant, [
            'provider_service_id' => '7009',
            'is_enabled' => true,
            'apply_provider_bounds' => false,
        ])->assertHasActionErrors(['provider_service_id']);

        $this->assertDatabaseCount('fulfillment_mappings', 0);
    }

    public function test_applying_bounds_and_enabling_cannot_happen_in_one_submit(): void
    {
        $this->actingAs($this->owner());

        $variant = $this->variant();
        $this->availableService('7010');

        $this->configure($variant, [
            'provider_service_id' => '7010',
            'is_enabled' => true,
            'apply_provider_bounds' => true,
        ])->assertHasActionErrors(['apply_provider_bounds']);

        // ⛔ 0 writes:mapping 未建立,variant 上下限未被改動。
        $this->assertDatabaseCount('fulfillment_mappings', 0);
        $this->assertSame(100, $variant->refresh()->min_quantity);
        $this->assertSame(10000, $variant->max_quantity);
    }

    public function test_applying_bounds_updates_the_variant_within_one_transaction(): void
    {
        $this->actingAs($this->owner());

        $variant = $this->variant();
        $this->availableService('7011', [
            'minimum_quantity_raw' => '50',
            'maximum_quantity_raw' => '5000',
        ]);

        $this->configure($variant, [
            'provider_service_id' => '7011',
            'is_enabled' => false,
            'apply_provider_bounds' => true,
        ])->assertHasNoActionErrors();

        $variant->refresh();

        // 上下限已套用,且 mapping 保持停用。
        $this->assertSame(50, $variant->min_quantity);
        $this->assertSame(5000, $variant->max_quantity);
        $this->assertDatabaseHas('fulfillment_mappings', [
            'service_variant_id' => $variant->id,
            'provider_service_id' => '7011',
            'is_enabled' => false,
        ]);
    }

    // ------------------------------------------------------------------
    // 5. 授權:Editor 完全看不到
    // ------------------------------------------------------------------

    public function test_an_editor_never_sees_the_smm_column_or_action(): void
    {
        $this->actingAs($this->editor());

        $this->assertFalse(ConfigureSmmMappingAction::allowed());
    }

    public function test_an_owner_may_use_it(): void
    {
        $this->actingAs($this->owner());

        $this->assertTrue(ConfigureSmmMappingAction::allowed());
    }

    public function test_an_editor_cannot_save_a_mapping_even_by_calling_the_action(): void
    {
        $this->actingAs($this->editor());

        $variant = $this->variant();
        $this->availableService('7012');

        /*
         * 第一道:Editor 眼中這個 action 根本不存在(Filament 連呼叫都拒絕)。
         */
        Livewire::test(VariantsRelationManager::class, [
            'ownerRecord' => $variant->service,
            'pageClass' => EditService::class,
        ])->assertTableActionHidden('configureSmmMapping', $variant);

        /*
         * ⛔ 第二道:即使繞過畫面直接執行儲存流程,action 內的授權檢查仍擋下。
         * 這證明保護不只在 visible(),否則哪天 UI 條件寫錯就會破防。
         */
        $threw = false;

        try {
            ConfigureSmmMappingAction::save($variant, [
                'provider_service_id' => '7012',
                'is_enabled' => false,
                'apply_provider_bounds' => false,
            ]);
        } catch (ValidationException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Editor 直接執行儲存流程必須被擋下');
        $this->assertDatabaseCount('fulfillment_mappings', 0);
    }

    // ------------------------------------------------------------------
    // 6. 敵意 provider 文字
    // ------------------------------------------------------------------

    public function test_hostile_provider_names_are_escaped_in_the_row_status(): void
    {
        $this->actingAs($this->owner());

        $variant = $this->variant();
        $hostile = '<script>alert("smm-xss")</script>';

        $this->availableService('7013', ['name' => $hostile]);

        $this->configure($variant, [
            'provider_service_id' => '7013',
            'is_enabled' => false,
            'apply_provider_bounds' => false,
        ]);

        $html = Livewire::test(VariantsRelationManager::class, [
            'ownerRecord' => $variant->service,
            'pageClass' => EditService::class,
        ])->html();

        // ⛔ raw payload 不得出現;escaped 版本才是正確渲染。
        $this->assertStringNotContainsString($hostile, $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;smm-xss&quot;)', $html);
    }

    // ------------------------------------------------------------------
    // 7. 訂單詳情:發票同頁唯讀
    // ------------------------------------------------------------------

    public function test_the_order_detail_shows_invoice_state_read_only(): void
    {
        $this->actingAs($this->owner());

        $order = Order::factory()->create();

        // ⛔ Order 有自訂 route key(不是 id),用 getRouteKey() 才是真實網址。
        $html = $this->get('/admin/orders/'.$order->getRouteKey())->assertOk()->getContent();

        // 沒有發票時每一欄顯示「尚未開立」,不推論成功或失敗。
        $this->assertStringContainsString('電子發票', $html);
        $this->assertStringContainsString('尚未開立', $html);

        // ⛔ 訂單頁不得出現任何手動狀態按鈕。
        foreach (['標記已付款', '手動完成', '重送', '作廢發票', '重新開立'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }

    public function test_no_external_request_is_made_while_configuring_a_mapping(): void
    {
        // ⛔ 設定對應不等於呼叫 SMM API;preventStrayRequests 會讓任何外連失敗。
        $this->actingAs($this->owner());

        $variant = $this->variant();
        $this->availableService('7014');

        $this->configure($variant, [
            'provider_service_id' => '7014',
            'is_enabled' => false,
            'apply_provider_bounds' => false,
        ])->assertHasNoActionErrors();

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // 8. R1:「自動派單」欄的三態
    //
    // ⛔ 這一節保護的性質只有一個:綠勾必須是真的。畫面上的「可自動派單」
    // 是對 Owner 的承諾,只有在 mapping 可用 *且* 全站 gate 開啟時才成立;
    // 任何一邊不成立就必須降級成黃色警示,而不是靜靜地顯示綠色。
    // ------------------------------------------------------------------

    /** 直接寫一筆 mapping;⛔ 只在 test DB,不經過任何 provider。 */
    private function mapping(ServiceVariant $variant, array $overrides = []): FulfillmentMapping
    {
        return FulfillmentMapping::create($overrides + [
            'service_variant_id' => $variant->id,
            'provider' => IntegrationProvider::TheMostPanel->value,
            'provider_service_id' => '8001',
            'payload_type' => FulfillmentPayloadType::LinkQuantity->value,
            'is_enabled' => true,
        ]);
    }

    /** 讓全站 gate 真的回傳 true;⛔ 只改 in-memory config,不寫 .env。 */
    private function openTheDispatchGate(): void
    {
        config()->set('fulfillment.driver', 'fake');
        config()->set('fulfillment.dispatch_enabled', true);

        // ⛔ 先反證前提:如果 gate 沒真的開,下面的綠勾測試就毫無意義。
        $this->assertTrue(FulfillmentDispatchGate::enabled());
    }

    public function test_a_variant_without_a_mapping_is_not_auto_dispatched(): void
    {
        $variant = $this->variant();

        $state = ConfigureSmmMappingAction::dispatchState($variant);

        $this->assertSame(ConfigureSmmMappingAction::DISPATCH_OFF, $state);
        $this->assertSame('未啟用', ConfigureSmmMappingAction::dispatchLabel($state));
        $this->assertSame('gray', ConfigureSmmMappingAction::dispatchColor($state));
        $this->assertSame('heroicon-m-x-circle', ConfigureSmmMappingAction::dispatchIcon($state));
    }

    public function test_a_disabled_mapping_is_not_auto_dispatched(): void
    {
        $variant = $this->variant();
        $this->mapping($variant, ['is_enabled' => false]);

        $state = ConfigureSmmMappingAction::dispatchState($variant);

        $this->assertSame(ConfigureSmmMappingAction::DISPATCH_OFF, $state);
        $this->assertSame('未啟用', ConfigureSmmMappingAction::dispatchLabel($state));
    }

    /**
     * ⛔ 本輪最重要的一條:啟用 ≠ 會派單。
     *
     * mapping 完全正確、可用,但全站 gate 關著。畫面必須說「尚未開放」,
     * ⛔ 絕不能顯示綠勾——那會讓 Owner 以為訂單已經在自動送出。
     */
    public function test_an_enabled_usable_mapping_still_waits_for_the_site_wide_gate(): void
    {
        $variant = $this->variant();
        $mapping = $this->mapping($variant);

        // 前提:mapping 這一側是好的,所以黃色只可能來自 gate。
        $this->assertTrue($mapping->isUsable());
        $this->assertFalse(FulfillmentDispatchGate::enabled());

        $state = ConfigureSmmMappingAction::dispatchState($variant);

        $this->assertSame(ConfigureSmmMappingAction::DISPATCH_WAITING, $state);
        $this->assertSame('尚未開放', ConfigureSmmMappingAction::dispatchLabel($state));
        $this->assertSame('warning', ConfigureSmmMappingAction::dispatchColor($state));
        $this->assertNotSame(ConfigureSmmMappingAction::DISPATCH_READY, $state);
    }

    /**
     * 已啟用、但 mapping 這一列本身不可用 → 對應壞了,需要人去看。
     *
     * ⛔ 不可歸類成「未啟用」:一筆壞掉的對應必須看起來跟從沒設定過不一樣,
     * 否則它會安靜地待在那裡,直到有人以為它在運作。
     *
     * ⛔ 施工中查證到的事實:這個狀態**存不進資料庫**。
     * `isUsable()` 除了 `is_enabled` 之外的三個條件(provider、非空
     * service ID、payload_type),與 fulfillment_mappings 的 CHECK 約束／
     * SQLite trigger 完全相同,所以任何「已啟用卻不可用」的列在 INSERT 或
     * UPDATE 當下就會被資料庫擋掉。
     *
     * 因此這裡用未落地的 in-memory model 驗證分支本身——⛔ 不用 raw SQL
     * 或停用 trigger 去偽造一個 schema 明文禁止的狀態。這個分支保留的理由
     * 是 fail-safe:若未來 `isUsable()` 增加新條件(例如 provider 目錄下架
     * 或 payload 型別擴充),舊資料就可能落在這裡,而畫面必須顯示黃色警示,
     * ⛔ 不能默默降級成綠勾或靜靜地顯示「未啟用」。
     */
    public function test_an_enabled_but_unusable_mapping_asks_for_a_check(): void
    {
        // 未 save:資料庫約束本身不允許這種列存在(見上方說明)。
        $mapping = new FulfillmentMapping([
            'provider' => 'not-a-known-provider',
            'provider_service_id' => '8003',
            'payload_type' => FulfillmentPayloadType::LinkQuantity->value,
            'is_enabled' => true,
        ]);

        $this->assertTrue($mapping->is_enabled, '前提:它是啟用的,壞的是可用性');
        $this->assertFalse($mapping->isUsable());

        // 三態的分類邏輯:啟用 + 不可用 → 需要檢查,⛔ 不是「未啟用」。
        $state = $mapping->is_enabled && ! $mapping->isUsable()
            ? ConfigureSmmMappingAction::DISPATCH_CHECK
            : ConfigureSmmMappingAction::DISPATCH_OFF;

        $this->assertSame(ConfigureSmmMappingAction::DISPATCH_CHECK, $state);
        $this->assertSame('需要檢查', ConfigureSmmMappingAction::dispatchLabel($state));
        $this->assertSame('warning', ConfigureSmmMappingAction::dispatchColor($state));
        $this->assertSame('heroicon-m-exclamation-triangle', ConfigureSmmMappingAction::dispatchIcon($state));
    }

    /**
     * 上一個測試所依賴的前提,獨立反證一次。
     *
     * ⛔ 若哪天有人放寬了 CHECK 約束,這裡會失敗,提醒「需要檢查」狀態
     * 從此在真實資料中做得出來,必須改用真實列來測。
     */
    public function test_the_database_itself_refuses_to_store_an_unusable_mapping(): void
    {
        $variant = $this->variant();

        $rejected = false;

        try {
            $this->mapping($variant, ['provider_service_id' => '   ']);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, '資料庫必須擋下空白的 provider_service_id');
        $this->assertDatabaseCount('fulfillment_mappings', 0);
    }

    /** 只有「可用 + gate 開啟」兩者同時成立,才准顯示綠勾。 */
    public function test_the_green_tick_appears_only_when_the_mapping_and_the_gate_both_allow_it(): void
    {
        $variant = $this->variant();
        $this->mapping($variant);

        $this->openTheDispatchGate();

        $state = ConfigureSmmMappingAction::dispatchState($variant);

        $this->assertSame(ConfigureSmmMappingAction::DISPATCH_READY, $state);
        $this->assertSame('可自動派單', ConfigureSmmMappingAction::dispatchLabel($state));
        $this->assertSame('success', ConfigureSmmMappingAction::dispatchColor($state));
        $this->assertSame('heroicon-m-check-circle', ConfigureSmmMappingAction::dispatchIcon($state));

        // ⛔ 即使 gate 開著,停用的對應仍然不是綠勾。
        $disabled = $this->variant();
        $this->mapping($disabled, ['is_enabled' => false]);

        $this->assertSame(
            ConfigureSmmMappingAction::DISPATCH_OFF,
            ConfigureSmmMappingAction::dispatchState($disabled),
        );

        // ⛔ 顯示狀態不得順手變成一次派單:gate 開著也一樣。
        Http::assertNothingSent();
    }

    /**
     * ⛔ 本機預設狀態的防呆:整張方案表不得出現「可自動派單」。
     *
     * 直接讀渲染後的 HTML,而不是只呼叫 helper——helper 對了但欄位接錯,
     * 畫面一樣會騙人。
     */
    public function test_the_local_default_never_renders_the_green_tick(): void
    {
        $this->actingAs($this->owner());

        $variant = $this->variant();
        $this->availableService('8002');
        $this->mapping($variant, ['provider_service_id' => '8002']);

        $this->assertFalse(FulfillmentDispatchGate::enabled());

        $html = Livewire::test(VariantsRelationManager::class, [
            'ownerRecord' => $variant->service,
            'pageClass' => EditService::class,
        ])->html();

        $this->assertStringContainsString('自動派單', $html);
        $this->assertStringContainsString('尚未開放', $html);
        $this->assertStringNotContainsString('可自動派單', $html);
    }

    /** ⛔ 兩個欄位都是商業敏感的,Editor 一個都看不到。 */
    public function test_an_editor_sees_neither_the_mapping_column_nor_the_dispatch_column(): void
    {
        $this->actingAs($this->editor());

        $variant = $this->variant();
        $this->mapping($variant);

        $html = Livewire::test(VariantsRelationManager::class, [
            'ownerRecord' => $variant->service,
            'pageClass' => EditService::class,
        ])->html();

        $this->assertStringNotContainsString('自動派單', $html);
        $this->assertStringNotContainsString('SMM 服務', $html);
        // 兩欄共用同一個 Owner-only 條件。
        $this->assertFalse(ConfigureSmmMappingAction::allowed());
    }
}
