<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\Platform;
use App\Models\Service;
use App\Models\ServiceContentSection;
use App\Support\ProductSlugMap;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\IsolatesSnapshotStorage;
use Tests\Concerns\SeedsThreadsCatalog;
use Tests\TestCase;

/**
 * M2-C R3:13 頁完整 SEO 內容、內鏈矩陣與受控 FAQ 移除。
 *
 * ⛔ 內容斷言一律對 repo 內 R3 fixture 逐字比對;禁詞/無證據主張在
 * 13 頁公開 HTML 必須 0 命中;rollback 只還原本輪欄位。
 */
class M2cR3ContentTest extends TestCase
{
    use IsolatesSnapshotStorage;
    use RefreshDatabase;
    use SeedsThreadsCatalog;

    /** @var array<string, mixed> */
    private array $r3 = [];

    protected function setUp(): void
    {
        parent::setUp();

        // ⛔ 快照寫進拋棄式目錄;不得污染 Owner 的真實還原資產。
        $this->isolateSnapshotStorage();

        Http::preventStrayRequests();

        $this->seed(CatalogSeeder::class);
        $this->seedThreadsCatalog();
        Artisan::call('m2c:apply-copy');

        $this->r3 = json_decode((string) file_get_contents(database_path('fixtures/m2c-r3-content.json')), true);
    }

    private function applyR3(): void
    {
        $this->assertSame(0, Artisan::call('m2c:apply-r3'));
    }

    /** @return array<string, string> url => html */
    private function allPages(): array
    {
        $pages = ['/' => null, '/services/instagram' => null, '/services/facebook' => null, '/services/threads' => null];

        foreach (array_values(ProductSlugMap::MAP) as $slug) {
            $pages['/product/'.$slug.'/'] = null;
        }

        foreach (array_keys($pages) as $url) {
            $pages[$url] = $this->get($url)->assertOk()->getContent();
        }

        return $pages;
    }

    public function test_dry_run_validates_but_writes_nothing(): void
    {
        $before = [
            'site' => DB::table('site_settings')->first(),
            'faqs' => Faq::withTrashed()->count(),
            'sections' => ServiceContentSection::query()->count(),
            'snapshots' => count(glob(storage_path('app/private/m2c-snapshots/r3-content-*.json')) ?: []),
        ];

        $this->assertSame(0, Artisan::call('m2c:apply-r3', ['--dry-run' => true]));

        $this->assertStringContainsString('0 writes', Artisan::output());
        $this->assertEquals($before['site'], DB::table('site_settings')->first());
        $this->assertSame($before['faqs'], Faq::withTrashed()->count());
        $this->assertSame($before['sections'], ServiceContentSection::query()->count());
        // dry-run 不建立 snapshot 檔。
        $this->assertSame($before['snapshots'], count(glob(storage_path('app/private/m2c-snapshots/r3-content-*.json')) ?: []));
    }

    public function test_apply_is_transactional_and_second_apply_adds_nothing(): void
    {
        $this->applyR3();
        $first = Artisan::output();

        $this->assertStringContainsString('sections created=16', $first);
        $this->assertStringContainsString('faq created=12', $first);
        $this->assertStringContainsString('removed=7', $first);

        $sections = ServiceContentSection::query()->whereNotNull('managed_key')->count();
        $faqs = Faq::query()->whereNotNull('managed_key')->count();

        $this->applyR3();
        $second = Artisan::output();

        $this->assertStringContainsString('sections created=0', $second);
        $this->assertStringContainsString('faq created=0', $second);
        $this->assertStringContainsString('removed=0', $second);
        $this->assertSame($sections, ServiceContentSection::query()->whereNotNull('managed_key')->count());
        $this->assertSame($faqs, Faq::query()->whereNotNull('managed_key')->count());
    }

    public function test_a_mid_apply_failure_rolls_everything_back(): void
    {
        // 缺一個 mapping 目標 → 整批 fail closed:欄位/段落/FAQ/移除全不落地。
        Service::query()->where('slug', 'post-boost')->forceDelete();

        $before = [
            'site' => DB::table('site_settings')->first(),
            'faqs' => Faq::withTrashed()->orderBy('id')->get(['id', 'question', 'deleted_at'])->toArray(),
            'sections' => ServiceContentSection::query()->count(),
        ];

        $this->assertSame(1, Artisan::call('m2c:apply-r3'));

        $this->assertEquals($before['site'], DB::table('site_settings')->first());
        $this->assertEquals($before['faqs'], Faq::withTrashed()->orderBy('id')->get(['id', 'question', 'deleted_at'])->toArray());
        $this->assertSame($before['sections'], ServiceContentSection::query()->count());
        $this->assertSame(0, ServiceContentSection::query()->whereNotNull('managed_key')->count());
    }

