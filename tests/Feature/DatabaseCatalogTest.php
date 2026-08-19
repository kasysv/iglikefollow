<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\Service;
use App\Models\ServiceContentSection;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Support\CatalogRepository;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_database_shows_an_honest_empty_state_and_never_falls_back_to_config(): void
    {
        // ⛔ 資料庫空白時不得悄悄回退 config/catalog.php。
        $this->assertGreaterThan(0, count(config('catalog.platforms')));

        $this->get('/')
            ->assertOk()
            ->assertSee('服務資料準備中')
            ->assertDontSee('/services/instagram/followers', false);

        $this->get('/services/instagram')->assertNotFound();
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(CatalogSeeder::class);
        $first = [Platform::count(), Service::count(), ServiceVariant::count()];

        $this->seed(CatalogSeeder::class);
        $second = [Platform::count(), Service::count(), ServiceVariant::count()];

        $this->assertSame($first, $second);
    }

    public function test_publishing_a_service_makes_it_visible_without_touching_code(): void
    {
        $platform = Platform::factory()->published()->create(['slug' => 'instagram', 'name' => 'Instagram']);

        $service = Service::factory()->create([
            'platform_id' => $platform->id,
            'slug' => 'new-service',
            'name' => 'New Service',
            'status' => 'draft',
        ]);

        $this->get('/services/instagram/new-service')->assertNotFound();

        // M2-C(D-103):公開頁=有 product slug 的 /product/ canonical。
        $service->update(['status' => 'published', 'first_published_at' => now(), 'product_slug' => 'new-service']);

        $this->get('/product/new-service/')->assertOk()->assertSee('New Service');
    }

    public function test_guests_cannot_use_the_preview_flag_to_see_drafts(): void
    {
        $platform = Platform::factory()->published()->create(['slug' => 'instagram', 'name' => 'Instagram']);
        Service::factory()->create([
            'platform_id' => $platform->id,
            'slug' => 'secret',
            'name' => 'Secret Draft',
            'status' => 'draft',
        ]);

        // ?preview=1 在訪客手上必須無效。
        $this->get('/services/instagram/secret?preview=1')->assertNotFound();
    }

    public function test_authenticated_admin_can_preview_a_draft(): void
    {
        $platform = Platform::factory()->published()->create(['slug' => 'instagram', 'name' => 'Instagram']);
        Service::factory()->create([
            'platform_id' => $platform->id,
            'slug' => 'secret',
            'name' => 'Secret Draft',
            'status' => 'draft',
        ]);

        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $this->actingAs($owner)
            ->get('/services/instagram/secret?preview=1')
            ->assertOk()
            ->assertSee('Secret Draft')
            ->assertSee('草稿預覽模式');
    }

    public function test_draft_preview_is_always_noindex(): void
    {
        $platform = Platform::factory()->published()->create(['slug' => 'instagram', 'name' => 'Instagram']);
        Service::factory()->create([
            'platform_id' => $platform->id,
            'slug' => 'secret',
            'status' => 'draft',
        ]);

        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $this->actingAs($owner)
            ->get('/services/instagram/secret?preview=1')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_archived_platform_disappears_from_public_navigation(): void
    {
        $this->seed(CatalogSeeder::class);

        Platform::query()->where('slug', 'facebook')->update(['status' => 'archived']);

        $this->get('/')->assertOk()->assertDontSee('/services/facebook', false);
        $this->get('/services/facebook')->assertNotFound();
    }

    public function test_admin_supplied_content_is_escaped_not_executed(): void
    {
        $platform = Platform::factory()->published()->create(['slug' => 'instagram', 'name' => 'Instagram']);
        $service = Service::factory()->published()->create([
            'platform_id' => $platform->id,
            'slug' => 'xss',
            'product_slug' => 'test-xss',
            'name' => 'XSS Probe',
        ]);

        ServiceContentSection::create([
            'service_id' => $service->id,
            'heading' => 'Heading <script>alert(1)</script>',
            'body' => 'Body <script>alert(2)</script> <b>bold</b>',
            'status' => 'published',
            'sort_order' => 0,
        ]);

        $html = $this->get('/product/test-xss/')->assertOk()->getContent();

        // ⛔ 後台內容不得以 raw HTML／script 輸出到前台。
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<script>alert(2)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_checkout_allow_list_comes_from_the_database(): void
    {
        $this->seed(CatalogSeeder::class);

        $repository = app(CatalogRepository::class);
        $purchasable = $repository->purchasableVariants();

        $this->assertGreaterThan(0, $purchasable->count());

        // 下架整個平台後，其服務項目不得再出現在白名單。
        Platform::query()->where('slug', 'facebook')->update(['status' => 'archived']);

        $after = app(CatalogRepository::class)->purchasableVariants();
        $this->assertLessThan($purchasable->count(), $after->count());
    }
}
