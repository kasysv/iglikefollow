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

    /**
     * M2-E-B:主畫面只給非技術人員需要的三欄。
     *
     * ⛔ 服務代碼、rate、分類、型別、refill、cancel、最後觀察一律不在
     * 主畫面出現——它們仍在資料庫與 model 裡,這是顯示決定,不是資料變更。
     */
    public function test_the_catalog_screen_shows_only_name_and_bounds(): void
    {
        ProviderService::factory()->create([
            'provider_service_id' => '9101',
            'name' => '虛構目錄服務',
            'rate_raw' => '0.90',
            'category' => '虛構分類',
            'service_type' => '虛構型別',
            'minimum_quantity_raw' => '50',
            'maximum_quantity_raw' => '5000',
        ]);

        $response = $this->actingAs($this->owner())->get(self::URL);

        $response->assertOk();
        $response->assertSee('虛構目錄服務');
        $response->assertSee('50');
        $response->assertSee('5000');

        // ⛔ 原始觀察欄位不得出現在主畫面。
        $response->assertDontSee('9101');
        $response->assertDontSee('0.90');
        $response->assertDontSee('虛構分類');
        $response->assertDontSee('虛構型別');
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

    /** M2-E-B:搜尋只用服務名稱;⛔ 代碼與分類不再是主畫面的搜尋維度。 */
    public function test_the_catalog_is_searchable_by_name(): void
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

        Livewire::test(ListProviderServices::class)
            ->searchTable('獨特虛構搜尋服務')
            ->assertCanSeeTableRecords([$target])
            ->assertCanNotSeeTableRecords([$other]);
    }

    /** M2-E-B:主畫面只保留「可用」篩選;⛔ 分類／型別／refill／cancel 篩選已移除。 */
    public function test_the_catalog_is_filterable_by_availability_only(): void
    {
        $this->actingAs($this->owner());

        $available = ProviderService::factory()->available()->create([
            'provider_service_id' => '93001',
            'name' => '可用虛構服務',
        ]);
        $unavailable = ProviderService::factory()->create([
            'provider_service_id' => '93002',
            'name' => '已下架虛構服務',
            'is_available' => false,
        ]);

        Livewire::test(ListProviderServices::class)
            ->filterTable('is_available', true)
            ->assertCanSeeTableRecords([$available])
            ->assertCanNotSeeTableRecords([$unavailable]);
    }

    /** M2-E-B:主畫面沒有服務代碼可排,預設改以服務名稱排序。 */
    public function test_the_default_sort_is_by_service_name(): void
    {
        $this->actingAs($this->owner());

        $b = ProviderService::factory()->available()->create([
            'provider_service_id' => '94002', 'name' => 'B 虛構服務',
        ]);
        $a = ProviderService::factory()->available()->create([
            'provider_service_id' => '94001', 'name' => 'A 虛構服務',
        ]);

        Livewire::test(ListProviderServices::class)
            ->assertCanSeeTableRecords([$a, $b], inOrder: true);
    }

    /**
     * M2-E-B:refill／cancel 不再是主畫面的篩選維度。
     *
     * ⛔ 這不代表資料消失:欄位仍在 model 上,只是不塞進日常操作畫面。
     */
    public function test_refill_and_cancel_filters_are_absent_from_the_screen(): void
    {
        $this->actingAs($this->owner());

        $component = Livewire::test(ListProviderServices::class);

        $component->assertTableFilterExists('is_available');

        foreach (['supports_cancel', 'supports_refill', 'category', 'service_type'] as $removed) {
            $this->assertNull(
                $component->instance()->getTable()->getFilter($removed),
                "主畫面不應再有 {$removed} 篩選",
            );
        }
    }

    /**
     * ⛔ Provider-controlled text renders as text, never as markup.
     *
     * M2-E-B 把分類／型別篩選移出主畫面,但敵意目錄的風險並未消失——
     * 服務「名稱」正是主畫面現在會渲染的 provider-controlled 欄位,
     * 所以逃逸保證改用名稱驗證,強度不變。
     */
    public function test_hostile_provider_names_are_escaped_on_the_screen(): void
    {
        $this->actingAs($this->owner());

        $hostileName = '<script>alert("catalog-name-xss")</script>';

        ProviderService::factory()->available()->create([
            'provider_service_id' => '95001',
            'name' => $hostileName,
        ]);

        $html = Livewire::test(ListProviderServices::class)->html();

        // raw payload 全頁 0 出現;'<script>alert' 也不得存在。
        $this->assertStringNotContainsString($hostileName, $html);
        $this->assertStringNotContainsString('<script>alert', $html);

        // escaped 版本存在(payload 只剩純文字)。
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;catalog-name-xss&quot;)', $html);
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
