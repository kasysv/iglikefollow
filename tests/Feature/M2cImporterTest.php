<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\Platform;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SiteSetting;
use App\Support\ProductSlugMap;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsThreadsCatalog;
use Tests\TestCase;

/**
 * M2-C importer:81 筆公開文案的 transactional 填入。
 *
 * ⛔ dry-run 0 write;apply 精確 counts;第二次 apply idempotent;
 * 範圍外欄位(價格/數量/SKU/status/mapping)一個位元都不變;
 * fixture 本身 81 筆、key 唯一、無禁詞。
 */
class M2cImporterTest extends TestCase
{
    use RefreshDatabase;
    use SeedsThreadsCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->seed(CatalogSeeder::class);
        $this->seedThreadsCatalog();
        // seeder 已依 ProductSlugMap 給 slug;先清掉以驗證 importer 自己會指派。
        Service::query()->update(['product_slug' => null]);
    }

    /** @return array<string, mixed> 範圍外欄位快照(價格/數量/SKU/狀態/mapping)。 */
    private function outOfScopeSnapshot(): array
    {
        return [
            'variants' => DB::table('service_variants')->orderBy('id')
                ->get(['id', 'sku', 'min_quantity', 'max_quantity', 'step_quantity', 'default_quantity', 'unit_price', 'currency', 'status', 'image_path'])
                ->map(fn ($r) => (array) $r)->all(),
            'service_status' => DB::table('services')->orderBy('id')->pluck('status', 'id')->all(),
            'mappings' => DB::table('fulfillment_mappings')->count(),
        ];
    }

    public function test_the_fixture_is_81_unique_records_with_no_banned_words(): void
    {
        $records = json_decode((string) file_get_contents(database_path('fixtures/m2c-publishable-copy.json')), true);

        $this->assertCount(81, $records);

        $keys = array_column($records, 'record_key');
        $this->assertSame(count($keys), count(array_unique($keys)));

        $values = implode(' ', array_column($records, 'draft_value'));

        foreach (['SMM', '供應商', '派單', 'API', '批發', 'OWNER INPUT', 'TheMostPanel', 'secret', 'APP_KEY'] as $banned) {
            $this->assertStringNotContainsString($banned, $values, $banned);
        }
    }

    public function test_dry_run_writes_nothing(): void
    {
        $before = [
            'db' => $this->outOfScopeSnapshot(),
            'faqs' => Faq::query()->count(),
            'slugs' => Service::query()->whereNotNull('product_slug')->count(),
            'site' => DB::table('site_settings')->first(),
        ];

        Artisan::call('m2c:apply-copy', ['--dry-run' => true]);

        $this->assertSame($before['db'], $this->outOfScopeSnapshot());
        $this->assertSame($before['faqs'], Faq::query()->count());
        $this->assertSame(0, Service::query()->whereNotNull('product_slug')->count());
        $this->assertEquals($before['site'], DB::table('site_settings')->first());
        $this->assertStringContainsString('0 writes', Artisan::output());
    }

    public function test_apply_writes_exact_counts_and_is_idempotent(): void
    {
        $outOfScopeBefore = $this->outOfScopeSnapshot();
        $faqsBefore = Faq::query()->count();

        Artisan::call('m2c:apply-copy');
        $first = Artisan::output();

        // 9 slugs、site 5、platform 12、service 53-54、tier 6、faq 3。
        $this->assertSame(9, Service::query()->whereNotNull('product_slug')->count());
        $this->assertSame($faqsBefore + 3, Faq::query()->count());
        $this->assertStringContainsString('slug set=9', $first);
        $this->assertStringContainsString('faq created=3', $first);
        /*
         * tier 寫入數依「label 恰含一個 token 的既有 variants」動態決定
         * (seeder 與 Owner dev DB 的 variant 組成不同;dev 實測=6)。
         */
        $followersService = Service::query()
            ->whereHas('platform', fn ($q) => $q->where('slug', 'instagram'))
            ->where('slug', 'followers')->firstOrFail();
        $tokens = ['真人', '頂級', '高級', '普通'];
        $expectedTierWrites = ServiceVariant::query()
            ->where('service_id', $followersService->id)->get()
            ->filter(function ($variant) use ($tokens): bool {
                $hits = collect($tokens)->filter(fn ($t) => str_contains((string) $variant->label, $t))->count();

                return $hits === 1;
            })->count();

        $this->assertGreaterThan(0, $expectedTierWrites);
        $this->assertStringContainsString('tier variants='.$expectedTierWrites, $first);
        // 無明確匹配的 tier → skipped 回報,不猜(高級/普通 在兩環境都不存在)。
        $this->assertStringContainsString('高級', $first);
        $this->assertStringContainsString('普通', $first);

        // slug 恰為固定 mapping。
        foreach (ProductSlugMap::MAP as $key => $slug) {
            [$platformSlug, $serviceSlug] = explode('/', $key, 2);
            $platform = Platform::query()->where('slug', $platformSlug)->firstOrFail();
            $this->assertSame(
                $slug,
                Service::query()->where('platform_id', $platform->id)->where('slug', $serviceSlug)->value('product_slug'),
                $key,
            );
        }

        // comments/auto-likes 維持 null。
        $this->assertSame(
            0,
            Service::query()->whereIn('slug', ['comments', 'auto-likes'])->whereNotNull('product_slug')->count(),
        );

        // 範圍外欄位一個位元不變。
        $this->assertSame($outOfScopeBefore, $this->outOfScopeSnapshot());

        // 內容逐字=fixture。
        $records = collect(json_decode((string) file_get_contents(database_path('fixtures/m2c-publishable-copy.json')), true))
            ->keyBy('record_key');

        $setting = SiteSetting::query()->firstOrFail();
        $this->assertSame($records['home.seo_title']['draft_value'], $setting->seo_title);
        $this->assertSame($records['home.meta_description']['draft_value'], $setting->meta_description);
        $this->assertSame($records['home.home_h1']['draft_value'], $setting->home_h1);

        $ig = Platform::query()->where('slug', 'instagram')->firstOrFail();
        $this->assertSame($records['instagram.seo_title']['draft_value'], $ig->seo_title);
        $this->assertSame($records['instagram.tagline']['draft_value'], $ig->tagline);

        $followers = Service::query()->where('product_slug', 'ig買粉絲')->firstOrFail();
        $this->assertSame($records['instagram-followers.seo_title']['draft_value'], $followers->seo_title);
        $this->assertSame($records['instagram-followers.h1']['draft_value'], $followers->h1);
        $this->assertSame($records['instagram-followers.cta_label']['draft_value'], $followers->cta_label);

        // 等級敘述:只寫入 label 明確含 token 的 variants。
        $tierReal = $records['tier.real']['draft_value'];
        $realVariants = ServiceVariant::query()->where('service_id', $followers->id)
            ->where('label', 'like', '%真人%')->get();
        $this->assertNotEmpty($realVariants);

        foreach ($realVariants as $variant) {
            $this->assertSame($tierReal, $variant->description, $variant->label);
        }

        // FAQ idempotent key=完整 question。
        $this->assertSame(1, Faq::query()->where('question', $records['faq.account-lock.question']['draft_value'])->count());

        // 第二次 apply:0 變更、FAQ 不重複。
        Artisan::call('m2c:apply-copy');
        $second = Artisan::output();

        $this->assertStringContainsString('slug set=0', $second);
        $this->assertStringContainsString('faq created=0', $second);
        $this->assertStringContainsString('site=0 platform=0 service=0', $second);
        $this->assertSame($faqsBefore + 3, Faq::query()->count());
        $this->assertSame($outOfScopeBefore, $this->outOfScopeSnapshot());
    }

    public function test_a_missing_service_rolls_the_whole_batch_back(): void
    {
        // 拿掉一個 mapping 目標 → 整批必須 rollback(slug/文案/FAQ 全不落地)。
        Service::query()->where('slug', 'post-boost')->forceDelete();

        $exit = Artisan::call('m2c:apply-copy');

        $this->assertSame(1, $exit);
        $this->assertSame(0, Service::query()->whereNotNull('product_slug')->count());
        $this->assertSame(0, Faq::query()->where('scope', 'service')->count());
        $this->assertNull(SiteSetting::query()->firstOrFail()->seo_title);
    }

    public function test_imported_faqs_render_on_the_followers_product_page(): void
    {
        Artisan::call('m2c:apply-copy');

        $records = collect(json_decode((string) file_get_contents(database_path('fixtures/m2c-publishable-copy.json')), true))
            ->keyBy('record_key');

        $html = $this->get('/product/ig買粉絲/')->assertOk()->getContent();

        $this->assertStringContainsString($records['faq.account-lock.question']['draft_value'], $html);
        $this->assertStringContainsString($records['faq.account-lock.answer']['draft_value'], $html);
        $this->assertStringContainsString($records['faq.password.question']['draft_value'], $html);

        // 公開 HTML 禁詞/credential shape=0(此頁為文案最密集頁)。
        foreach (['SMM', '派單', '批發', 'TheMostPanel', 'APP_KEY', 'password_hash'] as $banned) {
            $this->assertStringNotContainsString($banned, $html, $banned);
        }
    }
}