    public function test_fixture_schema_violations_fail_closed(): void
    {
        $path = database_path('fixtures/m2c-r3-content.json');
        $original = file_get_contents($path);

        try {
            // (a) 未知 product slug。
            $bad = json_decode($original, true);
            $bad['services']['不存在的slug'] = $bad['services']['ig買粉絲'];
            file_put_contents($path, json_encode($bad, JSON_UNESCAPED_UNICODE));
            $this->assertSame(1, Artisan::call('m2c:apply-r3', ['--dry-run' => true]));

            // (b) 重複 managed_key。
            $bad = json_decode($original, true);
            $bad['content_sections'][1]['managed_key'] = $bad['content_sections'][0]['managed_key'];
            file_put_contents($path, json_encode($bad, JSON_UNESCAPED_UNICODE));
            $this->assertSame(1, Artisan::call('m2c:apply-r3', ['--dry-run' => true]));

            // (c) 空值。
            $bad = json_decode($original, true);
            $bad['platforms']['instagram']['h1'] = ' ';
            file_put_contents($path, json_encode($bad, JSON_UNESCAPED_UNICODE));
            $this->assertSame(1, Artisan::call('m2c:apply-r3', ['--dry-run' => true]));

            // 三種壞 fixture 全程 0 writes。
            $this->assertSame(0, ServiceContentSection::query()->whereNotNull('managed_key')->count());
            $this->assertSame(0, Faq::query()->whereNotNull('managed_key')->count());
        } finally {
            file_put_contents($path, $original);
        }
    }

    public function test_thirteen_pages_have_unique_titles_descriptions_and_single_h1(): void
    {
        $this->applyR3();

        $titles = [];
        $descriptions = [];

        foreach ($this->allPages() as $url => $html) {
            $this->assertSame(1, substr_count($html, '<h1'), $url);

            preg_match('/<title>([^<]*)<\/title>/u', $html, $t);
            preg_match('/<meta name="description" content="([^"]*)"/u', $html, $d);

            $titles[$url] = $t[1] ?? '';
            $descriptions[$url] = $d[1] ?? '';
        }

        $this->assertSame(13, count(array_unique($titles)), 'Title 必須 13 頁唯一');
        $this->assertSame(13, count(array_unique($descriptions)), 'Description 必須 13 頁唯一');
    }

    public function test_primary_keywords_live_only_in_their_owner_title_and_h1(): void
    {
        $this->applyR3();

        $primaries = [
            '/product/ig買粉絲/' => 'IG買粉絲',
            '/product/ig買like/' => 'IG買讚',
            '/product/ig影片觀看/' => 'IG影片觀看數',
            '/product/fb買粉絲/' => 'FB買粉絲',
            '/product/fb買like/' => 'FB買讚',
            '/product/fb影片觀看/' => 'Facebook影片觀看數',
            '/product/threads買粉絲/' => 'Threads買粉絲',
            '/product/threads買讚/' => 'Threads買讚',
            '/product/threads貼文瀏覽/' => 'Threads瀏覽次數',
        ];

        $headSlices = [];

        foreach ($this->allPages() as $url => $html) {
            preg_match('/<title>([^<]*)<\/title>/u', $html, $t);
            preg_match('/<h1[^>]*>(.*?)<\/h1>/su', $html, $h);
            $headSlices[$url] = ($t[1] ?? '').' '.trim($h[1] ?? '');
        }

        foreach ($primaries as $owner => $word) {
            foreach ($headSlices as $url => $slice) {
                if ($url === $owner) {
                    $this->assertStringContainsString($word, $slice, "{$word} 必須在 owner {$url} 的 Title/H1");
                } else {
                    $this->assertStringNotContainsString($word, $slice, "{$word} 不得出現在 {$url} 的 Title/H1");
                }
            }
        }

        // Hub 不得用商品主詞作 Title/H1(上面 else 分支已涵蓋三個 Hub)。
        $this->assertStringContainsString('Instagram 粉絲、貼文讚與影片觀看服務', $headSlices['/services/instagram']);
    }

