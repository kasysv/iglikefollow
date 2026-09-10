<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CanonicalUrl;
use App\Support\ProductSlugMap;
use Database\Seeders\CatalogSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\SeedsThreadsCatalog;
use Tests\TestCase;

/**
 * M5A：舊站 URL 一跳收斂到新站 canonical。
 *
 * ⛔⛔ 這一輪處理的是**永久**決定。301 一旦發布，搜尋引擎與外部網站會把
 * 結果記住很久；⭐ 所以每一條規則都要能逐條反證，⛔ 不能靠「大概對」。
 *
 * ⛔⛔ 為什麼很多測試用 `Request::create()` 直接餵 kernel，而不是 `$this->get()`：
 *
 * Laravel 測試 client 的 `prepareUrlForRequest()` 做的是
 * `trim(url($uri), '/')`——它**永遠送不出尾斜線**。
 * ⭐ 我實際讀了 `MakesHttpRequests.php:626-635` 確認這件事。
 *
 * ⛔ 那正是既有程式為什麼有一段 `runningUnitTests()` 的例外：尾斜線收斂
 * 在測試裡會自我迴圈，於是被跳過——**代價是那個行為從來沒被測到**。
 * ⭐ `Request::create()` 保留尾斜線，所以本檔可以真正驗證兩個方向。
 */
