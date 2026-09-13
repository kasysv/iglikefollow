<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageSiteSettings;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\Faq;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\CanonicalUrl;
use App\Support\FaqPageContent;
use App\Support\VariantGuide;
use Database\Seeders\CatalogSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Concerns\SeedsThreadsCatalog;
use Tests\TestCase;

class VariantGuideTest extends TestCase
{
    use RefreshDatabase, SeedsThreadsCatalog;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed(CatalogSeeder::class);
        $this->seedThreadsCatalog();
        Artisan::call('m2c:apply-copy');
    }

    private function responseFor(string $path)
    {
        return app(Kernel::class)->handle(Request::create('http://localhost:8083'.$path, 'GET'));
    }

    private function htmlFor(Service $service): string
    {
        $response = $this->responseFor('/product/'.rawurlencode($service->product_slug).'/');
        $this->assertSame(200, $response->getStatusCode());

        return $response->getContent();
    }

    private function nodes(string $html, string $query): \DOMNodeList
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return (new DOMXPath($dom))->query($query);
    }

    public function test_defaults_appear_on_all_six_followers_and_likes_pages_only(): void
    {
        $eligible = 0;
        $excluded = 0;
        foreach (Service::query()->whereNotNull('product_slug')->get() as $service) {
            $html = $this->htmlFor($service);
            $count = $this->nodes($html, '//*[@data-probe="variant-guide"]')->length;
            if (in_array($service->slug, ['followers', 'post-likes'], true)) {
                $eligible++;
                $this->assertSame(1, $count);
                foreach (VariantGuide::defaults() as $text) {
                    $this->assertStringContainsString($text, $html);
                }
                // The guide must not push mobile checkout behind the long explanation.
                $this->assertLessThan(strpos($html, 'data-probe="variant-guide"'), strpos($html, 'id="checkout"'));
            } else {
                $excluded++;
                $this->assertSame(0, $count);
            }
        }
        $this->assertSame(6, $eligible);
        $this->assertSame(3, $excluded);
    }

    public function test_switch_off_is_persistent_and_true_cannot_enable_a_view_product(): void
    {
        $service = Service::where('product_slug', 'ig買粉絲')->firstOrFail();
        $service->update(['show_variant_guide' => false]);
        $this->assertStringNotContainsString('data-probe="variant-guide"', $this->htmlFor($service->fresh()));
        $service->update(['show_variant_guide' => true]);
        $this->assertStringContainsString('data-probe="variant-guide"', $this->htmlFor($service->fresh()));

        $views = Service::where('product_slug', 'ig影片觀看')->firstOrFail();
        $views->update(['show_variant_guide' => true]);
        $this->assertStringNotContainsString('data-probe="variant-guide"', $this->htmlFor($views));
    }

    public function test_shared_edit_reaches_all_enabled_products_and_escapes_html(): void
    {
        $guide = VariantGuide::defaults();
        $guide['real'] = "第一行\n第二行<script>alert('fixture')</script>";
        SiteSetting::current()->update(['variant_guide' => $guide]);
        foreach (Service::whereIn('slug', ['followers', 'post-likes'])->get() as $service) {
            $html = $this->htmlFor($service);
            $this->assertStringContainsString(e($guide['real']), $html);
            $this->assertStringNotContainsString("<script>alert('fixture')</script>", $html);
            $this->assertSame(4, $this->nodes($html, '//*[@data-probe="variant-guide"]//dd[contains(@class,"whitespace-pre-line")]')->length);
        }
        Http::assertNothingSent();
    }

    public function test_missing_or_malformed_settings_have_safe_defaults(): void
    {
        $this->assertSame(VariantGuide::defaults(), VariantGuide::resolve(null));
        $this->assertSame(VariantGuide::defaults(), VariantGuide::resolve('invalid'));
        $this->assertSame(VariantGuide::defaults(), VariantGuide::resolve(['real' => ['invalid'], 'unknown' => 'ignored']));
    }

    public function test_owner_can_save_shared_copy_and_product_switch_using_the_real_forms(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner', 'is_active' => true]));
        Livewire::test(ManageSiteSettings::class)
            ->set('data.variant_guide.real', '由後台統一更新的真人說明')
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('由後台統一更新的真人說明', SiteSetting::current()->variantGuide()['real']);

        $service = Service::where('product_slug', 'ig買粉絲')->firstOrFail();
        Livewire::test(EditService::class, ['record' => $service->id])
            ->assertFormSet(['show_variant_guide' => true])
            ->fillForm(['show_variant_guide' => false])
            ->call('save')->assertHasNoFormErrors();
        $this->assertFalse($service->fresh()->show_variant_guide);
    }

    public function test_editor_cannot_access_shared_settings(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'editor', 'is_active' => true]));
        $this->assertFalse(ManageSiteSettings::canAccess());
        $this->get(ManageSiteSettings::getUrl())->assertForbidden();
    }

    public function test_guide_does_not_change_metadata_h1_schema_or_links(): void
    {
        $service = Service::where('product_slug', 'ig買粉絲')->firstOrFail();
        $service->update(['show_variant_guide' => false]);
        $before = $this->htmlFor($service);
        $sitemap = $this->responseFor('/sitemap.xml')->getContent();
        $robots = $this->responseFor('/robots.txt')->getContent();
        $service->update(['show_variant_guide' => true]);
        $after = $this->htmlFor($service);
        foreach (['//title', '//meta', '//link[@rel="canonical"]', '//h1', '//script[@type="application/ld+json"]', '//a'] as $query) {
            $serialize = fn (string $html) => array_map(fn ($node) => $node->ownerDocument->saveHTML($node), iterator_to_array($this->nodes($html, $query)));
            $this->assertSame($serialize($before), $serialize($after), $query);
        }
        $this->assertSame($sitemap, $this->responseFor('/sitemap.xml')->getContent());
        $this->assertSame($robots, $this->responseFor('/robots.txt')->getContent());
        $this->assertCount(14, CanonicalUrl::indexablePaths());
    }

    public function test_faq_answers_keep_enter_and_blank_lines_safely_on_all_four_page_types(): void
    {
        $answer = "第一行\n第二行\n\n第四行 <script>fixture</script>";
        $service = Service::where('product_slug', 'ig買粉絲')->firstOrFail();
        Faq::create(['scope' => 'global', 'question' => '全域換行測試', 'answer' => $answer, 'status' => 'published',
            'managed_key' => app(FaqPageContent::class)->homeFeaturedKeys()[0], 'sort_order' => 0]);
        Faq::create(['scope' => 'platform', 'platform_id' => $service->platform_id, 'question' => '平台換行測試', 'answer' => $answer, 'status' => 'published', 'sort_order' => 0]);
        Faq::create(['scope' => 'service', 'service_id' => $service->id, 'question' => '商品換行測試', 'answer' => $answer, 'status' => 'published', 'sort_order' => 0]);
        foreach (['/', '/faq', '/services/instagram', '/product/'.rawurlencode('ig買粉絲').'/'] as $path) {
            $response = $this->responseFor($path);
            $this->assertSame(200, $response->getStatusCode());
            $html = $response->getContent();
            $paragraphs = $this->nodes($html, '//p[contains(@class,"whitespace-pre-line")]');
            $this->assertContains($answer, array_map(fn ($node) => $node->textContent, iterator_to_array($paragraphs)), $path);
            $this->assertStringNotContainsString('<script>fixture</script>', $html);
        }
    }

    public function test_new_migration_can_roll_back_and_reapply_without_touching_existing_copy(): void
    {
        $migration = require database_path('migrations/2026_09_13_180000_add_shared_variant_guide.php');
        $before = SiteSetting::current()->home_h1;
        $migration->down();
        $this->assertFalse(Schema::hasColumn('site_settings', 'variant_guide'));
        $this->assertFalse(Schema::hasColumn('services', 'show_variant_guide'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('site_settings', 'variant_guide'));
        $this->assertSame($before, SiteSetting::current()->home_h1);
    }

    public function test_rollback_refuses_to_destroy_saved_copy_or_switches(): void
    {
        $migration = require database_path('migrations/2026_09_13_180000_add_shared_variant_guide.php');
        SiteSetting::current()->update(['variant_guide' => VariantGuide::defaults()]);
        try {
            $migration->down();
            $this->fail('Saved guide must prevent destructive rollback');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('自訂資料', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('services', 'show_variant_guide'));
        $this->assertSame(VariantGuide::defaults(), SiteSetting::current()->variantGuide());
    }
}