    public function test_pages_carry_r3_copy_sections_faqs_and_prices_in_initial_html(): void
    {
        $this->applyR3();
        $pages = $this->allPages();

        // 首頁:H1/首屏/兩個 H2/主 CTA。
        $home = $pages['/'];
        $this->assertStringContainsString($this->r3['site']['home_h1'], $home);
        $this->assertStringContainsString($this->r3['site']['home_intro'], $home);
        $this->assertStringContainsString('選擇 Instagram、Facebook 或 Threads 服務', $home);
        $this->assertStringContainsString('買讚、粉絲與觀看的下單流程', $home);
        $this->assertStringContainsString($this->r3['site']['primary_cta_label'], $home);

        // Hub:R3 H1/tagline/intro 與固定 H2。
        foreach (['instagram', 'facebook', 'threads'] as $slug) {
            $hub = $pages['/services/'.$slug];
            $platform = $this->r3['platforms'][$slug];
            $this->assertStringContainsString($platform['h1'], $hub, $slug);
            $this->assertStringContainsString($platform['tagline'], $hub, $slug);
            $this->assertStringContainsString($platform['intro'], $hub, $slug);
            $name = Platform::query()->where('slug', $slug)->value('name');
            $this->assertStringContainsString('選擇 '.$name.' 服務', $hub, $slug);
            $this->assertStringContainsString($name.' 服務比較', $hub, $slug);
        }

        // 商品頁:summary/intro/H2 段落/FAQ/方案與價格。
        $sectionsBySlug = collect($this->r3['content_sections'])->groupBy('product_slug');
        $faqsBySlug = collect($this->r3['faqs']['service'])->groupBy('product_slug');

        foreach ($this->r3['services'] as $slug => $copy) {
            $html = $pages['/product/'.$slug.'/'];

            $this->assertStringContainsString($copy['summary'], $html, $slug);
            $this->assertStringContainsString($copy['intro'], $html, $slug);

            foreach ($sectionsBySlug->get($slug, collect()) as $section) {
                $this->assertStringContainsString($section['heading'], $html, $section['managed_key']);
                $this->assertStringContainsString($section['body'], $html, $section['managed_key']);
            }

            foreach ($faqsBySlug->get($slug, collect()) as $faq) {
                $this->assertStringContainsString($faq['question'], $html, $faq['managed_key']);
                $this->assertStringContainsString($faq['answer'], $html, $faq['managed_key']);
            }

            // 方案/價格在初始 HTML:至少一個 published variant label+單價欄。
            $service = Service::query()->where('product_slug', $slug)->firstOrFail();
            $variant = $service->variants()->published()->firstOrFail();
            $this->assertStringContainsString((string) $variant->label, $html, $slug);
            $this->assertStringContainsString('單價', $html, $slug);
        }

        /*
         * 全站 FAQ:R5 起 global FAQ 的唯一完整 owner 是 `/faq`,首頁只顯示
         * 核准的 3 題精選並以可爬連結指向 `/faq`。⛔ 這裡只驗「R3 三題的
         * 內容仍可在初始 HTML 讀到」,不再要求全部擠在首頁。
         */
        $faqPage = $this->get('/faq')->assertOk()->getContent();

        foreach ($this->r3['faqs']['global'] as $faq) {
            $this->assertTrue(
                str_contains($home, $faq['question']) || str_contains($faqPage, $faq['question']),
                $faq['managed_key'].' 的問題應出現在首頁精選或 /faq',
            );
            $this->assertTrue(
                str_contains($home, $faq['answer']) || str_contains($faqPage, $faq['answer']),
                $faq['managed_key'].' 的答案應出現在首頁精選或 /faq',
            );
        }

        $this->assertStringContainsString('查看全部常見問題', $home);
    }

    public function test_internal_link_matrix_home_one_hop_and_hub_three_each(): void
    {
        $this->applyR3();
        $pages = $this->allPages();
        $home = $pages['/'];

        // 首頁→三 Hub 的 R3 anchor。
        foreach (['查看 Instagram 粉絲、買讚與觀看', '查看 Facebook 粉絲、買讚與觀看', '查看 Threads 粉絲、買讚與瀏覽'] as $anchor) {
            $this->assertStringContainsString($anchor, $home);
        }

        // 首頁一跳連 9 商品(真 <a href> 直達 canonical)。
        foreach ($this->r3['services'] as $slug => $copy) {
            $service = Service::query()->where('product_slug', $slug)->firstOrFail();
            $this->assertStringContainsString(
                'href="'.$service->primaryUrl().'"',
                $home,
                $slug,
            );
            $this->assertStringContainsString($copy['card_title'], $home, $slug);
        }

        // 三 Hub 各連正確三商品,anchor=R3 card_title。
        $hubMatrix = [
            'instagram' => ['ig買粉絲' => 'IG買粉絲方案', 'ig買like' => 'IG買讚方案', 'ig影片觀看' => 'IG影片觀看方案'],
            'facebook' => ['fb買粉絲' => 'FB買粉絲方案', 'fb買like' => 'FB買讚方案', 'fb影片觀看' => 'Facebook影片觀看方案'],
            'threads' => ['threads買粉絲' => 'Threads買粉絲方案', 'threads買讚' => 'Threads買讚方案', 'threads貼文瀏覽' => 'Threads瀏覽次數方案'],
        ];

        foreach ($hubMatrix as $platformSlug => $links) {
            $hub = $pages['/services/'.$platformSlug];

            foreach ($links as $productSlug => $anchor) {
                $service = Service::query()->where('product_slug', $productSlug)->firstOrFail();
                $this->assertStringContainsString('href="'.$service->primaryUrl().'"', $hub, $productSlug);
                $this->assertStringContainsString($anchor, $hub, $productSlug);
            }
        }

        // 商品頁麵包屑 anchor=「平台名+服務」;其他服務 anchor=card_title。
        $followersHtml = $pages['/product/ig買粉絲/'];
        $this->assertStringContainsString('Instagram服務', $followersHtml);
        $this->assertStringContainsString('IG買讚方案', $followersHtml);

        // 全部頁面:0 個商品級 /services/... 公開內鏈。
        foreach ($pages as $url => $html) {
            $this->assertDoesNotMatchRegularExpression('#href="[^"]*/services/[a-z]+/[a-z][^"]*"#', $html, $url);
        }
    }

