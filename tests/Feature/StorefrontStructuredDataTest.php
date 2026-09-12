<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\Platform;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\CanonicalUrl;
use Database\Seeders\CatalogSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\SeedsThreadsCatalog;
use Tests\TestCase;

/**
 * M5B：公開頁 JSON-LD。
 *
 * ⭐ 這一份測試的主張不是「有標記」，而是「標記描述的正是這一頁**看得到**
 * 的內容」——Google 的結構化資料規範以此為門檻。所以幾乎每一條都拿
 * 渲染出來的 HTML 跟 JSON 互相對照，⛔ 不只檢查 JSON 自己的形狀。
 *
 * ⛔ 本輪 0 外呼、0 DB 寫入、0 真實交易；⛔ 不公開草稿或客戶資料。
 */
class StorefrontStructuredDataTest extends TestCase
{
    use RefreshDatabase;
    use SeedsThreadsCatalog;

    private const ORIGIN = 'http://localhost:8083';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seedThreadsCatalog();
        Artisan::call('m2c:apply-copy');
    }

    /** ⭐ 保留原始 path 形式（含尾斜線），與 M5A 的做法一致。 */
    private function request(string $path, array $server = []): Response
    {
        $host = $server['HTTP_HOST'] ?? null;

        $url = $host === null
            ? self::ORIGIN.$path
            : (($server['HTTPS'] ?? null) === 'on' ? 'https' : 'http').'://'.$host.$path;

        return app(Kernel::class)->handle(Request::create($url, 'GET', [], [], [], $server));
    }

    /** 從渲染後的 HTML 取出唯一一份 JSON-LD 並解析。 */
    private function graphOf(string $path, array $server = []): array
    {
        $html = (string) $this->request($path, $server)->getContent();

        $blocks = $this->ldJsonBlocks($html);

        $this->assertCount(1, $blocks, "{$path} 必須剛好輸出一個 JSON-LD 區塊。");

        $decoded = json_decode($blocks[0], true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /** @return list<string> */
    private function ldJsonBlocks(string $html): array
    {
        preg_match_all(
            '~<script type="application/ld\+json">(.*?)</script>~s',
            $html,
            $matches
        );

        return $matches[1];
    }

    /** @return array<string, mixed>|null */
    private function nodeOfType(array $graph, string $type): ?array
    {
        foreach ($graph['@graph'] ?? [] as $node) {
            if (($node['@type'] ?? null) === $type) {
                return $node;
            }
        }

        return null;
    }

    // ============================================ 1. 四個頁型的基本圖譜

    public function test_the_home_page_declares_organization_website_and_webpage(): void
    {
        $graph = $this->graphOf('/');

        $this->assertSame('https://schema.org', $graph['@context']);

        $organization = $this->nodeOfType($graph, 'Organization');
        $website = $this->nodeOfType($graph, 'WebSite');
        $page = $this->nodeOfType($graph, 'WebPage');

        $this->assertNotNull($organization);
        $this->assertNotNull($website);
        $this->assertNotNull($page);

        $this->assertSame(self::ORIGIN.'/#organization', $organization['@id']);
        $this->assertSame(self::ORIGIN.'/#website', $website['@id']);
        $this->assertSame(self::ORIGIN.'/#webpage', $page['@id']);

        // 節點互相指得到：⛔ 不得出現指向不存在 @id 的參照。
        $this->assertSame($website['@id'], $page['isPartOf']['@id']);
        $this->assertSame($organization['@id'], $page['about']['@id']);
        $this->assertSame($organization['@id'], $website['publisher']['@id']);
    }

    public function test_a_platform_hub_declares_a_collection_page_and_breadcrumb(): void
    {
        $graph = $this->graphOf('/services/instagram');

        $page = $this->nodeOfType($graph, 'CollectionPage');
        $breadcrumb = $this->nodeOfType($graph, 'BreadcrumbList');

        $this->assertNotNull($page);
        $this->assertNotNull($breadcrumb);

        $canonical = CanonicalUrl::to('/services/instagram');

        $this->assertSame($canonical, $page['url']);
        $this->assertSame($canonical.'#webpage', $page['@id']);
        $this->assertSame($canonical.'#breadcrumb', $page['breadcrumb']['@id']);
        $this->assertSame($canonical.'#breadcrumb', $breadcrumb['@id']);
    }

    public function test_a_product_page_points_its_main_entity_at_a_service(): void
    {
        $graph = $this->graphOf('/product/ig買粉絲/');

        $page = $this->nodeOfType($graph, 'WebPage');
        $service = $this->nodeOfType($graph, 'Service');
        $organization = $this->nodeOfType($graph, 'Organization');

        $this->assertNotNull($page);
        $this->assertNotNull($service);

        $canonical = CanonicalUrl::to('/product/ig買粉絲/');

        $this->assertSame($canonical, $page['url']);
        $this->assertSame($canonical.'#service', $page['mainEntity']['@id']);
        $this->assertSame($canonical.'#service', $service['@id']);
        $this->assertSame($canonical, $service['url']);
        $this->assertSame($organization['@id'], $service['provider']['@id']);
    }

    public function test_the_faq_page_declares_an_faq_page_with_the_visible_questions(): void
    {
        $graph = $this->graphOf('/faq');

        $page = $this->nodeOfType($graph, 'FAQPage');

        $this->assertNotNull($page, '有已發布問答時必須是 FAQPage。');

        $published = Faq::query()->published()->where('scope', 'global')->orderBy('sort_order')->get();

        $this->assertCount($published->count(), $page['mainEntity']);

        foreach ($page['mainEntity'] as $i => $question) {
            $this->assertSame('Question', $question['@type']);
            $this->assertSame($published[$i]->question, $question['name']);
            $this->assertSame('Answer', $question['acceptedAnswer']['@type']);
            $this->assertSame($published[$i]->answer, $question['acceptedAnswer']['text']);
        }
    }

    // ============================================ 2. 14 條 canonical 全覆蓋

    public function test_every_indexable_page_carries_exactly_one_parsable_graph(): void
    {
        $paths = CanonicalUrl::indexablePaths();

        $this->assertCount(14, $paths, '本輪基準就是 14 條可索引 path。');

        $seenPageIds = [];

        foreach ($paths as $path) {
            $graph = $this->graphOf($path);

            $expected = CanonicalUrl::to($path);

            $page = $this->nodeOfType($graph, 'WebPage')
                ?? $this->nodeOfType($graph, 'CollectionPage')
                ?? $this->nodeOfType($graph, 'FAQPage');

            $this->assertNotNull($page, "{$path} 必須有一個頁面節點。");

            // ⛔⛔ 圖譜的 url 必須與這一頁的 canonical 逐字相同。
            $this->assertSame($expected, $page['url'], "{$path} 的圖譜 url 必須等於 canonical。");
            $this->assertSame($expected.'#webpage', $page['@id']);

            // ⛔ 每一頁的 @id 必須唯一,⛔ 不得兩頁共用一個身份。
            $this->assertNotContains($page['@id'], $seenPageIds);
            $seenPageIds[] = $page['@id'];
        }
    }

    public function test_the_graph_url_matches_the_canonical_link_in_the_same_html(): void
    {
        foreach (CanonicalUrl::indexablePaths() as $path) {
            $html = (string) $this->request($path)->getContent();

            preg_match('~<link rel="canonical" href="(.*?)">~', $html, $link);

            $this->assertNotEmpty($link, "{$path} 必須仍有 canonical。");

            $graph = json_decode($this->ldJsonBlocks($html)[0], true, 512, JSON_THROW_ON_ERROR);

            $page = $this->nodeOfType($graph, 'WebPage')
                ?? $this->nodeOfType($graph, 'CollectionPage')
                ?? $this->nodeOfType($graph, 'FAQPage');

            /*
             * ⭐ 這一條才是重點：canonical 與 Schema 是兩份獨立的「這一頁是誰」
             * 宣告。⛔ 兩者不一致就是在對搜尋引擎講兩種話。
             */
            $this->assertSame($link[1], $page['url'], "{$path} 的 canonical 與圖譜 url 必須一致。");
        }
    }

    // ============================================ 3. 標記必須等於看得到的字

    public function test_the_graph_name_is_the_pages_visible_h1(): void
    {
        foreach (CanonicalUrl::indexablePaths() as $path) {
            $html = (string) $this->request($path)->getContent();

            preg_match_all('~<h1[^>]*>(.*?)</h1>~s', $html, $h1);

            $this->assertCount(1, $h1[1], "{$path} 必須只有一個 H1。");

            $visible = trim(html_entity_decode(strip_tags($h1[1][0]), ENT_QUOTES, 'UTF-8'));

            $graph = json_decode($this->ldJsonBlocks($html)[0], true, 512, JSON_THROW_ON_ERROR);

            $page = $this->nodeOfType($graph, 'WebPage')
                ?? $this->nodeOfType($graph, 'CollectionPage')
                ?? $this->nodeOfType($graph, 'FAQPage');

            $this->assertSame($visible, $page['name'], "{$path} 的圖譜名稱必須等於可見 H1。");
        }
    }

    /**
     * ⛔⛔ builder 的 fallback 必須與 Blade 的 fallback 逐字相同。
     *
     * ⭐ 這條是拿掉 DB 值之後才有意義：兩邊各自寫死一份 fallback，
     * 有一天改了一邊就會分歧，而分歧就是「標記宣告了頁面上沒有的名稱」。
     */
    public function test_the_fallback_names_match_the_blade_fallbacks(): void
    {
        /*
         * ⛔ 用空字串,⛔ 不是 null:這幾個欄位是 NOT NULL,而 Blade 的
         * fallback 本來就是 `?:`——實際會觸發 fallback 的值就是空字串。
         */
        SiteSetting::query()->update(['home_h1' => '', 'home_intro' => '']);

        $html = (string) $this->request('/')->getContent();

        preg_match('~<h1[^>]*>(.*?)</h1>~s', $html, $h1);

        $graph = json_decode($this->ldJsonBlocks($html)[0], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            trim($h1[1]),
            $this->nodeOfType($graph, 'WebPage')['name'],
            '首頁 fallback H1 必須與 Blade 相同。'
        );

        $platform = Platform::query()->where('slug', 'instagram')->first();
        $platform->forceFill(['h1' => ''])->saveQuietly();

        $html = (string) $this->request('/services/instagram')->getContent();

        preg_match('~<h1[^>]*>(.*?)</h1>~s', $html, $h1);

        $graph = json_decode($this->ldJsonBlocks($html)[0], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            trim($h1[1]),
            $this->nodeOfType($graph, 'CollectionPage')['name'],
            'Hub fallback H1 必須與 Blade 相同。'
        );

        $service = Service::query()->whereNotNull('product_slug')->first();
        $service->forceFill(['h1' => ''])->saveQuietly();

        $html = (string) $this->request('/product/'.$service->product_slug.'/')->getContent();

        preg_match('~<h1[^>]*>(.*?)</h1>~s', $html, $h1);

        $graph = json_decode($this->ldJsonBlocks($html)[0], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            trim($h1[1]),
            $this->nodeOfType($graph, 'WebPage')['name'],
            '商品 fallback H1 必須與 Blade 相同。'
        );
    }

    public function test_the_breadcrumb_matches_the_visible_breadcrumb(): void
    {
        $service = Service::query()
            ->whereNotNull('product_slug')
            ->with('platform')
            ->first();

        $graph = $this->graphOf('/product/'.$service->product_slug.'/');

        $items = $this->nodeOfType($graph, 'BreadcrumbList')['itemListElement'];

        $this->assertCount(3, $items);

        $this->assertSame(1, $items[0]['position']);
        $this->assertSame('首頁', $items[0]['name']);
        $this->assertSame(CanonicalUrl::to('/'), $items[0]['item']);

        // ⛔ 第二層必須是可見麵包屑的 exact anchor「{平台名}服務」。
        $this->assertSame(2, $items[1]['position']);
        $this->assertSame($service->platform->name.'服務', $items[1]['name']);
        $this->assertSame(CanonicalUrl::to('/services/'.$service->platform->slug), $items[1]['item']);

        // ⛔ 最後一層是目前頁：可見麵包屑那一格不是連結,所以不帶 item。
        $this->assertSame(3, $items[2]['position']);
        $this->assertSame($service->name, $items[2]['name']);
        $this->assertArrayNotHasKey('item', $items[2]);
    }

    public function test_the_hub_breadcrumb_has_two_levels_and_no_self_link(): void
    {
        $graph = $this->graphOf('/services/instagram');

        $items = $this->nodeOfType($graph, 'BreadcrumbList')['itemListElement'];

        $this->assertCount(2, $items);
        $this->assertSame('首頁', $items[0]['name']);
        $this->assertSame(CanonicalUrl::to('/'), $items[0]['item']);
        $this->assertArrayNotHasKey('item', $items[1]);
    }

    // ============================================ 4. published 篩選與空資料

    public function test_a_draft_service_is_not_described_anywhere(): void
    {
        $service = Service::query()->whereNotNull('product_slug')->with('platform')->first();

        $slug = $service->product_slug;
        $name = $service->name;

        $service->forceFill(['status' => 'draft'])->saveQuietly();

        // 該商品頁本身已經是 404，⛔ 不會有圖譜。
        $this->assertSame(404, $this->request('/product/'.$slug.'/')->getStatusCode());

        // ⛔ 它也不得出現在 Hub 或首頁的任何標記裡。
        foreach (['/', '/services/'.$service->platform->slug] as $path) {
            $html = (string) $this->request($path)->getContent();

            $this->assertStringNotContainsString(
                $slug,
                $this->ldJsonBlocks($html)[0],
                "{$path} 的圖譜不得提到草稿商品。"
            );
            $this->assertStringNotContainsString(
                json_encode($name, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
                $this->ldJsonBlocks($html)[0]
            );
        }
    }

    public function test_a_draft_faq_is_not_described(): void
    {
        $faq = Faq::query()->published()->where('scope', 'global')->orderBy('sort_order')->first();

        $question = $faq->question;

        $faq->forceFill(['status' => 'draft'])->saveQuietly();

        $block = $this->ldJsonBlocks((string) $this->request('/faq')->getContent())[0];

        $this->assertStringNotContainsString(
            trim(json_encode($question, JSON_UNESCAPED_SLASHES), '"'),
            $block,
            '草稿問答不得進入標記。'
        );
    }

    public function test_the_faq_page_degrades_to_a_webpage_when_nothing_is_published(): void
    {
        Faq::query()->where('scope', 'global')->update(['status' => 'draft']);

        $graph = $this->graphOf('/faq');

        $this->assertNull($this->nodeOfType($graph, 'FAQPage'), '沒有問答就不得宣稱是 FAQPage。');

        $page = $this->nodeOfType($graph, 'WebPage');

        $this->assertNotNull($page);
        $this->assertArrayNotHasKey('mainEntity', $page, '⛔ 不得輸出空的 mainEntity。');
        $this->assertSame(CanonicalUrl::to('/faq'), $page['url']);
    }

    public function test_a_missing_settings_row_still_produces_a_valid_graph(): void
    {
        SiteSetting::query()->delete();

        $graph = $this->graphOf('/');

        $organization = $this->nodeOfType($graph, 'Organization');

        $this->assertSame('IGLIKEFOLLOW', $organization['name']);
        $this->assertSame(CanonicalUrl::to('/'), $organization['url']);
        $this->assertNotEmpty($this->nodeOfType($graph, 'WebPage')['name']);
    }

    public function test_a_blank_summary_omits_the_description_instead_of_inventing_one(): void
    {
        $service = Service::query()->whereNotNull('product_slug')->first();

        $service->forceFill(['summary' => '   '])->saveQuietly();

        $graph = $this->graphOf('/product/'.$service->product_slug.'/');

        $this->assertArrayNotHasKey(
            'description',
            $this->nodeOfType($graph, 'Service'),
            '⛔ 沒有摘要就省略,⛔ 不編造文字。'
        );
    }

    // ============================================ 5. 安全

    /**
     * ⛔⛔ `</script>` 是這裡唯一會變成 XSS 的路徑。
     *
     * ⭐ 反證方式：把危險字元放進真實 DB 欄位，再確認
     *  (1) HTML 裡的 script 元素沒有被提前關掉；
     *  (2) 標準 JSON parser 解回來的字串與原文**逐字相同**
     *      ——⛔ 不是靠刪字或改寫內容換來的安全。
     */
    public function test_dangerous_text_cannot_break_out_of_the_script_element(): void
    {
        $hostile = '</script><img src=x onerror=alert(1)>"\'&中文'."\n".'第二行';

        $faq = Faq::query()->published()->where('scope', 'global')->orderBy('sort_order')->first();

        $faq->forceFill(['question' => $hostile, 'answer' => $hostile])->saveQuietly();

        $html = (string) $this->request('/faq')->getContent();

        $blocks = $this->ldJsonBlocks($html);

        $this->assertCount(1, $blocks, 'script 元素不得被提前關掉。');

        /*
         * ⛔⛔ 唯一的危險是「HTML parser 提前關掉 script 元素」,
         * ⛔ 不是「文字裡出現 onerror 這幾個字」。
         *
         * ⭐ 我前兩版都把斷言寫錯了,而它們紅了:
         *  1. `assertStringNotContainsString('onerror=alert', $html)`
         *     ——同一段文字本來就會被 Blade 以 `{{ }}` 逸出後印成可見的
         *     問答文字,那是**正確**行為;
         *  2. 改成只看 JSON 區塊仍然錯:`onerror=` 留在 JSON 字串**裡面**
         *     完全無害,而且內容本來就必須逐字保留。
         *
         * ⭐ 正確的斷言是這兩個角括號:只要 `<` `>` 不以字面出現,
         * parser 就不可能在這裡結束 script。
         */
        $this->assertStringNotContainsString('<', $blocks[0], 'JSON-LD 內不得有字面的 `<`。');
        $this->assertStringNotContainsString('>', $blocks[0], 'JSON-LD 內不得有字面的 `>`。');
        $this->assertStringContainsString('\u003C/script\u003E', $blocks[0], '危險序列必須被編碼保留。');

        $graph = json_decode($blocks[0], true, 512, JSON_THROW_ON_ERROR);

        $question = $this->nodeOfType($graph, 'FAQPage')['mainEntity'][0];

        // ⭐ 內容完整保留：安全來自編碼,⛔ 不是來自刪字。
        $this->assertSame($hostile, $question['name']);
        $this->assertSame($hostile, $question['acceptedAnswer']['text']);
    }

    /**
     * ⛔⛔ 偽造 Host 不得污染圖譜。
     *
     * ⭐ 這是 M5A 的教訓：`route()`／`url()` 會採用 request 的 Host，
     * 而圖譜的 `@id`／`url` 正是「這一頁是誰」的機器可讀宣告。
     */
    public function test_a_forged_host_never_appears_in_the_graph(): void
    {
        foreach (['/', '/faq', '/services/instagram', '/product/ig買粉絲/'] as $path) {
            $html = (string) $this->request($path, ['HTTP_HOST' => 'evil.test'])->getContent();

            $blocks = $this->ldJsonBlocks($html);

            if ($blocks === []) {
                // 被轉址時沒有圖譜也是可接受的結果。
                continue;
            }

            $this->assertStringNotContainsString('evil.test', $blocks[0], "{$path} 的圖譜不得含攻擊者的網域。");
            $this->assertStringContainsString(CanonicalUrl::origin(), $blocks[0]);
        }
    }

    public function test_the_graph_never_carries_provider_or_customer_data(): void
    {
        foreach (CanonicalUrl::indexablePaths() as $path) {
            $block = $this->ldJsonBlocks((string) $this->request($path)->getContent())[0];

            foreach (['themostpanel', 'provider_order_id', 'api_key', 'hashkey', 'merchantid', 'price', 'offers'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $forbidden,
                    $block,
                    "{$path} 的圖譜不得出現 {$forbidden}。"
                );
            }
        }
    }

    /**
     * ⭐ Google 的 Organization logo 要求最小 112x112；
     * ⛔ 715x143 的橫式 wordmark 不是方形 logo。
     */
    public function test_the_declared_logo_is_the_square_brand_mark(): void
    {
        $organization = $this->nodeOfType($this->graphOf('/'), 'Organization');

        $this->assertSame(CanonicalUrl::to('/images/iglikefollow-mark.png'), $organization['logo']);

        $size = getimagesize(public_path('images/iglikefollow-mark.png'));

        $this->assertGreaterThanOrEqual(112, $size[0]);
        $this->assertGreaterThanOrEqual(112, $size[1]);
    }

    // ============================================ 6. 不輸出的頁面

    public function test_an_owner_preview_never_emits_a_graph(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        foreach (['/services/instagram?preview=1', '/services/instagram/followers?preview=1'] as $path) {
            $response = $this->actingAs($owner)->get($path);

            $this->assertSame(200, $response->getStatusCode(), "{$path} 應可預覽。");
            $this->assertCount(
                0,
                $this->ldJsonBlocks($response->getContent()),
                "⛔ preview 不得輸出圖譜：{$path}"
            );
        }
    }

    public function test_a_draft_platform_preview_never_emits_a_graph(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        Platform::query()->where('slug', 'instagram')->update(['status' => 'draft']);

        $response = $this->actingAs($owner)->get('/services/instagram?preview=1');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(0, $this->ldJsonBlocks($response->getContent()));
    }

    public function test_utility_and_transactional_pages_never_emit_a_graph(): void
    {
        foreach (['/order-check', '/checkout'] as $path) {
            $response = $this->request($path);

            $this->assertCount(
                0,
                $this->ldJsonBlocks((string) $response->getContent()),
                "⛔ {$path} 不得輸出圖譜。"
            );
        }
    }

    // ============================================ 7. 既有 SEO 不變

    public function test_resuming_a_selection_does_not_change_the_product_graph(): void
    {
        $service = Service::query()->whereNotNull('product_slug')->with('variants')->first();

        $plain = $this->ldJsonBlocks(
            (string) $this->request('/product/'.$service->product_slug.'/')->getContent()
        )[0];

        $variant = $service->variants->first();

        $resumed = $this->withSession([
            'checkout' => ['variant_id' => $variant->id, 'quantity' => $variant->min_quantity],
            'checkout_resume' => true,
        ])->get('/product/'.$service->product_slug.'/');

        $resumedBlock = $this->ldJsonBlocks($resumed->getContent())[0];

        /*
         * ⭐ resume 只改畫面預選的方案，⛔ 不改商品身份——
         * 同一個 canonical 不得有兩種機器可讀描述。
         */
        $this->assertSame($plain, $resumedBlock, 'resume 不得改變圖譜。');
    }

    public function test_removing_the_graph_leaves_the_visible_dom_unchanged(): void
    {
        foreach (CanonicalUrl::indexablePaths() as $path) {
            $html = (string) $this->request($path)->getContent();

            $stripped = preg_replace(
                '~\s*<script type="application/ld\+json">.*?</script>~s',
                '',
                $html
            );

            // ⛔ 拿掉 JSON-LD 之後,⛔ 一個可見元素都不能少或多。
            $this->assertStringNotContainsString('ld+json', $stripped);

            foreach (['<h1', '<title>', 'rel="canonical"', 'name="robots"', 'name="description"'] as $marker) {
                $this->assertSame(
                    substr_count($html, $marker),
                    substr_count($stripped, $marker),
                    "{$path} 移除圖譜後 {$marker} 的數量必須不變。"
                );
            }
        }
    }

    public function test_the_sitemap_and_robots_are_untouched_by_this_round(): void
    {
        $sitemap = $this->request('/sitemap.xml');

        $this->assertSame(200, $sitemap->getStatusCode());

        // ⛔ sitemap 仍只列 14 條 canonical,⛔ 本輪不新增也不移除。
        $this->assertSame(
            14,
            substr_count((string) $sitemap->getContent(), '<loc>'),
            'sitemap 條目數不得因 Schema 而改變。'
        );

        $this->assertCount(0, $this->ldJsonBlocks((string) $sitemap->getContent()));
    }
}
