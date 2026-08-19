<?php

namespace Tests\Feature;

use App\Filament\Resources\Services\Pages\EditService;
use App\Models\Service;
use App\Models\User;
use App\Support\ProductSlugMap;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\SeedsThreadsCatalog;
use Tests\TestCase;

/**
 * M2-C(D-103 方案3):`/product/{slug}/` 是 9 個可購服務唯一的主要商品
 * 路由;`/services/{platform}` 是 Hub;商品級 `/services/...` 只作 302 收斂
 * 與授權 preview,不得形成可索引第二頁。
 *
 * ⛔ 文案斷言一律對 repo 內 fixture(81 records)逐字比對,不另打字。
 */
class M2cProductRoutesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsThreadsCatalog;

    /** @var array<string, string> record_key => draft_value */
    private array $copy = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->seed(CatalogSeeder::class);
        $this->seedThreadsCatalog();
        Artisan::call('m2c:apply-copy');

        foreach (json_decode((string) file_get_contents(database_path('fixtures/m2c-publishable-copy.json')), true) as $r) {
            $this->copy[$r['record_key']] = $r['draft_value'];
        }
    }

    private function serviceByKey(string $key): Service
    {
        return Service::query()->where('product_slug', ProductSlugMap::MAP[$key])->firstOrFail();
    }

    public function test_all_nine_product_urls_resolve_to_the_exact_service(): void
    {
        foreach (ProductSlugMap::MAP as $key => $slug) {
            [, $serviceSlug] = explode('/', $key, 2);
            $service = $this->serviceByKey($key);

            $html = $this->get('/product/'.$slug.'/')->assertOk()->getContent();

            // 正確服務:H1 來自 fixture、name 存在,⛔ 不是「碰巧 200」。
            $recordPrefix = str_replace('/', '-', $key);
            $this->assertStringContainsString('<h1', $html, $key);
            $this->assertStringContainsString($this->copy["{$recordPrefix}.h1"], $html, $key);
            $this->assertSame(1, substr_count($html, '<h1'), $key);
            $this->assertSame($serviceSlug, $service->slug, $key);
        }
    }

    public function test_unknown_malformed_and_draft_slugs_are_404(): void
    {
        $this->get('/product/不存在的商品/')->assertNotFound();
        $this->get('/product/UPPER/')->assertNotFound();
        $this->get('/product/a.b/')->assertNotFound();
        $this->get('/product/a b/')->assertNotFound();
        $this->get('/product/a%2Fb/')->assertNotFound();

        // draft leakage 反證:draft service 即使有 slug 也不得成頁。
        $service = $this->serviceByKey('facebook/video-views');
        $service->forceFill(['status' => 'draft'])->saveQuietly();
        $this->get('/product/fb影片觀看/')->assertNotFound();
    }

    public function test_home_hub_and_product_pages_carry_title_description_h1_and_self_canonical(): void
    {
        // 首頁
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('<title>'.$this->copy['home.seo_title'].'</title>', $html);
        $this->assertStringContainsString($this->copy['home.meta_description'], $html);
        $this->assertStringContainsString($this->copy['home.home_h1'], $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/').'/">', $html);

        // 3 個 Hub
        foreach (['instagram', 'facebook', 'threads'] as $slug) {
            $html = $this->get('/services/'.$slug)->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, '<h1'), $slug);
            $this->assertStringContainsString('<title>'.$this->copy["{$slug}.seo_title"].'</title>', $html, $slug);
            $this->assertStringContainsString($this->copy["{$slug}.meta_description"], $html, $slug);
            $this->assertStringContainsString($this->copy["{$slug}.intro"], $html, $slug);
            $this->assertStringContainsString('<link rel="canonical" href="'.route('platform', $slug).'">', $html, $slug);
        }

        // 9 個商品頁:title/description/summary/intro/cta 與 self-canonical
        foreach (ProductSlugMap::MAP as $key => $slug) {
            $recordPrefix = str_replace('/', '-', $key);
            $service = $this->serviceByKey($key);
            $html = $this->get('/product/'.$slug.'/')->assertOk()->getContent();

            $this->assertStringContainsString('<title>'.$this->copy["{$recordPrefix}.seo_title"].'</title>', $html, $key);
            $this->assertStringContainsString($this->copy["{$recordPrefix}.meta_description"], $html, $key);
            $this->assertStringContainsString($this->copy["{$recordPrefix}.summary"], $html, $key);
            $this->assertStringContainsString($this->copy["{$recordPrefix}.intro"], $html, $key);
            // cta_label 顯示在選購區。
            $this->assertStringContainsString($this->copy["{$recordPrefix}.cta_label"], $html, $key);
            $this->assertStringContainsString('<link rel="canonical" href="'.$service->primaryUrl().'">', $html, $key);
            // canonical 形式=尾斜線,無 query/fragment。
            $this->assertStringEndsWith('/', $service->primaryUrl(), $key);
            $this->assertStringNotContainsString('?', $service->primaryUrl(), $key);
            $this->assertStringNotContainsString('#', $service->primaryUrl(), $key);
        }
    }

    public function test_public_html_links_products_only_via_product_urls(): void
    {
        $pages = array_merge(
            ['/', '/services/instagram', '/services/facebook', '/services/threads'],
            array_map(fn (string $slug) => '/product/'.$slug.'/', array_values(ProductSlugMap::MAP)),
        );

        foreach ($pages as $page) {
            $html = $this->get($page)->assertOk()->getContent();

            // ⛔ 初始 HTML 商品內鏈=0 個商品級 /services/...。
            $this->assertDoesNotMatchRegularExpression(
                '#href="[^"]*/services/[a-z]+/[a-z][^"]*"#',
                $html,
                $page,
            );
        }

        // Hub 至少內鏈其每個可購服務的 /product/ canonical。
        $html = $this->get('/services/instagram')->assertOk()->getContent();

        foreach (['instagram/followers', 'instagram/post-likes', 'instagram/video-views'] as $key) {
            $this->assertStringContainsString($this->serviceByKey($key)->primaryUrl(), $html, $key);
        }
    }

    public function test_service_level_routes_are_a_single_302_and_preview_only(): void
    {
        $followers = $this->serviceByKey('instagram/followers');

        // guest:單次 302 直達 canonical(target 是最終頁,無二跳)。
        $this->get('/services/instagram/followers')
            ->assertStatus(302)
            ->assertRedirect($followers->primaryUrl());

        // ⛔ 本輪不得出現任何正式 301。
        $this->assertNotSame(301, $this->get('/services/instagram/followers')->getStatusCode());

        // guest 掛 preview flag 無效:仍是 302(不外洩 preview 內容)。
        $this->get('/services/instagram/followers?preview=1')
            ->assertStatus(302);

        // 授權 preview:200+noindex;⛔ 不輸出可索引 canonical。
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $response = $this->actingAs($owner)
            ->get('/services/instagram/followers?preview=1')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->assertStringNotContainsString('rel="canonical"', $response->getContent());

        // draft(comments)guest 404;授權 preview 200。
        $this->get('/services/instagram/comments')->assertNotFound();
        $this->actingAs($owner)->get('/services/instagram/comments?preview=1')->assertOk();

        auth()->logout();

        // 無 product slug 的 published 服務(auto-likes)guest 404。
        $this->get('/services/instagram/auto-likes')->assertNotFound();
    }

    public function test_checkout_start_error_and_return_all_land_on_the_product_page(): void
    {
        $followers = $this->serviceByKey('instagram/followers');
        $variant = $followers->variants()->published()->firstOrFail();

        // 驗證失敗:back 到來源商品頁(errors 由 session 帶回,無可索引參數頁)。
        $this->from($followers->primaryUrl())
            ->post('/checkout/start', ['variant' => $variant->id, 'quantity' => 1])
            ->assertRedirect($followers->primaryUrl())
            ->assertSessionHasErrors('quantity');

        // 成功:進 /checkout;返回修改:302 回商品頁+#checkout,⛔ 無 ?resume=1。
        $this->post('/checkout/start', [
            'variant' => $variant->id,
            'quantity' => (int) $variant->default_quantity,
        ])->assertRedirect(route('checkout'));

        $location = (string) $this->post('/checkout/return')->headers->get('Location');

        $this->assertSame($followers->primaryUrl().'#checkout', $location);
        $this->assertStringNotContainsString('resume=1', $location);

        // 回到商品頁本身仍是干淨 canonical URL(無 query)。
        $this->get($followers->primaryUrl())->assertOk();
    }

    public function test_product_slug_is_owner_only_and_validated_in_the_admin(): void
    {
        $followers = $this->serviceByKey('instagram/followers');

        // Editor:欄位 disabled+不 dehydrate → 值不可能被改。
        $editor = User::factory()->create(['role' => 'editor', 'is_active' => true]);

        Livewire::actingAs($editor)
            ->test(EditService::class, ['record' => $followers->getRouteKey()])
            ->fillForm(['product_slug' => 'editor-hijack'])
            ->call('save');

        $this->assertSame('ig買粉絲', $followers->fresh()->product_slug);

        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        // Owner:壞 shape 擋下(含 /、.、空白)。
        foreach (['a/b', 'a.b', 'a b', 'UPPER'] as $bad) {
            Livewire::actingAs($owner)
                ->test(EditService::class, ['record' => $followers->getRouteKey()])
                ->fillForm(['product_slug' => $bad])
                ->call('save')
                ->assertHasFormErrors(['product_slug']);
        }

        // Owner:與其他服務碰撞擋下(DB unique 的表單前哨)。
        Livewire::actingAs($owner)
            ->test(EditService::class, ['record' => $followers->getRouteKey()])
            ->fillForm(['product_slug' => 'ig買like'])
            ->call('save')
            ->assertHasFormErrors(['product_slug']);

        $this->assertSame('ig買粉絲', $followers->fresh()->product_slug);
    }

    public function test_noindex_robots_health_and_checkout_surfaces_do_not_regress(): void
    {
        $this->get('/')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/product/ig買粉絲/')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /');
        $this->get('/api/health')->assertOk()->assertJsonPath('indexing', false);

        $followers = $this->serviceByKey('instagram/followers');
        $variant = $followers->variants()->published()->firstOrFail();
        $this->post('/checkout/start', [
            'variant' => $variant->id,
            'quantity' => (int) $variant->default_quantity,
        ]);
        $this->get('/checkout')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