    public function test_banned_words_and_unprovable_claims_are_zero_across_thirteen_pages(): void
    {
        $this->applyR3();

        $banned = ['SMM', '第三方供應商', 'API派單', 'service ID', '批發成本', '後端重新驗價', '本機 MOCK', '不會導致帳號被鎖', 'mock', '後端'];

        foreach ($this->allPages() as $url => $html) {
            foreach ($banned as $word) {
                $this->assertStringNotContainsString($word, $html, "{$url} 含禁詞 {$word}");
            }
        }

        // DB 層:被鎖 FAQ 與內部 FAQ 已受控移除(僅 soft delete 指定列)。
        $this->assertSame(0, Faq::query()->where('answer', 'like', '%不會導致帳號被鎖%')->count());
        $this->assertSame(0, Faq::query()->where('question', '現在可以真的付款嗎？')->count());
        // 同題 managed 版本恰一筆,無重複顯示。
        $this->assertSame(1, Faq::query()->where('question', '需要註冊會員嗎？')->count());
    }

    public function test_rollback_restores_only_this_rounds_fields_and_rows(): void
    {
        // ⛔ 還原比較排除 timestamps:rollback 重寫值會更新 updated_at,跨秒即 flake。
        $stripTimes = fn (?object $row): array => collect((array) $row)->except(['created_at', 'updated_at'])->all();
        $beforeSite = $stripTimes(DB::table('site_settings')->first());
        $beforePlatform = Platform::query()->where('slug', 'instagram')->first()
            ->only(['h1', 'tagline', 'intro', 'seo_title', 'meta_description']);
        $beforeService = Service::query()->where('product_slug', 'ig買粉絲')->first()
            ->only(['seo_title', 'h1', 'summary', 'intro', 'card_title', 'card_blurb']);
        $beforeFaqs = Faq::withTrashed()->orderBy('id')->get(['id', 'question', 'answer', 'deleted_at'])->toArray();
        $beforeVariantPrices = DB::table('service_variants')->orderBy('id')->pluck('unit_price', 'id')->all();

        $this->applyR3();
        preg_match('/snapshot=(\S+\.json)/u', Artisan::output(), $m);
        $snapshot = $m[1] ?? '';
        $this->assertFileExists($snapshot);

        $this->assertSame(0, Artisan::call('m2c:apply-r3', ['--rollback' => $snapshot]));

        // 本輪欄位/列全部還原。
        $this->assertEquals($beforeSite, $stripTimes(DB::table('site_settings')->first()));
        $this->assertEquals($beforePlatform, Platform::query()->where('slug', 'instagram')->first()
            ->only(['h1', 'tagline', 'intro', 'seo_title', 'meta_description']));
        $this->assertEquals($beforeService, Service::query()->where('product_slug', 'ig買粉絲')->first()
            ->only(['seo_title', 'h1', 'summary', 'intro', 'card_title', 'card_blurb']));
        $this->assertEquals($beforeFaqs, Faq::withTrashed()->orderBy('id')->get(['id', 'question', 'answer', 'deleted_at'])->toArray());
        $this->assertSame(0, ServiceContentSection::query()->whereNotNull('managed_key')->count());
        $this->assertSame(0, Faq::query()->whereNotNull('managed_key')->count());

        // ⛔ 價格等範圍外資料自始至終不動。
        $this->assertSame($beforeVariantPrices, DB::table('service_variants')->orderBy('id')->pluck('unit_price', 'id')->all());
    }

    public function test_route_canonical_and_noindex_behaviour_does_not_regress(): void
    {
        $this->applyR3();

        $followers = Service::query()->where('product_slug', 'ig買粉絲')->firstOrFail();

        $this->get('/services/instagram/followers')
            ->assertStatus(302)
            ->assertRedirect($followers->primaryUrl());

        $html = $this->get($followers->primaryUrl())->assertOk()->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="'.$followers->primaryUrl().'">', $html);

        $this->get('/')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /');
        // 正式 301 = 0。
        $this->assertNotSame(301, $this->get('/services/instagram/followers')->getStatusCode());
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedSnapshotStorage();

        parent::tearDown();
    }
}
