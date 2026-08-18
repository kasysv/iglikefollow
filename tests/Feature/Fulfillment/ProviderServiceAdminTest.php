<?php

namespace Tests\Feature\Fulfillment;

use App\Filament\Resources\ProviderServices\Pages\ListProviderServices;
use App\Models\ProviderService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The catalog page: Owner-only, read-only, escaped, honestly labelled.
 */
class ProviderServiceAdminTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/provider-services';

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

    public function test_a_guest_is_redirected(): void
    {
        $this->get(self::URL)->assertRedirect();
    }

    public function test_an_owner_reaches_the_catalog(): void
    {
        $this->actingAs($this->owner())->get(self::URL)->assertOk();
    }

    public function test_an_editor_is_forbidden(): void
    {
        // ⛔ 後端擋，不是只把選單藏起來。
        $this->actingAs($this->editor())->get(self::URL)->assertForbidden();
    }

    public function test_an_inactive_owner_is_locked_out(): void
    {
        $inactive = User::factory()->create(['role' => 'owner', 'is_active' => false]);

        $this->actingAs($inactive)->get(self::URL)->assertForbidden();
    }

    /** ⛔ 後台頁面一律 noindex：它絕不能出現在搜尋結果裡。 */
    public function test_the_page_is_noindex(): void
    {
        $this->actingAs($this->owner())
            ->get(self::URL)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_the_rate_warning_and_empty_copy_are_visible_before_any_sync(): void
    {
        $response = $this->actingAs($this->owner())->get(self::URL);

        $response->assertOk();
        // ⛔ rate 警語常駐;沒資料時仍是「尚未同步」而非「帳戶沒有服務」。
        $response->assertSee('不是本站售價');
        $response->assertSee('尚未同步');
        $response->assertSee('不代表帳戶沒有服務');
    }

    /**
     * ⛔ B4-C-C-B 之後「尚未同步真實帳戶」是錯誤陳述:有資料時說明改為
     * 安全事實(row count＋最後觀察時間),rate 警語仍常駐。
     */
    public function test_an_observed_catalog_shows_the_count_and_last_seen_instead_of_not_synced(): void
    {
        ProviderService::factory()->count(3)->available()->create();
        ProviderService::query()->update(['last_seen_at' => '2026-08-18 12:49:15', 'first_seen_at' => '2026-08-18 12:49:15']);

        $response = $this->actingAs($this->owner())->get(self::URL);

        $response->assertOk();
        $response->assertSee('不是本站售價');
        $response->assertSee('本機最近成功觀察 3 筆服務');
        $response->assertSee('2026-08-18 12:49:15');
        // ⛔ 有資料時不得再宣稱尚未同步。
        $response->assertDontSee('尚未同步');
    }

    public function test_the_empty_state_says_not_synced_rather_than_no_services(): void
    {
        $this->assertSame(0, ProviderService::query()->count());

        $response = $this->actingAs($this->owner())->get(self::URL);

        $response->assertOk();
        $response->assertSee('尚未同步');
        // ⛔ 沒資料的正確解讀是「還沒同步」，不是「帳戶沒有服務」。
        $response->assertSee('不代表帳戶沒有服務');
    }

    public function test_an_owner_sees_the_business_fields(): void
    {
        ProviderService::factory()->create([
            'provider_service_id' => '9101',
            'name' => '虛構目錄服務',
            'rate_raw' => '0.90',
        ]);

        $response = $this->actingAs($this->owner())->get(self::URL);

        $response->assertOk();
        $response->assertSee('9101');
        $response->assertSee('虛構目錄服務');
        $response->assertSee('0.90');
    }

    /**
     * ⛔ Provider-controlled text renders as text, never as markup. A service
     * name is exactly where a hostile catalog would put a script tag.
     */
    public function test_provider_text_is_escaped(): void
    {
        ProviderService::factory()->create([
            'name' => '<script>alert("fictional-xss")</script>',
            'category' => '<img src=x onerror=alert(1)>',
        ]);

        $response = $this->actingAs($this->owner())->get(self::URL);

        $response->assertOk();
        // 原樣的 HTML 不得出現；escape 後的版本要在。
        $response->assertDontSee('<script>alert("fictional-xss")</script>', false);
        $response->assertDontSee('<img src=x onerror=alert(1)>', false);
        $response->assertSee('<script>alert("fictional-xss")</script>');
    }

    public function test_there_is_no_create_edit_view_or_delete_route(): void
    {
        $row = ProviderService::factory()->create();
        $owner = $this->owner();

        $this->actingAs($owner)->get(self::URL.'/create')->assertNotFound();
        $this->actingAs($owner)->get(self::URL.'/'.$row->id)->assertNotFound();
        $this->actingAs($owner)->get(self::URL.'/'.$row->id.'/edit')->assertNotFound();
    }

    public function test_no_sync_test_connection_or_export_control_exists(): void
    {
        ProviderService::factory()->create();

        $response = $this->actingAs($this->owner())->get(self::URL);

        $response->assertOk();
        /*
         * ⛔ 這些按鈕每一個都是在宣稱「按下去就觀察了供應商」，而 CATALOG-A
         * 沒有任何可以觀察供應商的東西。
         */
        $response->assertDontSee('立即同步');
        $response->assertDontSee('測試連線');
        $response->assertDontSee('匯出');
        $response->assertDontSee('刪除');
    }

    /** ⛔ catalog 只有一條 admin route，公開面 0。 */
    public function test_every_provider_service_route_lives_under_admin(): void
    {
        $matched = [];

        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'provider-services')) {
                $matched[] = $route->uri();
            }
        }

        $this->assertSame(['admin/provider-services'], $matched);
    }

    // ==================================== MAPPING-UI-A:Owner review 搜尋／篩選

    /** 264 筆真實 catalog 之後,Owner 靠搜尋而不是捲動找服務。 */
    public function test_the_catalog_is_searchable_by_id_name_and_category(): void
    {
        $this->actingAs($this->owner());

        $target = ProviderService::factory()->available()->create([
            'provider_service_id' => '91011',
            'name' => '獨特虛構搜尋服務',
            'category' => '獨特虛構分類',
        ]);
        $other = ProviderService::factory()->available()->create([
            'provider_service_id' => '92022',
            'name' => '另一個虛構服務',
            'category' => '別的分類',
        ]);

        foreach (['91011', '獨特虛構搜尋服務', '獨特虛構分類'] as $term) {
            Livewire::test(ListProviderServices::class)
                ->searchTable($term)
                ->assertCanSeeTableRecords([$target])
                ->assertCanNotSeeTableRecords([$other]);
        }
    }

    public function test_the_catalog_is_filterable_by_availability_category_type_refill_and_cancel(): void
    {
        $this->actingAs($this->owner());

        $available = ProviderService::factory()->available()->create([
            'category' => '分類甲', 'service_type' => 'Default', 'supports_refill' => true,
        ]);
        $unavailable = ProviderService::factory()->create([
            'category' => '分類乙', 'service_type' => 'Custom', 'supports_refill' => false,
        ]);

        Livewire::test(ListProviderServices::class)
            ->filterTable('is_available', true)
            ->assertCanSeeTableRecords([$available])
            ->assertCanNotSeeTableRecords([$unavailable]);

        Livewire::test(ListProviderServices::class)
            ->filterTable('category', '分類乙')
            ->assertCanSeeTableRecords([$unavailable])
            ->assertCanNotSeeTableRecords([$available]);

        Livewire::test(ListProviderServices::class)
            ->filterTable('service_type', 'Default')
            ->assertCanSeeTableRecords([$available])
            ->assertCanNotSeeTableRecords([$unavailable]);

        Livewire::test(ListProviderServices::class)
            ->filterTable('supports_refill', true)
            ->assertCanSeeTableRecords([$available])
            ->assertCanNotSeeTableRecords([$unavailable]);
    }

    /** R1:cancel filter 的獨立正／反資料證明。 */
    public function test_the_cancel_filter_narrows_to_cancelable_rows(): void
    {
        $this->actingAs($this->owner());

        $cancelable = ProviderService::factory()->available()->create(['supports_cancel' => true]);
        $other = ProviderService::factory()->available()->create(['supports_cancel' => false]);

        Livewire::test(ListProviderServices::class)
            ->filterTable('supports_cancel', true)
            ->assertCanSeeTableRecords([$cancelable])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ListProviderServices::class)
            ->filterTable('supports_cancel', false)
            ->assertCanSeeTableRecords([$other])
            ->assertCanNotSeeTableRecords([$cancelable]);
    }

    /**
     * ⛔ R1:filter 選項 label 是 provider-controlled text 的另一個出口。
     * 敵意 category／service type 進 filter 後,整份 rendered HTML(含
     * filter dropdown)不得出現任何 raw payload;且該敵意值必須真的作為
     * filter option 運作(round-trip 篩選命中),證明不是「filter 根本
     * 沒渲染」的假陰性。
     */
    public function test_hostile_filter_option_labels_are_escaped(): void
    {
        $this->actingAs($this->owner());

        $hostileCategory = '<script>alert("filter-cat-xss")</script>';
        $hostileType = '<img src=x onerror=alert("filter-type-xss")>';

        $hostile = ProviderService::factory()->available()->create([
            'category' => $hostileCategory,
            'service_type' => $hostileType,
        ]);
        $benign = ProviderService::factory()->available()->create([
            'category' => '正常分類',
            'service_type' => 'Default',
        ]);

        // 敵意值確實作為 filter option 運作。
        Livewire::test(ListProviderServices::class)
            ->filterTable('category', $hostileCategory)
            ->assertCanSeeTableRecords([$hostile])
            ->assertCanNotSeeTableRecords([$benign]);

        Livewire::test(ListProviderServices::class)
            ->filterTable('service_type', $hostileType)
            ->assertCanSeeTableRecords([$hostile])
            ->assertCanNotSeeTableRecords([$benign]);

        // 整份 component HTML(含 filter UI)0 個 raw payload;escaped 版本存在。
        $html = Livewire::test(ListProviderServices::class)->html();

        // raw payload(含未 escape 的 < >)全頁 0 出現;'<script>' 標籤本身也不得存在。
        $this->assertStringNotContainsString($hostileCategory, $html);
        $this->assertStringNotContainsString($hostileType, $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        // escaped 版本存在(< > " 都被轉義,payload 只剩純文字)。
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;filter-cat-xss&quot;)', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(&quot;filter-type-xss&quot;)&gt;', $html);
    }

    /**
     * ⛔ R1:count>0 但 last_seen_at 全 null——不得宣稱「最近成功觀察」
     * 或顯示空括號;來源不明的 rows 不能偽裝成同步證據。
     */
    public function test_rows_without_observation_timestamps_do_not_claim_recent_sync(): void
    {
        // factory 預設:unavailable、兩個 seen timestamps 均 null。
        ProviderService::factory()->count(2)->create();

        $response = $this->actingAs($this->owner())->get(self::URL);

        $response->assertOk();
        $response->assertSee('不是本站售價');
        $response->assertSee('未記錄觀察時間');
        $response->assertSee('不能視為最近同步的證據');
        $response->assertDontSee('最近成功觀察');
        $response->assertDontSee('（最後觀察');
    }

    /** 公開頁不得因 catalog 存在而查詢它。 */
    public function test_the_storefront_never_queries_the_catalog(): void
    {
        ProviderService::factory()->create(['name' => '不可外流的虛構名稱']);

        $queried = false;
        $watching = true;

        DB::listen(function ($query) use (&$queried, &$watching) {
            if ($watching && str_contains($query->sql, 'provider_services')) {
                $queried = true;
            }
        });

        $response = $this->get('/');
        $watching = false;

        $response->assertOk();
        $this->assertFalse($queried, '⛔ storefront request 不得查詢 provider_services');
        $response->assertDontSee('不可外流的虛構名稱');
    }
}
