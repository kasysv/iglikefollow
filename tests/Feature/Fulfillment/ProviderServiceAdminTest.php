<?php

namespace Tests\Feature\Fulfillment;

use App\Models\ProviderService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
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

    public function test_the_rate_and_not_synced_warnings_are_always_visible(): void
    {
        $response = $this->actingAs($this->owner())->get(self::URL);

        $response->assertOk();
        $response->assertSee('不是本站售價');
        $response->assertSee('CATALOG-A 為本機 foundation，尚未同步真實帳戶');
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
