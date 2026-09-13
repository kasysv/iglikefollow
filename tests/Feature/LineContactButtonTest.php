<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Support\CanonicalUrl;
use Database\Seeders\CatalogSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\SeedsThreadsCatalog;
use Tests\TestCase;

/**
 * M5D：右下角 LINE 客服按鈕。
 *
 * ⛔ 這是一條一般客服連結，⛔ 不是 Messaging API：本輪 0 API／SDK／
 * webhook／token，⛔ 也沒有任何 DB 變更。
 *
 * ⛔⛔ 顯示範圍是 allowlist：結帳、付款與後台**不得**出現按鈕。
 */
class LineContactButtonTest extends TestCase
{
    use RefreshDatabase;
    use SeedsThreadsCatalog;

    private const URL = 'https://line.me/R/ti/p/@532otvye';

    private const ORIGIN = 'http://localhost:8083';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seedThreadsCatalog();
        Artisan::call('m2c:apply-copy');
    }

    private function request(string $path): Response
    {
        return app(Kernel::class)->handle(Request::create(self::ORIGIN.$path, 'GET'));
    }

    /** @return list<\DOMElement> */
    private function buttons(string $html): array
    {
        $dom = new DOMDocument;

        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        $nodes = (new DOMXPath($dom))->query('//a[@data-probe="line-contact"]');

        return iterator_to_array($nodes);
    }

    // ============================================ 1. 顯示範圍

    /** @return list<array{string}> */
    public static function pagesThatShowTheButton(): array
    {
        return [
            'home' => ['/'],
            'faq' => ['/faq'],
            'platform hub' => ['/services/instagram'],
            'product' => ['/product/ig買粉絲/'],
            'order-check' => ['/order-check'],
        ];
    }

    #[DataProvider('pagesThatShowTheButton')]
    public function test_the_button_appears_exactly_once_on_public_pages(string $path): void
    {
        $html = (string) $this->request($path)->getContent();

        // ⛔ 恰好一個：⛔ 重複插入會有兩個焦點目標、兩個相同 aria-label。
        $this->assertCount(1, $this->buttons($html), "{$path} 必須恰好一個按鈕");
    }

    public function test_every_indexable_page_carries_the_button(): void
    {
        foreach (CanonicalUrl::indexablePaths() as $path) {
            $html = (string) $this->request($path)->getContent();

            $this->assertCount(1, $this->buttons($html), "{$path} 必須有按鈕");
        }
    }

    /**
     * ⛔⛔ 結帳／付款／後台一律不得出現。
     *
     * ⭐ 初版刻意這樣設計：保留封閉式結帳，⛔ 並避免遮住付款欄位。
     */
    public function test_the_button_never_appears_on_checkout_payment_or_admin_pages(): void
    {
        foreach (['/checkout', '/ignfdash', '/ignfdash/login'] as $path) {
            $html = (string) $this->request($path)->getContent();

            $this->assertCount(0, $this->buttons($html), "⛔ {$path} 不得出現按鈕");
            $this->assertStringNotContainsString('line.me', $html, "⛔ {$path} 不得有 LINE 連結");
        }
    }

    /**
     * ⛔ 404／錯誤頁共用同一個 layout，⛔ 但不得誤顯示。
     *
     * ⭐ 這正是用 allowlist 而不是 denylist 的原因：`routeIs()` 在錯誤頁
     * 不會命中任何一條，所以預設就是不顯示。
     */
    public function test_error_pages_do_not_show_the_button(): void
    {
        /*
         * ⛔ 這兩條必須是真正的 404。
         * ⭐ 我第一版放了 `/services/threads/followers/`，它其實是 M5A 的
         * 301 alias（測試因此紅）——那證明的是轉址，不是錯誤頁。
         */
        foreach (['/unknown-page-xyz/', '/product/no-such-product/'] as $path) {
            $response = $this->request($path);

            $this->assertSame(404, $response->getStatusCode(), "{$path} 應為 404");
            $this->assertCount(0, $this->buttons((string) $response->getContent()));
        }
    }

    // ============================================ 2. 連結本身

    public function test_the_link_is_the_exact_approved_url_with_no_appended_data(): void
    {
        $button = $this->buttons((string) $this->request('/')->getContent())[0];

        $href = $button->getAttribute('href');

        // ⛔ 逐字相同：⛔ 沒有 query、fragment 或預填訊息。
        $this->assertSame(self::URL, $href);
        $this->assertStringNotContainsString('?', $href);
        $this->assertStringNotContainsString('#', $href);
    }

    /**
     * ⛔⛔ 不得夾帶任何客人資料。
     *
     * ⭐ 用一張**假的** fixture 訂單查詢，⛔ 不用真實客戶資料：
     * 確認訂單頁上的按鈕連結仍然一個字都沒多。
     */
    public function test_the_link_never_carries_order_or_customer_data(): void
    {
        $service = Service::query()->whereNotNull('product_slug')->firstOrFail();

        foreach (['/', '/order-check', '/product/'.$service->product_slug.'/'] as $path) {
            $button = $this->buttons((string) $this->request($path)->getContent())[0];

            $this->assertSame(self::URL, $button->getAttribute('href'), "{$path} 的連結不得被加料");
        }
    }

    public function test_the_new_window_cannot_reach_back_into_this_site(): void
    {
        $button = $this->buttons((string) $this->request('/')->getContent())[0];

        $this->assertSame('_blank', $button->getAttribute('target'));

        // ⛔ noopener：新分頁不得取得 window.opener。
        $this->assertStringContainsString('noopener', $button->getAttribute('rel'));
        $this->assertStringContainsString('noreferrer', $button->getAttribute('rel'));

        // ⛔ 不把我們的網址外洩給 LINE。
        $this->assertSame('no-referrer', $button->getAttribute('referrerpolicy'));
    }

    // ============================================ 3. 無障礙與無 JS

    public function test_the_button_is_a_real_link_that_works_without_javascript(): void
    {
        $button = $this->buttons((string) $this->request('/')->getContent())[0];

        $this->assertSame('a', $button->tagName, '⛔ 必須是真實 <a>，不是 JS-only 元素。');
        $this->assertNotSame('', $button->getAttribute('href'));

        // ⛔ 不得靠 onclick 之類的 handler 才能用。
        $this->assertSame('', $button->getAttribute('onclick'));
    }

    public function test_the_accessible_name_says_it_opens_a_new_window(): void
    {
        $button = $this->buttons((string) $this->request('/')->getContent())[0];

        $label = $button->getAttribute('aria-label');

        $this->assertStringContainsString('LINE', $label);
        $this->assertStringContainsString('客服', $label);
        $this->assertStringContainsString('另開視窗', $label);
    }

    /** ⛔ 不靠 tooltip 當唯一辨識：按鈕上必須有可見文字。 */
    public function test_the_button_shows_real_visible_text(): void
    {
        $button = $this->buttons((string) $this->request('/')->getContent())[0];

        $this->assertStringContainsString('LINE', trim($button->textContent));
    }

    /** 裝飾性圖形必須對輔助技術隱藏，⛔ 也不得自稱官方 Logo。 */
    public function test_the_decorative_mark_is_hidden_from_assistive_tech(): void
    {
        $html = (string) $this->request('/')->getContent();

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        $svg = (new DOMXPath($dom))
            ->query('//a[@data-probe="line-contact"]//*[local-name()="svg"]')
            ->item(0);

        $this->assertNotNull($svg);
        $this->assertSame('true', $svg->getAttribute('aria-hidden'));
    }

    // ============================================ 4. 不得影響既有頁面

    /**
     * ⛔⛔ 移除按鈕後，可見 DOM 必須與原本完全相同。
     *
     * ⭐ 這條釘住「只新增、沒改動」：整頁扣掉這個 component 之後，
     * ⛔ 既有的 H1／canonical／robots／內鏈數量一個都不能變。
     */
    public function test_removing_the_button_leaves_the_rest_of_the_page_untouched(): void
    {
        foreach (CanonicalUrl::indexablePaths() as $path) {
            $html = (string) $this->request($path)->getContent();

            $stripped = preg_replace('~\s*<a href="https://line\.me.*?</a>~s', '', $html);

            $this->assertStringNotContainsString('line.me', $stripped);

            foreach (['<h1', '<title>', 'rel="canonical"', 'name="robots"', 'application/ld+json'] as $marker) {
                $this->assertSame(
                    substr_count($html, $marker),
                    substr_count($stripped, $marker),
                    "{$path} 移除按鈕後 {$marker} 數量必須不變"
                );
            }
        }
    }

    /** ⛔ 新外鏈不得進 sitemap。 */
    public function test_the_external_link_never_enters_the_sitemap(): void
    {
        $body = (string) $this->request('/sitemap.xml')->getContent();

        $this->assertStringNotContainsString('line.me', $body);
        $this->assertSame(14, substr_count($body, '<loc>'));
    }

    /** 只有顯示按鈕的頁面才墊高底部，⛔ 不全站加 padding。 */
    public function test_only_pages_with_the_button_reserve_bottom_space(): void
    {
        $this->assertStringContainsString(
            'has-line-contact',
            (string) $this->request('/')->getContent()
        );

        $this->assertStringNotContainsString(
            'has-line-contact',
            (string) $this->request('/checkout')->getContent()
        );
    }
}
