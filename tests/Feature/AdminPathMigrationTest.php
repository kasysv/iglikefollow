<?php

namespace Tests\Feature;

use App\Http\Middleware\CanonicalUrlRedirect;
use App\Models\Order;
use App\Models\User;
use App\Services\Notifications\PaidOrderMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * M5C：後台入口由 `/admin` 改為 `/ignfdash`。
 *
 * ⭐ 這一份集中釘住「換路徑」本身的性質，⛔ 不重複既有後台功能測試。
 *
 * ⛔⛔ 換路徑**不是**額外的登入安全保證：認證、授權、CSRF、session、
 * Livewire 保護與 ForceNoindex 全部照舊——下面每一條都各驗一次，
 * 免得有人把「網址變難猜」誤當成安全性提升。
 */
class AdminPathMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    // ======================================== 1. 新舊入口

    public function test_the_panel_is_served_from_the_new_path(): void
    {
        $this->get('/ignfdash')->assertRedirect('/ignfdash/login');
        $this->get('/ignfdash/login')->assertOk();
    }

    /**
     * ⛔ 舊入口沒有第二套後台，也⛔ 沒有轉址。
     *
     * ⭐ 施工單明確要求 404：⛔ 不新增 301／302 指向新入口，
     * 否則等於把新網址公告給任何試舊網址的人。
     * ⛔ 舊書籤與歷史 LINE 連結會失效，這是預期影響。
     */
    public function test_the_old_admin_path_is_gone_with_no_redirect(): void
    {
        foreach (['/admin', '/admin/login', '/admin/orders', '/admin/services'] as $old) {
            $response = $this->get($old);

            $this->assertSame(404, $response->getStatusCode(), "{$old} 必須 404");
            $this->assertNull($response->headers->get('Location'), "⛔ {$old} 不得轉址");
        }
    }

    // ======================================== 2. route name 不變

    /**
     * ⛔⛔ panel id 仍是 `admin`，所以 route name 必須一個都沒變。
     *
     * ⭐ `filament.admin.*` 是 `getUrl()`、授權與既有程式共用的識別碼；
     * 改 name 會牽動一堆看不見的地方，而本輪只要換對外網址。
     */
    public function test_every_filament_route_keeps_its_admin_name_but_moves_to_the_new_path(): void
    {
        $filament = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'filament.'));

        $this->assertGreaterThan(30, $filament->count(), '應該有一整組 Filament route。');

        $panelRoutes = $filament->filter(
            fn ($route) => str_starts_with((string) $route->getName(), 'filament.admin.')
        );

        $this->assertGreaterThan(30, $panelRoutes->count());

        foreach ($panelRoutes as $route) {
            $this->assertStringStartsWith(
                'ignfdash',
                $route->uri(),
                "{$route->getName()} 必須走新路徑，實際：{$route->uri()}"
            );

            $this->assertStringNotContainsString('admin/', $route->uri());
        }
    }

    // ======================================== 3. 安全性一律照舊

    public function test_the_new_path_is_unconditionally_noindex(): void
    {
        /*
         * ⛔ 正式站已開放索引，所以這一條不能依賴 IndexingPolicy：
         * 後台必須無條件 noindex。
         */
        config(['app.env' => 'production', 'seo.allow_indexing' => true]);

        $this->get('/ignfdash/login')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_a_guest_still_cannot_reach_anything_behind_the_new_path(): void
    {
        foreach (['/ignfdash', '/ignfdash/orders', '/ignfdash/services', '/ignfdash/users'] as $path) {
            $this->get($path)->assertRedirect();
        }
    }

    /** 換路徑不得讓非授權角色多拿到任何東西。 */
    public function test_an_editor_gains_no_owner_only_access_through_the_new_path(): void
    {
        $editor = User::factory()->create(['role' => 'editor', 'is_active' => true]);

        foreach (['/ignfdash/provider-services', '/ignfdash/fulfillment-mappings', '/ignfdash/admin-audit-logs'] as $ownerOnly) {
            $this->actingAs($editor)->get($ownerOnly)->assertForbidden();
        }
    }

    /**
     * ⭐ 已登入但停用的帳號拿到的是 **403**，⛔ 不是轉址。
     *
     * 我第一版寫成 `assertRedirect()` 而它紅了——那是我的預期錯了：
     * 未登入才會被導去 login，已登入但 `canAccessPanel()` 為 false 的
     * 使用者會直接被擋下。⭐ 403 是更嚴格的結果，這裡照實釘住。
     */
    public function test_an_inactive_user_cannot_reach_the_new_path(): void
    {
        $inactive = User::factory()->create(['role' => 'owner', 'is_active' => false]);

        $this->actingAs($inactive)->get('/ignfdash')->assertForbidden();
    }

    public function test_an_owner_can_open_the_panel_on_the_new_path(): void
    {
        $this->actingAs($this->owner())->get('/ignfdash')->assertOk();
    }

    // ======================================== 4. SEO 正規化不得碰後台

    /**
     * ⛔ 後台 URL 帶識別碼，SEO 正規化會轉小寫並丟掉 query。
     *
     * ⭐ 這裡直接驗 middleware 的判斷本身，⛔ 不靠端對端行為
     * ——Filament 有自己的 route，端對端看不出 bypass 有沒有生效。
     */
    public function test_the_seo_middleware_bypasses_both_the_new_and_the_old_admin_prefix(): void
    {
        $middleware = new \ReflectionClass(CanonicalUrlRedirect::class);

        $bypasses = $middleware->getMethod('bypassesSeoNormalisation');
        $bypasses->setAccessible(true);

        $instance = app(CanonicalUrlRedirect::class);

        foreach (['/ignfdash', '/ignfdash/', '/ignfdash/orders/IGLF-20260913-ABCD'] as $path) {
            $this->assertTrue($bypasses->invoke($instance, $path), "{$path} 必須跳過 SEO 正規化");
        }

        // ⛔ 舊前綴的保護邊界保留。
        foreach (['/admin', '/admin/login'] as $path) {
            $this->assertTrue($bypasses->invoke($instance, $path));
        }

        // ⛔ 公開頁一定要繼續被正規化。
        foreach (['/faq', '/product/x/'] as $path) {
            $this->assertFalse($bypasses->invoke($instance, $path));
        }
    }

    public function test_the_new_admin_path_never_enters_the_sitemap(): void
    {
        $body = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringNotContainsString('ignfdash', $body);
        $this->assertStringNotContainsString('/admin', $body);
    }

    /** 後台入口不得出現在前台任何一頁的導覽。 */
    public function test_the_admin_entry_is_not_linked_from_public_pages(): void
    {
        foreach (['/', '/faq'] as $path) {
            $html = (string) $this->get($path)->assertOk()->getContent();

            $this->assertStringNotContainsString('ignfdash', $html, "{$path} 不得連到後台");
        }
    }

    // ======================================== 5. LINE 通知用新網址

    /**
     * ⛔ LINE 訂單通知的後台連結必須是新路徑。
     *
     * ⭐ 它由 `ViewOrder::getUrl()` 產生，所以換 panel path 就會跟著換
     * ——⛔ 這條測試釘住那個事實，⛔ 不改訊息格式或發送條件。
     */
    public function test_the_line_order_link_uses_the_new_admin_path(): void
    {
        $order = Order::factory()->create();

        $message = PaidOrderMessage::for($order->fresh());

        $this->assertStringContainsString('/ignfdash/', $message);
        $this->assertStringNotContainsString('/admin/', $message);
    }
}
