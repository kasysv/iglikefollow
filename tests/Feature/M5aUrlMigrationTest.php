<?php

namespace Tests\Feature;

use App\Http\Middleware\CanonicalUrlRedirect;
use App\Models\Platform;
use App\Models\Service;
use App\Models\User;
use App\Support\CanonicalUrl;
use App\Support\ProductSlugMap;
use Database\Seeders\CatalogSeeder;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
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
        /*
         * BLOCKER I hit while writing the production matrix: Request::create()
         * takes the host from the URL argument and IGNORES HTTP_HOST in the
         * server bag. Prefixing self::ORIGIN unconditionally meant every
         * "hostile host" case actually ran against localhost, so the matrix
         * silently proved nothing. Build the URL from the requested host
         * instead, and derive the scheme from HTTPS so isSecure() is real.
         */
        $host = $server['HTTP_HOST'] ?? null;

        if ($host === null) {
            $url = self::ORIGIN.$path;
        } else {
            $scheme = ($server['HTTPS'] ?? null) === 'on' ? 'https' : 'http';
            $url = $scheme.'://'.$host.$path;
        }

        return app(Kernel::class)->handle(
            Request::create($url, $method, [], [], [], $server)
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

    // ==================================================== 9. R1：HTML canonical 不得被 Host 污染

    /**
     * ⛔⛔ R1：`Host: evil.test` 不得污染**任何頁面的 HTML canonical 或內鏈**。
     *
     * ⭐ GPT 以真實 HTTP 反證了初版的漏洞：我只保護了 middleware 的 Location
     * 與 sitemap，⛔ 但 `StorefrontController` 與 `Service::primaryUrl()`
     * 仍用 request-aware 的 `route()`／`url()`，於是商品頁的
     * `<link rel="canonical">` 直接變成 `http://evil.test/product/...`。
     *
     * ⛔ canonical 是我們**主動告訴搜尋引擎**「這一頁的正身在哪裡」，
     * 被污染等於親手把權重指給攻擊者——比 Location 被污染更嚴重。
     */
    public function test_a_hostile_host_cannot_poison_any_html_canonical(): void
    {
        foreach (CanonicalUrl::indexablePaths() as $path) {
            $html = (string) $this->request($path, 'GET', ['HTTP_HOST' => 'evil.test'])->getContent();

            $this->assertStringNotContainsString('evil.test', $html, $path);

            preg_match_all('#<link rel="canonical" href="([^"]+)"#', $html, $m);

            $this->assertCount(1, $m[1], $path);
            $this->assertSame(CanonicalUrl::to($path), $m[1][0], $path);
        }
    }

    /** ⛔ Hub 頁列出的商品內鏈也必須是 trusted origin。 */
    public function test_a_hostile_host_cannot_poison_internal_product_links(): void
    {
        foreach (['/services/instagram', '/services/facebook', '/services/threads'] as $hub) {
            $html = (string) $this->request($hub, 'GET', ['HTTP_HOST' => 'evil.test'])->getContent();

            $this->assertStringNotContainsString('evil.test', $html, $hub);
        }
    }

    // ==================================================== 10. R1：fail closed

    /**
     * ⛔⛔ 商品停用後：canonical 404、alias 404、對應 legacy 不轉、退出 sitemap。
     *
     * ⭐ 這是 GPT 指出的 B 類缺口：初版只查固定 mapping，不確認目標仍 live，
     * ⛔ 於是商品一停用就留下永久 301 → 404。
     */
    public function test_unpublishing_a_product_closes_every_route_that_pointed_at_it(): void
    {
        $service = Service::query()->where('product_slug', 'ig買粉絲')->firstOrFail();

        // ⭐ 先確認停用前是通的（⛔ 否則後面的斷言可能只是碰巧成立）。
        $this->assertSame(200, $this->request('/product/ig買粉絲/')->getStatusCode());
        $this->assertSame(301, $this->request('/services/instagram/followers')->getStatusCode());
        $this->assertSame(301, $this->request('/product/ig粉絲/')->getStatusCode());

        $service->forceFill(['status' => 'draft'])->save();

        // ⛔ canonical 本身 404。
        $this->assertSame(404, $this->request('/product/ig買粉絲/')->getStatusCode());

        // ⛔ alias 不得 301 到 404。
        $this->assertSame(404, $this->request('/services/instagram/followers')->getStatusCode());

        // ⛔ 指向它的 legacy 也不得再轉。
        foreach (['/product/ig粉絲/', '/product/買粉絲/', '/product/free/'] as $legacy) {
            $this->assertSame(404, $this->request($legacy)->getStatusCode(), $legacy);
        }

        // ⛔ sitemap 移除該商品。
        $body = (string) $this->request('/sitemap.xml')->getContent();
        $this->assertStringNotContainsString(CanonicalUrl::to('/product/ig買粉絲/'), $body);
        $this->assertSame(13, substr_count($body, '<loc>'));
    }

    /**
     * ⛔⛔ 平台停用後：Hub 404、分類 legacy 不轉、Hub 與旗下商品全部退出 sitemap。
     */
    public function test_unpublishing_a_platform_closes_its_hub_and_products(): void
    {
        $platform = Platform::query()->where('slug', 'instagram')->firstOrFail();

        $this->assertSame(200, $this->request('/services/instagram')->getStatusCode());

        $platform->forceFill(['status' => 'draft'])->save();

        // ⛔ Hub 404。
        $this->assertSame(404, $this->request('/services/instagram')->getStatusCode());

        // ⛔ 分類 legacy 不得 301 到 404 的 Hub。
        $this->assertSame(404, $this->request('/product-category/instagram/')->getStatusCode());

        // ⛔ Hub 與旗下 3 個商品都退出 sitemap。
        $body = (string) $this->request('/sitemap.xml')->getContent();

        $this->assertStringNotContainsString(CanonicalUrl::to('/services/instagram'), $body);

        foreach (['ig買粉絲', 'ig買like', 'ig影片觀看'] as $slug) {
            $this->assertStringNotContainsString(CanonicalUrl::to('/product/'.$slug.'/'), $body);
        }

        $this->assertSame(10, substr_count($body, '<loc>'));
    }

    /** ⭐ 資料恢復後，預設集合仍 exact 14。 */
    public function test_the_default_catalog_still_yields_exactly_fourteen(): void
    {
        $body = (string) $this->request('/sitemap.xml')->getContent();

        $this->assertSame(14, substr_count($body, '<loc>'));
    }

    // ==================================================== 11. R1：測試 client 尾斜線

    /**
     * ⭐ R1：`tests/TestCase.php` 的 `prepareUrlForRequest()` override 生效。
     *
     * ⛔ Laravel 原生會 `trim(url($uri), '/')`，讓 47 處既有
     * `$this->get('/product/x/')` 實際送出 slashless，於是正確的 301
     * 被誤讀成迴歸。⭐ 修基礎設施，⛔ 不逐檔改呼叫、⛔ 也不把例外放回 production。
     */
    public function test_the_test_client_now_preserves_a_trailing_slash(): void
    {
        // ⭐ 一般 `$this->get()` 帶尾斜線 → 真的送出尾斜線 → 200。
        $this->get('/product/ig買粉絲/')->assertOk();

        // ⛔ slashless 仍一跳 301 到尾斜線 canonical。
        $this->get('/product/ig買粉絲')
            ->assertStatus(301)
            ->assertRedirect(CanonicalUrl::to('/product/ig買粉絲/'));

        // ⛔ query string 不得被 override 弄丟。
        $this->get('/product/ig買粉絲/?utm_source=test')->assertOk();
    }

    // ==================================================== 12. R1：正式 origin 矩陣

    /** @return array<string, array{string, bool, string, string}> */
    public static function productionOriginMatrix(): array
    {
        $cases = [];

        foreach ([['http', false], ['https', true]] as [$scheme, $secure]) {
            foreach (['www.iglikefollow.com', 'iglikefollow.com'] as $host) {
                $label = $scheme.' + '.(str_starts_with($host, 'www.') ? 'www' : 'apex');

                $cases[$label.' + canonical'] = [$host, $secure, '/faq', '/faq'];
                $cases[$label.' + legacy'] = [$host, $secure, '/shop/', '/'];
            }
        }

        return $cases;
    }

    /**
     * ⛔⛔ 正式 origin 矩陣：任何組合最多一跳直達 `https://www.iglikefollow.com`。
     *
     * ⭐ 只有 `https + www + canonical` 該是 200；其餘三種 canonical 組合
     * （http/www、http/apex、https/apex）都必須**一次**收斂到正式 origin。
     */
    #[DataProvider('productionOriginMatrix')]
    public function test_the_production_origin_matrix_converges_in_one_hop(
        string $host,
        bool $secure,
        string $from,
        string $finalPath,
    ): void {
        config(['app.url' => 'https://www.iglikefollow.com']);

        $server = ['HTTP_HOST' => $host];

        if ($secure) {
            $server['HTTPS'] = 'on';
        }

        $response = $this->request($from, 'GET', $server);

        $isCanonicalRequest = $from === $finalPath;
        $alreadyOnCanonicalOrigin = $secure && $host === 'www.iglikefollow.com';

        if ($isCanonicalRequest && $alreadyOnCanonicalOrigin) {
            $this->assertSame(200, $response->getStatusCode());

            return;
        }

        $this->assertSame(301, $response->getStatusCode(), $host.$from);
        $this->assertSame(
            'https://www.iglikefollow.com'.CanonicalUrl::encodePath($finalPath),
            $response->headers->get('Location'),
            $host.$from,
        );
    }

    /**
     * staging 與 hostile host 絕不被導向正式站。
     *
     * R2：原本這裡寫成「如果是 301，就檢查它指向正式站」——那等於**允許**
     * 它 301 到 production，正是這條測試該禁止的行為。改為直接斷言
     * 不得發生任何轉址。
     */
    public function test_staging_and_hostile_hosts_are_never_forced_to_production(): void
    {
        config(['app.url' => 'https://www.iglikefollow.com']);

        foreach (['staging.iglikefollow.com', 'evil.test'] as $host) {
            $response = $this->request('/faq', 'GET', ['HTTP_HOST' => $host]);

            $this->assertNotSame(
                301,
                $response->getStatusCode(),
                $host.' 不得被導向正式站。',
            );

            $this->assertNull(
                $response->headers->get('Location'),
                $host.' 不得產生任何 Location。',
            );
        }
    }

    // ==================================================== 13. R2：交易路由不進 SEO 正規化

    /** @return array<string, array{string}> */
    public static function bypassedTransactionPaths(): array
    {
        return [
            '/payments/{ref}/status' => ['/payments/review-REFERENCE/status'],
            '/payments/linepay confirm' => ['/payments/linepay/review-REFERENCE/confirm'],
            '/payments/linepay cancel' => ['/payments/linepay/review-REFERENCE/cancel'],
            '/admin' => ['/admin/login'],
            '/api' => ['/api/health'],
            '/up' => ['/up'],
        ];
    }

    /**
     * R2：交易／後台／API 的原始 path 與 query 必須原封不動抵達 next handler。
     *
     * GPT 的 probe 重現了真正的風險：
     * `/payments/review-REFERENCE/status?review_token=keep-me` 被 SEO
     * 正規化改寫成 `/payments/review-reference/status`——整個 path 轉小寫、
     * query 消失。`LinePayReturnController::identityMatches()` 需要比對
     * `orderId` 與 `transactionId`，那種改寫會讓付款確認拿不到必要資料。
     *
     * 這裡直接斷言 next handler 收到的 path 與 query，而不是只檢查
     * 「最終不是 301」——後者在 handler 收到被改寫的值時也會通過。
     */
    #[DataProvider('bypassedTransactionPaths')]
    public function test_transaction_routes_reach_their_handler_untouched(string $path): void
    {
        config(['app.url' => 'https://www.iglikefollow.com']);

        $seen = null;

        $request = Request::create('https://iglikefollow.com'.$path.'?review_token=keep-ME&b=2');

        $response = app(CanonicalUrlRedirect::class)->handle(
            $request,
            function (Request $r) use (&$seen) {
                $seen = ['path' => $r->getPathInfo(), 'query' => $r->query()];

                return response('ok', 200);
            },
        );

        $this->assertSame(200, $response->getStatusCode(), $path.' 不得被 SEO 轉址。');
        $this->assertNotNull($seen, $path.' 必須抵達 next handler。');

        // path 的大小寫逐字保留（`review-REFERENCE` 不得變成小寫）。
        $this->assertSame($path, $seen['path'], $path.' 的 path 被改寫了。');

        /*
         * query 逐項比對其**值**，不比對字串順序：
         * `Request::getQueryString()` 會依鍵名排序後重組，
         * 所以字串比對會因排序而假性失敗（我第一版就踩到）。
         * 真正要證明的是「值與大小寫沒有被動過、也沒有被丟掉」。
         */
        $this->assertSame(
            ['review_token' => 'keep-ME', 'b' => '2'],
            $seen['query'],
            $path.' 的 query 被改寫或遺失了。',
        );
    }

    /** POST 也不得被改寫（付款回呼常是 POST）。 */
    public function test_transaction_routes_are_untouched_for_post_too(): void
    {
        config(['app.url' => 'https://www.iglikefollow.com']);

        $seen = null;

        $request = Request::create('https://iglikefollow.com/payments/ecpay/callback?Token=KEEP', 'POST');

        $response = app(CanonicalUrlRedirect::class)->handle(
            $request,
            function (Request $r) use (&$seen) {
                $seen = ['path' => $r->getPathInfo(), 'query' => $r->query()];

                return response('ok', 200);
            },
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('/payments/ecpay/callback', $seen['path']);
        $this->assertSame(['Token' => 'KEEP'], $seen['query']);
    }

    /** 只是字首相同的 path 不算交易路由，仍照常 404。 */
    public function test_a_lookalike_prefix_is_not_treated_as_a_transaction_route(): void
    {
        $this->assertSame(404, $this->request('/apixyz')->getStatusCode());
        $this->assertSame(404, $this->request('/payments-not-real')->getStatusCode());
    }

    /** `/checkout` 的子路徑不得因前綴被 SEO 轉址。 */
    public function test_checkout_subpaths_are_not_seo_redirected(): void
    {
        $seen = null;

        $response = app(CanonicalUrlRedirect::class)->handle(
            Request::create('http://localhost:8083/checkout/start', 'GET'),
            function (Request $r) use (&$seen) {
                $seen = $r->getPathInfo();

                return response('ok', 200);
            },
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('/checkout/start', $seen);
    }

    // ==================================================== 14. R2：405／404 邊界

    /**
     * R2：真實存在的路由用錯 method 必須保留 405 與 Allow header。
     *
     * R1 把所有 405 一律改成 404，那會改變既有路由的 method 語意：
     * `/faq` 確實存在、只是 GET-only，回 404 等於謊稱這一頁不存在。
     */
    public function test_an_existing_route_keeps_a_real_405(): void
    {
        foreach (['/faq', '/up'] as $path) {
            $response = $this->request($path, 'POST');

            $this->assertSame(405, $response->getStatusCode(), $path.' 必須保留 405。');
            $this->assertNotNull(
                $response->headers->get('Allow'),
                $path.' 的 405 必須帶 Allow header。',
            );
        }
    }

    /** 完全不存在的 path 在任何 method 下都是真 404。 */
    public function test_an_unknown_path_is_404_for_every_method(): void
    {
        foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
            $this->assertSame(
                404,
                $this->request('/order-lookup', $method)->getStatusCode(),
                '/order-lookup '.$method.' 必須是 404。',
            );
        }
    }

    // ==================================================== 15. R2：完整商品 host 矩陣

    /** @return array<string, array{string, bool}> */
    public static function productionHosts(): array
    {
        return [
            'http + www' => ['www.iglikefollow.com', false],
            'http + apex' => ['iglikefollow.com', false],
            'https + apex' => ['iglikefollow.com', true],
            'https + www' => ['www.iglikefollow.com', true],
        ];
    }

    /**
     * R2：9 個商品 canonical 在四種 host／scheme 下，最多一跳抵達 200。
     *
     * R1 的缺口：已帶尾斜線的商品 URL 在 apex 上被 301 到 www 時
     * 丟掉尾斜線，下一次請求又補回來——兩跳。
     */
    #[DataProvider('productionHosts')]
    public function test_every_product_canonical_converges_in_one_hop(string $host, bool $secure): void
    {
        config(['app.url' => 'https://www.iglikefollow.com']);

        foreach (ProductSlugMap::MAP as $slug) {
            foreach (['/product/'.$slug.'/', '/product/'.$slug] as $from) {
                $this->assertConvergesInOneHop($from, '/product/'.$slug.'/', $host, $secure);
            }
        }
    }

    /** R2：15 條 legacy 在四種 host／scheme 下也必須一跳到最終 200。 */
    #[DataProvider('productionHosts')]
    public function test_every_legacy_converges_in_one_hop_on_production(string $host, bool $secure): void
    {
        config(['app.url' => 'https://www.iglikefollow.com']);

        foreach (self::legacyRedirects() as [$from, $to]) {
            $this->assertConvergesInOneHop($from, $to, $host, $secure);
        }
    }

    /** R2：9 條 alias 同樣一跳到商品 canonical。 */
    #[DataProvider('productionHosts')]
    public function test_every_alias_converges_in_one_hop_on_production(string $host, bool $secure): void
    {
        config(['app.url' => 'https://www.iglikefollow.com']);

        foreach (self::productAliases() as [$from, $to]) {
            $this->assertConvergesInOneHop($from, $to, $host, $secure);
        }
    }

    /**
     * 追 Location 直到非 301：最多一跳，且最終必須是 200 canonical。
     */
    private function assertConvergesInOneHop(
        string $from,
        string $finalPath,
        string $host,
        bool $secure,
    ): void {
        $server = ['HTTP_HOST' => $host];

        if ($secure) {
            $server['HTTPS'] = 'on';
        }

        $response = $this->request($from, 'GET', $server);
        $hops = 0;

        while ($response->getStatusCode() === 301) {
            $hops++;

            $this->assertLessThanOrEqual(1, $hops, $from.' 超過一跳（'.$host.'）。');

            $location = (string) $response->headers->get('Location');

            $this->assertStringStartsWith(
                'https://www.iglikefollow.com',
                $location,
                $from.' 必須直達正式 www HTTPS。',
            );

            // 最終目標必須逐字等於預期 canonical。
            $this->assertSame(
                'https://www.iglikefollow.com'.CanonicalUrl::encodePath($finalPath),
                $location,
                $from.' 的最終 Location 不正確（'.$host.'）。',
            );

            $response = $this->request(
                (string) parse_url($location, PHP_URL_PATH),
                'GET',
                ['HTTP_HOST' => 'www.iglikefollow.com', 'HTTPS' => 'on'],
            );
        }

        $this->assertSame(200, $response->getStatusCode(), $from.' 的最終狀態必須是 200。');
    }

    // ==================================================== 16. R2：停用目標 × slash × host

    /**
     * R2：draft 目標與未知 path 在任何 host／slash 組合下都直接 404。
     *
     * 不得先 301 去掉斜線或換 host 再 404——那是一條指向 404 的永久轉址。
     */
    #[DataProvider('productionHosts')]
    public function test_draft_and_unknown_targets_are_direct_404s(string $host, bool $secure): void
    {
        config(['app.url' => 'https://www.iglikefollow.com']);

        Platform::query()->where('slug', 'threads')->update(['status' => 'draft']);

        $server = ['HTTP_HOST' => $host];

        if ($secure) {
            $server['HTTPS'] = 'on';
        }

        $paths = [
            '/services/threads',
            '/services/threads/',
            '/services/threads/followers',
            '/services/threads/followers/',
            '/services/nonexistent',
            '/services/nonexistent/',
            '/unknown-review-only',
            '/unknown-review-only/',
            '/product/threads買粉絲/',
            '/product/threads買粉絲',
        ];

        foreach ($paths as $path) {
            $this->assertSame(
                404,
                $this->request($path, 'GET', $server)->getStatusCode(),
                $path.' 在 '.$host.' 必須直接 404。',
            );
        }
    }

    /** 410 在四種 host／slash 組合下仍是 410。 */
    #[DataProvider('productionHosts')]
    public function test_gone_pages_stay_410_on_every_host(string $host, bool $secure): void
    {
        config(['app.url' => 'https://www.iglikefollow.com']);

        $server = ['HTTP_HOST' => $host];

        if ($secure) {
            $server['HTTPS'] = 'on';
        }

        foreach (['/cart', '/cart/', '/my-account', '/my-account/'] as $path) {
            $this->assertSame(
                410,
                $this->request($path, 'GET', $server)->getStatusCode(),
                $path.' 在 '.$host.' 必須是 410。',
            );
        }
    }

    // ==================================================== 17. R2：正式 fixture 的可索引驗證

    /**
     * R2：在 production ＋ 允許索引 ＋ 正確 indexable host 的 fixture 下，
     * 14 條 canonical 必須逐一是可索引的 200。
     *
     * 只改 config（等同 .env 的值），不修改實際 .env 或本機資料。
     */
    private function withProductionIndexing(callable $callback): void
    {
        config([
            'app.env' => 'production',
            'app.url' => 'https://www.iglikefollow.com',
            'seo.allow_indexing' => true,
            'seo.indexable_host' => 'www.iglikefollow.com',
        ]);

        $callback();
    }

    public function test_every_canonical_is_indexable_under_production_fixture(): void
    {
        $this->withProductionIndexing(function (): void {
            $server = ['HTTP_HOST' => 'www.iglikefollow.com', 'HTTPS' => 'on'];

            foreach (CanonicalUrl::indexablePaths() as $path) {
                $response = $this->request($path, 'GET', $server);

                $this->assertSame(200, $response->getStatusCode(), $path.' 必須 200。');

                // header 不得有 noindex。
                $robots = (string) $response->headers->get('X-Robots-Tag');
                $this->assertStringNotContainsString('noindex', $robots, $path.' header 不得 noindex。');

                $html = (string) $response->getContent();

                // meta 也不得有 noindex。
                $this->assertDoesNotMatchRegularExpression(
                    '#<meta[^>]+name="robots"[^>]*noindex#i',
                    $html,
                    $path.' meta 不得 noindex。',
                );

                // 單一 H1／title／description／self-canonical。
                $this->assertSame(1, substr_count($html, '<h1'), $path.' 必須只有一個 H1。');
                $this->assertSame(1, substr_count($html, '<title>'), $path.' 必須只有一個 title。');

                preg_match_all('#<meta name="description" content="([^"]*)"#', $html, $desc);
                $this->assertCount(1, $desc[1], $path.' 必須只有一個 description。');
                $this->assertNotSame('', trim($desc[1][0]), $path.' description 不得為空。');

                preg_match_all('#<link rel="canonical" href="([^"]+)"#', $html, $canon);
                $this->assertCount(1, $canon[1], $path.' 必須只有一個 canonical。');
                $this->assertSame(
                    CanonicalUrl::to($path),
                    $canon[1][0],
                    $path.' 的 canonical 必須是 absolute self-canonical。',
                );
            }
        });
    }

    /** 相同設定下 staging host 仍必須 noindex。 */
    public function test_staging_host_stays_noindex_under_the_same_production_fixture(): void
    {
        $this->withProductionIndexing(function (): void {
            $response = $this->request('/faq', 'GET', [
                'HTTP_HOST' => 'staging.iglikefollow.com',
                'HTTPS' => 'on',
            ]);

            $this->assertStringContainsString(
                'noindex',
                (string) $response->headers->get('X-Robots-Tag'),
                'staging host 必須維持 noindex。',
            );
        });
    }

    /** 同一 fixture 下 robots.txt 才會 Allow，且 sitemap 用 trusted origin。 */
    public function test_robots_allows_only_under_the_production_fixture(): void
    {
        $this->withProductionIndexing(function (): void {
            $body = (string) $this->request('/robots.txt', 'GET', [
                'HTTP_HOST' => 'www.iglikefollow.com',
                'HTTPS' => 'on',
            ])->getContent();

            $this->assertStringContainsString('Allow: /', $body);
            $this->assertStringContainsString(CanonicalUrl::to('/sitemap.xml'), $body);

            // staging host 在同一設定下仍 Disallow。
            $staging = (string) $this->request('/robots.txt', 'GET', [
                'HTTP_HOST' => 'staging.iglikefollow.com',
                'HTTPS' => 'on',
            ])->getContent();

            $this->assertStringContainsString('Disallow: /', $staging);
        });
    }

    // ==================================================== 18. R3：真實 session 的 preview

    /**
     * 用「已持久化的 session cookie」發一個新 request，模擬真正的下一次瀏覽。
     *
     * R3 的重點：`actingAs()` 會預先把 User 放進 auth guard，因此看不出
     * middleware 是否排在 StartSession 之前。這個 helper 刻意走完整流程：
     * 先登入並寫入 file session，再清掉 guard 與 session store，
     * 然後只帶著 session cookie 發第二個 request。
     *
     * 不停用 EncryptCookies：cookie 由框架自己加密／解密，
     * 才算「經過完整 session 流程」。
     */
    private function requestWithPersistedSession(User $user, string $path): Response
    {
        $directory = sys_get_temp_dir().'/iglf-r3-session-'.bin2hex(random_bytes(8));

        mkdir($directory, 0700);

        config(['session.driver' => 'file', 'session.files' => $directory]);

        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        app('auth')->forgetGuards();

        try {
            $store = app('session')->driver();
            $store->start();
            app('auth')->guard('web')->login($user);
            $store->save();

            $sessionId = $store->getId();

            // 模擬下一個 PHP request：沒有已登入的 guard、也沒有已載入的 store。
            app('auth')->forgetGuards();
            app('session')->forgetDrivers();
            app()->forgetInstance('session.store');

            $cookieName = config('session.cookie');

            // 經過框架的 cookie 加密，與真實瀏覽器一致。
            $encrypted = app(Encrypter::class)->encrypt(
                CookieValuePrefix::create($cookieName, app(Encrypter::class)->getKey()).$sessionId,
                false,
            );

            $request = Request::create(
                'http://localhost:8083'.$path,
                'GET',
                [],
                [$cookieName => $encrypted],
            );

            return app(Kernel::class)->handle($request);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * R3：Owner 以真實 session cookie 開 preview 必須是 200＋noindex＋無 canonical。
     *
     * R2 的缺口：`CanonicalUrlRedirect` 以 `prependToGroup()` 掛在 web group
     * 最前面，早於 StartSession，所以 `$request->user()` 永遠是 null——
     * 真正登入的 Owner 會被 301 走，預覽功能在正式環境完全失效。
     */
    public function test_an_owner_preview_survives_a_real_cookie_backed_session(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $response = $this->requestWithPersistedSession(
            $owner,
            '/services/instagram/followers?preview=1',
        );

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'Owner 的 preview 必須由 session 認出；實際 Location: '
            .($response->headers->get('Location') ?? '-'),
        );

        $this->assertStringContainsString(
            'noindex',
            (string) $response->headers->get('X-Robots-Tag'),
        );

        $this->assertStringNotContainsString(
            'rel="canonical"',
            (string) $response->getContent(),
            'preview 不得輸出可索引 canonical。',
        );
    }

    /** Editor 同樣可以預覽。 */
    public function test_an_editor_preview_survives_a_real_cookie_backed_session(): void
    {
        $editor = User::factory()->create(['role' => 'editor', 'is_active' => true]);

        $response = $this->requestWithPersistedSession(
            $editor,
            '/services/instagram/followers?preview=1',
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(
            'noindex',
            (string) $response->headers->get('X-Robots-Tag'),
        );
    }

    /**
     * guest 自己加 `?preview=1` 仍然被永久收斂，且 query 不得保留。
     *
     * 這條與上面兩條共用同一條路徑，確保 R3 的順序調整沒有把 preview
     * 豁免放寬給未登入者。
     */
    public function test_a_guest_preview_is_still_redirected_after_the_reorder(): void
    {
        $response = $this->request('/services/instagram/followers?preview=1');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(
            CanonicalUrl::to('/product/ig買粉絲/'),
            $response->headers->get('Location'),
            'guest 的 preview 參數不得保留在 Location。',
        );
    }

    /**
     * R3：middleware 必須排在 EncryptCookies／StartSession 之後。
     *
     * 直接釘住順序本身，而不只是行為：若日後有人改回 prepend，
     * 上面的 session 測試會紅，但這一條會更直接指出原因。
     */
    public function test_the_seo_middleware_runs_after_session_is_started(): void
    {
        $group = app('router')->getMiddlewareGroups()['web'];

        $seo = array_search(CanonicalUrlRedirect::class, $group, true);
        $session = array_search(StartSession::class, $group, true);
        $cookies = array_search(EncryptCookies::class, $group, true);

        $this->assertNotFalse($seo, 'CanonicalUrlRedirect 必須在 web group 內。');
        $this->assertNotFalse($session);
        $this->assertNotFalse($cookies);

        $this->assertGreaterThan(
            $session,
            $seo,
            'CanonicalUrlRedirect 必須在 StartSession 之後，否則讀不到登入狀態。',
        );

        $this->assertGreaterThan($cookies, $seo);
    }
}