class M5aUrlMigrationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsThreadsCatalog;

    private const ORIGIN = 'http://localhost:8083';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->seed(CatalogSeeder::class);
        $this->seedThreadsCatalog();
        Artisan::call('m2c:apply-copy');
    }

    /**
     * ⭐ 送出一個保留原始 path 形式（含尾斜線）的請求。
     */
    private function request(string $path, string $method = 'GET', array $server = []): Response
    {
        return app(Kernel::class)->handle(
            Request::create(self::ORIGIN.$path, $method, [], [], [], $server)
        );
    }

    // ==================================================== 1. 15 條 legacy

    /** @return array<string, array{string, string}> */
    public static function legacyRedirects(): array
    {
        return [
            '/product/ig粉絲/' => ['/product/ig粉絲/', '/product/ig買粉絲/'],
            '/product/買粉絲/' => ['/product/買粉絲/', '/product/ig買粉絲/'],
            '/product/instagramfollow/' => ['/product/instagramfollow/', '/product/ig買粉絲/'],
            '/product/free/' => ['/product/free/', '/product/ig買粉絲/'],
            '/ig買粉絲推薦/' => ['/ig買粉絲推薦/', '/product/ig買粉絲/'],
            '/product/ig買follow/' => ['/product/ig買follow/', '/product/ig買粉絲/'],
            '/product/ig買讚/' => ['/product/ig買讚/', '/product/ig買like/'],
            '/product/互粉互讚/' => ['/product/互粉互讚/', '/product/ig影片觀看/'],
            '/product/facebook買讚/' => ['/product/facebook買讚/', '/product/fb買like/'],
            '/product/臉書買讚/' => ['/product/臉書買讚/', '/product/fb買like/'],
            '/product/facebookpagelike/' => ['/product/facebookpagelike/', '/product/fb買like/'],
            '/product/買粉推薦/' => ['/product/買粉推薦/', '/product/fb影片觀看/'],
            '/product-category/instagram/' => ['/product-category/instagram/', '/services/instagram'],
            '/product-category/facebook/' => ['/product-category/facebook/', '/services/facebook'],
            '/shop/' => ['/shop/', '/'],
        ];
    }

    /**
     * ⛔⛔ 每一條 legacy 都必須**一跳直達最終** canonical。
     *
     * ⭐ 「一跳」是本輪的核心：舊 URL 同時有 host、scheme、path、尾斜線
     * 四個問題，若各轉一次，使用者要跑四趟、Googlebot 會直接放棄。
     */
    #[DataProvider('legacyRedirects')]
    public function test_every_legacy_path_lands_on_its_final_target_in_one_hop(
        string $from,
        string $to,
    ): void {
        $response = $this->request($from);

        $this->assertSame(301, $response->getStatusCode(), "⛔ {$from} 必須是 301。");
        $this->assertSame(
            CanonicalUrl::to($to),
            $response->headers->get('Location'),
            "⛔⛔ {$from} 必須一跳直達 {$to}。",
        );
    }

    /** ⛔ 無尾斜線的同一條 legacy 也必須一跳直達，⛔ 不得先補斜線。 */
    #[DataProvider('legacyRedirects')]
    public function test_the_slashless_variant_also_lands_directly(string $from, string $to): void
    {
        $slashless = rtrim($from, '/');

        if ($slashless === '') {
            $this->markTestSkipped('根路徑沒有無斜線變體。');
        }

        $response = $this->request($slashless);

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(CanonicalUrl::to($to), $response->headers->get('Location'));
    }

    /**
     * ⛔ percent-encoded 中文與 raw UTF-8 必須落在同一條規則。
     *
     * ⭐ 外部連結兩種形式都有：貼在 LINE／FB 的通常是 encoded，
     * 手打或從舊後台複製的是 raw。⛔ 只支援一種等於漏掉一半流量。
     */
    #[DataProvider('legacyRedirects')]
    public function test_the_percent_encoded_variant_lands_identically(string $from, string $to): void
    {
        $encoded = implode('/', array_map(
            static fn (string $segment): string => rawurlencode($segment),
            explode('/', $from),
        ));

        $response = $this->request($encoded);

        $this->assertSame(301, $response->getStatusCode(), "⛔ encoded {$encoded} 必須是 301。");
        $this->assertSame(CanonicalUrl::to($to), $response->headers->get('Location'));
    }

    /** ⛔ HEAD 與 GET 行為必須一致——爬蟲大量使用 HEAD。 */
    #[DataProvider('legacyRedirects')]
    public function test_head_behaves_like_get(string $from, string $to): void
    {
        $response = $this->request($from, 'HEAD');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(CanonicalUrl::to($to), $response->headers->get('Location'));
    }

    /**
     * ⛔⛔ 永久轉址必須丟棄 query 與 fragment。
     *
     * ⭐ 舊站是 WooCommerce，外部連結掛著 `?add-to-cart=`、`?orderby=`
     * 這類參數。帶到新站沒有意義，⛔ 但會產生無限多個 URL 變體，
     * 每一個都要被爬、被去重。
     */
    public function test_permanent_redirects_drop_legacy_query_strings(): void
    {
        $response = $this->request('/shop/?add-to-cart=123&orderby=popularity');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(
            self::ORIGIN.'/',
            $response->headers->get('Location'),
            '⛔⛔ WooCommerce 參數不得被帶到新站。',
        );
    }

    // ==================================================== 2. 9 條 services alias

    /** @return array<string, array{string, string}> */
    public static function productAliases(): array
    {
        $cases = [];

        foreach (ProductSlugMap::MAP as $key => $productSlug) {
            $cases['/services/'.$key] = ['/services/'.$key, '/product/'.$productSlug.'/'];
        }

        return $cases;
    }

    /** ⛔ 商品級 alias 由 302 改為**正式 301**，且直達最終商品 URL。 */
    #[DataProvider('productAliases')]
    public function test_each_product_alias_is_a_permanent_redirect(string $from, string $to): void
    {
        $response = $this->request($from);

        $this->assertSame(301, $response->getStatusCode(), "⛔ {$from} 必須是 301（不再是 302）。");
        $this->assertSame(CanonicalUrl::to($to), $response->headers->get('Location'));
    }

    /** ⛔ guest 自己加 `?preview=1` 不得看到 preview，仍須永久收斂且不留 query。 */
    #[DataProvider('productAliases')]
    public function test_a_guest_cannot_use_preview_to_reach_the_alias(string $from, string $to): void
    {
        $response = $this->request($from.'?preview=1');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(
            CanonicalUrl::to($to),
            $response->headers->get('Location'),
            '⛔⛔ guest 的 preview 參數不得保留在 Location。',
        );
    }

    /** ⭐ 授權的 Owner preview 仍是 200＋noindex＋無 canonical。 */
    public function test_an_authorised_owner_preview_still_renders(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $response = $this->actingAs($owner)
            ->get('/services/instagram/followers?preview=1')
            ->assertOk();

        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        $this->assertStringNotContainsString('rel="canonical"', $response->getContent());
    }

    /** ⛔ comments／auto-likes 不在 ProductSlugMap：維持 404，⛔ 不轉址。 */
    public function test_services_without_a_product_slug_stay_404(): void
    {
        foreach (['/services/instagram/comments', '/services/instagram/auto-likes'] as $path) {
            $response = $this->request($path);

            $this->assertSame(404, $response->getStatusCode(), "⛔ {$path} 必須是 404，⛔ 不得轉址。");
        }
    }

    // ==================================================== 3. 尾斜線

    /** ⛔ 商品 canonical 固定**有**尾斜線；無尾斜線一跳補上。 */
    public function test_product_urls_converge_on_the_trailing_slash(): void
    {
        foreach (ProductSlugMap::MAP as $productSlug) {
            $response = $this->request('/product/'.$productSlug);

            $this->assertSame(301, $response->getStatusCode());
            $this->assertSame(
                CanonicalUrl::to('/product/'.$productSlug.'/'),
                $response->headers->get('Location'),
            );

            // ⭐ 有尾斜線的形式本身是 200，⛔ 不再轉。
            $this->assertSame(200, $this->request('/product/'.$productSlug.'/')->getStatusCode());
        }
    }

    /** ⛔ FAQ／Hub／utility 固定**無**尾斜線；有尾斜線一跳去掉。 */
    public function test_non_product_canonicals_converge_on_no_trailing_slash(): void
    {
        $paths = ['/faq', '/services/instagram', '/services/facebook',
            '/services/threads', '/checkout', '/order-check'];

        foreach ($paths as $path) {
            $response = $this->request($path.'/');

            $this->assertSame(301, $response->getStatusCode(), "⛔ {$path}/ 必須 301。");
            $this->assertSame(
                CanonicalUrl::to($path),
                $response->headers->get('Location'),
                "⛔ {$path}/ 必須收斂到無尾斜線。",
            );
        }
    }

    /** ⛔⛔ canonical 自己**絕不**再轉址——那會是無限迴圈。 */
    public function test_canonical_paths_never_redirect_to_themselves(): void
    {
        foreach (CanonicalUrl::indexablePaths() as $path) {
            $response = $this->request($path);

            $this->assertNotSame(
                301,
                $response->getStatusCode(),
                "⛔⛔ canonical {$path} 不得再轉址（自我迴圈）。",
            );
        }
    }

    // ==================================================== 4. 410 與 404

    /** ⭐ Owner 指定：`/cart` 與 `/my-account` 四種形式皆為真 410。 */
    public function test_the_removed_woocommerce_pages_are_gone(): void
    {
        foreach (['/cart', '/cart/', '/my-account', '/my-account/'] as $path) {
            $response = $this->request($path);

            $this->assertSame(
                410,
                $response->getStatusCode(),
                "⛔⛔ {$path} 必須是 410（不是 301／302／200／404）。",
            );
        }
    }

    /** ⛔⛔ 未知 path 一律真 404，⛔ 嚴禁 catch-all 轉首頁。 */
    public function test_unknown_paths_are_real_404s(): void
    {
        foreach (['/nonexistent-xyz', '/product/does-not-exist/', '/random/deep/path'] as $path) {
            $response = $this->request($path);

            $this->assertSame(404, $response->getStatusCode(), "⛔ {$path} 必須是真 404。");
            $this->assertNull(
                $response->headers->get('Location'),
                '⛔⛔ 未知 path 不得被轉去首頁。',
            );
        }
    }

    // ==================================================== 5. Host 安全

    /**
     * ⛔⛔ 惡意 Host header 不得污染 Location、canonical 或 sitemap。
     *
     * ⭐ 這是本輪最重要的安全性質：若我們用 request 的 Host 組 Location，
     * 攻擊者送一個 `Host: evil.test` 就能把我們的使用者與 SEO 權重導走。
     */
    public function test_a_hostile_host_header_cannot_poison_redirects(): void
    {
        $response = $this->request('/shop/', 'GET', ['HTTP_HOST' => 'evil.test']);

        $location = (string) $response->headers->get('Location');

        $this->assertStringStartsWith(
            self::ORIGIN,
            $location,
            '⛔⛔ Location 必須來自 trusted APP_URL，⛔ 不是 request Host。',
        );
        $this->assertStringNotContainsString('evil.test', $location);
    }

    /** ⛔ 惡意 Host 也不得進入 sitemap。 */
    public function test_a_hostile_host_header_cannot_poison_the_sitemap(): void
    {
        $body = $this->request('/sitemap.xml', 'GET', ['HTTP_HOST' => 'evil.test'])->getContent();

        $this->assertStringNotContainsString('evil.test', (string) $body);
        $this->assertStringContainsString(self::ORIGIN.'/', (string) $body);
    }

    /**
     * ⛔⛔ 非正式 host（staging／localhost）**絕不**被導向正式站。
     *
     * ⭐ 把 staging 流量導到正式站會讓測試訂單變成真實訂單
     * ——那是會花錢、會開發票的錯誤。
     */
    public function test_a_staging_host_is_never_forced_to_production(): void
    {
        $response = $this->request('/faq', 'GET', ['HTTP_HOST' => 'staging.iglikefollow.com']);

        $this->assertNotSame(
            301,
            $response->getStatusCode(),
            '⛔⛔ staging host 不得被導向正式站。',
        );
    }

    // ==================================================== 6. sitemap

    /** ⛔ exact 14 條，順序固定，且與 legacy／alias／utility 交集為 0。 */
    public function test_the_sitemap_contains_exactly_the_fourteen_canonicals(): void
    {
        $response = $this->request('/sitemap.xml');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            'application/xml; charset=UTF-8',
            $response->headers->get('Content-Type'),
        );

        $body = (string) $response->getContent();

        preg_match_all('#<loc>(.*?)</loc>#u', $body, $matches);
        $urls = $matches[1];

        $this->assertCount(14, $urls, '⛔ sitemap 必須 exact 14 條。');
        $this->assertSame($urls, array_values(array_unique($urls)), '⛔ 不得重複。');

        // ⭐ 順序固定：首頁、FAQ、3 Hub、9 商品。
        $this->assertSame(CanonicalUrl::to('/'), $urls[0]);
        $this->assertSame(CanonicalUrl::to('/faq'), $urls[1]);
        $this->assertSame(CanonicalUrl::to('/services/instagram'), $urls[2]);

        // ⛔ 交集為 0：任何 legacy 來源或商品級 alias 都不得出現。
        foreach (array_keys(self::legacyRedirects()) as $legacy) {
            $this->assertStringNotContainsString(
                self::ORIGIN.rtrim($legacy, '/').'<',
                $body,
                "⛔⛔ sitemap 不得含 redirect 來源：{$legacy}",
            );
        }

        foreach (array_keys(ProductSlugMap::MAP) as $key) {
            $this->assertStringNotContainsString(
                '/services/'.$key,
                $body,
                '⛔⛔ sitemap 不得含商品級 alias。',
            );
        }

        // ⛔ utility／交易／後台一律不得出現。
        foreach (['/checkout', '/order-check', '/admin', '/api/health', 'preview'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "⛔ sitemap 不得含 {$forbidden}");
        }
    }

    /** ⛔ sitemap 上的每一條都必須是 200、非轉址的真實 canonical。 */
    public function test_every_sitemap_url_is_a_live_canonical(): void
    {
        foreach (CanonicalUrl::indexablePaths() as $path) {
            $response = $this->request($path);

            $this->assertSame(200, $response->getStatusCode(), "⛔ sitemap URL {$path} 必須 200。");

            $html = (string) $response->getContent();

            // ⛔ 唯一 H1。
            $this->assertSame(1, substr_count($html, '<h1'), "⛔ {$path} 必須只有一個 H1。");

            // ⛔ 唯一 absolute self-canonical。
            preg_match_all('#<link rel="canonical" href="([^"]+)"#', $html, $m);
            $this->assertCount(1, $m[1], "⛔ {$path} 必須有唯一 canonical。");
            $this->assertSame(
                CanonicalUrl::to($path),
                $m[1][0],
                "⛔ {$path} 的 canonical 必須是 absolute self-canonical。",
            );
        }
    }

    // ==================================================== 7. 一跳驗證

    /**
     * ⛔⛔ 自行追 Location：任何來源都不得超過一跳，最終必須是 200 canonical。
     *
     * ⭐ 這是施工單特別要求的一支測試。它抓的是「每個規則單獨看都對、
     * 合起來卻串成鏈」——例如先補斜線再換 path。
     */
    public function test_no_source_url_needs_more_than_one_hop(): void
    {
        $sources = array_merge(
            array_column(self::legacyRedirects(), 0),
            array_column(self::productAliases(), 0),
            ['/product/ig買粉絲', '/faq/', '/services/instagram/'],
        );

        foreach ($sources as $source) {
            $hops = 0;
            $path = $source;

            while (true) {
                $response = $this->request($path);

                if ($response->getStatusCode() !== 301) {
                    break;
                }

                $hops++;

                $this->assertLessThanOrEqual(
                    1,
                    $hops,
                    "⛔⛔ {$source} 超過一跳——形成 redirect chain。",
                );

                $location = (string) $response->headers->get('Location');
                $path = (string) parse_url($location, PHP_URL_PATH);
            }

            $this->assertSame(
                200,
                $response->getStatusCode(),
                "⛔ {$source} 的最終目標必須是 200 canonical。",
            );
        }
    }

    // ==================================================== 8. 既有行為回歸

    /** ⛔ 交易與後台頁維持 noindex；⛔ payment callback 不受影響。 */
    public function test_utility_pages_keep_their_noindex(): void
    {
        foreach (['/order-check', '/checkout'] as $path) {
            $response = $this->request($path);

            $this->assertStringContainsString(
                'noindex',
                (string) $response->headers->get('X-Robots-Tag'),
                "⛔ {$path} 必須保持 noindex。",
            );
        }
    }

    /** ⛔⛔ POST 語意不得被本輪改動——對 POST 發 301 會讓瀏覽器改用 GET 重送。 */
    public function test_post_requests_are_never_redirected_by_the_middleware(): void
    {
        $response = $this->request('/shop/', 'POST');

        $this->assertNotSame(
            301,
            $response->getStatusCode(),
            '⛔⛔ middleware 不得改動任何 POST 的語意。',
        );
    }

    /** ⛔ 站內連結不得指向 legacy 或商品級 alias。 */
    public function test_internal_links_never_point_at_legacy_or_alias_urls(): void
    {
        foreach (CanonicalUrl::indexablePaths() as $path) {
            $html = (string) $this->request($path)->getContent();

            foreach (array_keys(self::legacyRedirects()) as $legacy) {
                $this->assertStringNotContainsString(
                    'href="'.rtrim($legacy, '/').'"',
                    $html,
                    "⛔ {$path} 不得連到 legacy：{$legacy}",
                );
            }

            foreach (array_keys(ProductSlugMap::MAP) as $key) {
                $this->assertStringNotContainsString(
                    'href="/services/'.$key.'"',
                    $html,
                    "⛔ {$path} 不得連到商品級 alias。",
                );
            }
        }
    }
}
