<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\Service;
use App\Models\ServiceVariant;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsThreadsCatalog;
use Tests\TestCase;

/**
 * M2-D-A:可讀性與成交視覺層級。
 *
 * 核心不變式:
 * - accent(#C93636)只給三種角色:主打標籤、已選款式、真正的購買 CTA;
 *   ⛔ 一般導覽／查看服務 CTA 維持黑色,正文與標題不染色。
 * - 平台 Logo 只在首頁平台卡、Hub 切換 tab 與 Hub hero 出現,
 *   ⛔ 不在同一平台頁的每張服務卡重複。
 * - 交易區不再使用 12px＋50% 灰的組合。
 * - Title／Description／H1／canonical／robots 與可見文案 0 改動。
 */
class M2dStorefrontVisualHierarchyTest extends TestCase
{
    use RefreshDatabase;
    use SeedsThreadsCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->seed(CatalogSeeder::class);
        $this->seedThreadsCatalog();
        Artisan::call('m2c:apply-copy');
    }

    private function instagramHub(): string
    {
        return $this->get('/services/instagram')->assertOk()->getContent();
    }

    private function productPage(string $slug = 'ig買粉絲'): string
    {
        $url = Service::query()->where('product_slug', $slug)->firstOrFail()->primaryUrl();

        return $this->get($url)->assertOk()->getContent();
    }

    private function checkoutPage(): string
    {
        $variant = ServiceVariant::query()->published()
            ->whereHas('service', fn ($q) => $q->where('product_slug', 'ig買粉絲'))
            ->firstOrFail();

        $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => $variant->default_quantity])
            ->assertRedirect(route('checkout'));

        return $this->get('/checkout')->assertOk()->getContent();
    }

    // ------------------------------------------------------------------
    // 1. Logo:allowlist、尺寸、不重複
    // ------------------------------------------------------------------

    public function test_the_brand_icon_component_stays_an_allowlist_for_slug_and_size(): void
    {
        // 三個已知平台輸出本機 SVG。
        foreach (['instagram' => '#E4405F', 'facebook' => '#0866FF', 'threads' => '#10110F'] as $slug => $color) {
            $html = Blade::render('<x-platform-brand-icon :slug="$slug" />', ['slug' => $slug]);

            $this->assertStringContainsString('<svg', $html);
            $this->assertStringContainsString($color, $html);
            $this->assertStringContainsString('aria-hidden="true"', $html);
            $this->assertStringContainsString('focusable="false"', $html);

            /*
             * ⛔ 無外部資源:唯一允許的 http 字串是 SVG 規範的 xmlns
             * namespace(它不是網路請求)。剝掉之後不得再有任何 URL。
             */
            $withoutNamespace = str_replace('xmlns="http://www.w3.org/2000/svg"', '', $html);
            $this->assertStringNotContainsString('http', $withoutNamespace);
            $this->assertStringNotContainsString('<script', $html);
        }

        // ⛔ 惡意／未知 slug 不得輸出原值,只給中性 fallback。
        $hostile = '"><script>alert(1)</script>';
        $html = Blade::render('<x-platform-brand-icon :slug="$slug" />', ['slug' => $hostile]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringContainsString('<circle', $html);

        // ⛔ 尺寸也是 allowlist:未知值退回 md(28px),不得注入任意值。
        $unknownSize = Blade::render('<x-platform-brand-icon slug="instagram" :size="$size" />', ['size' => '999px" onload="x']);

        $this->assertStringContainsString('width="28"', $unknownSize);
        $this->assertStringNotContainsString('onload', $unknownSize);

        $small = Blade::render('<x-platform-brand-icon slug="instagram" size="sm" />');
        $this->assertStringContainsString('width="20"', $small);
        $this->assertStringContainsString('h-5 w-5', $small);
    }

    public function test_the_home_page_keeps_its_three_platform_logos(): void
    {
        Platform::query()->where('slug', 'threads')->update(['status' => 'published']);

        $html = $this->get('/')->assertOk()->getContent();

        foreach (['#E4405F', '#0866FF', '#10110F'] as $color) {
            $this->assertStringContainsString($color, $html);
        }
    }

    public function test_the_platform_hub_shows_logos_in_tabs_and_hero_but_not_on_every_service_card(): void
    {
        $html = $this->instagramHub();

        preg_match_all('/<svg[^>]*viewBox="0 0 24 24"[^>]*>/u', $html, $matches);
        $icons = $matches[0];

        /*
         * 3 個平台切換 tab(20px)+ 1 個 hero(28px)= 4 個。
         * ⛔ 若每張服務卡都插 Logo,這個數字會隨服務數量成長。
         */
        $this->assertCount(4, $icons);

        $small = array_filter($icons, fn (string $svg) => str_contains($svg, 'width="20"'));
        $medium = array_filter($icons, fn (string $svg) => str_contains($svg, 'width="28"'));

        $this->assertCount(3, $small, '平台切換 tab 應各有一個 20px Logo');
        $this->assertCount(1, $medium, 'Hero 應只有一個 28px Logo');

        // 平台名稱仍以文字提供(Logo 純裝飾)。
        foreach (['Instagram', 'Facebook', 'Threads'] as $name) {
            $this->assertStringContainsString($name, $html);
        }

        // 服務卡數量 > 0,但沒有因此增加 Logo。
        $this->assertGreaterThan(0, substr_count($html, 'class="service-card'));
    }

    // ------------------------------------------------------------------
    // 2. accent 的三種角色與黑色 CTA
    // ------------------------------------------------------------------

    public function test_only_real_purchase_ctas_carry_the_purchase_modifier(): void
    {
        // 商品頁「繼續結帳」。
        $product = $this->productPage();
        $this->assertMatchesRegularExpression(
            '/<button[^>]*class="[^"]*primary-button--purchase[^"]*"[^>]*>\s*繼續結帳/u',
            $product,
        );

        // 結帳頁「前往付款」。
        $checkout = $this->checkoutPage();
        $this->assertMatchesRegularExpression(
            '/<button[^>]*class="[^"]*primary-button--purchase[^"]*"[^>]*>\s*前往付款/u',
            $checkout,
        );
    }

    public function test_navigation_and_browse_ctas_stay_black(): void
    {
        // 首頁:CTA 與 header「選擇服務」都不得是 purchase。
        $home = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('primary-button--purchase', $home);
        $this->assertStringContainsString('選擇服務', $home);

        // FAQ:「查看全部服務」維持黑色。
        Artisan::call('m2c:apply-r5-faq');
        $faq = $this->get('/faq')->assertOk()->getContent();
        $this->assertStringNotContainsString('primary-button--purchase', $faq);
        $this->assertStringContainsString('查看全部服務', $faq);

        // Hub 也沒有購買按鈕(要先進商品頁)。
        $this->assertStringNotContainsString('primary-button--purchase', $this->instagramHub());
    }

    public function test_the_featured_badge_uses_the_accent_class_not_the_faint_green(): void
    {
        $html = $this->instagramHub();

        $this->assertStringContainsString('class="featured-badge"', $html);
        $this->assertStringContainsString('主打服務', $html);

        // ⛔ 不得再用淡綠底＋深綠小字。
        $this->assertStringNotContainsString('bg-trust/12 px-3 py-1 text-xs font-bold text-trust', $html);
    }

    public function test_the_accent_is_defined_once_and_is_not_bright_red(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('--color-accent: #c93636', $css);
        $this->assertStringContainsString('--color-accent-dark: #ad2c2c', $css);

        /*
         * ⛔ 不得使用未經對比檢查的亮紅。註解裡的警告字樣不算宣告,
         * 因此先剝掉 CSS 註解再檢查真正的宣告。
         */
        $declarations = strtolower((string) preg_replace('#/\*.*?\*/#su', '', $css));

        $this->assertStringNotContainsString('#ff0000', $declarations);
        $this->assertStringNotContainsString('#f00;', $declarations);
        $this->assertStringNotContainsString('red;', $declarations);

        /*
         * 已選款式改用 accent(不再是 trust 綠)。用 substr 取出該 block
         * 再檢查,⛔ 不用會被註解干擾的單行 regex。
         */
        $selectedStart = strpos($css, '.variant-card:has(input:checked)');
        $this->assertNotFalse($selectedStart);
        $selectedBlock = substr($css, $selectedStart, strpos($css, '}', $selectedStart) - $selectedStart);

        $this->assertStringContainsString('var(--color-accent)', $selectedBlock);
        $this->assertStringNotContainsString('border-trust', $selectedBlock);

        // 一般 primary-button 仍是黑底 ink。
        $buttonStart = strpos($css, '.primary-button {');
        $this->assertNotFalse($buttonStart);
        $buttonBlock = substr($css, $buttonStart, strpos($css, '}', $buttonStart) - $buttonStart);

        $this->assertStringContainsString('bg-ink', $buttonBlock);
        $this->assertStringNotContainsString('accent', $buttonBlock);
    }

    // ------------------------------------------------------------------
    // 3. 可讀性:交易區不再有 12px + 50% 灰
    // ------------------------------------------------------------------

    public function test_transactional_helpers_no_longer_use_tiny_faint_grey(): void
    {
        $blades = [
            'service' => resource_path('views/storefront/service.blade.php'),
            'checkout' => resource_path('views/storefront/checkout.blade.php'),
        ];

        foreach ($blades as $name => $path) {
            $source = (string) file_get_contents($path);

            // ⛔ 交易頁不得再有 text-xs 搭 50%／55% 灰的組合。
            $this->assertStringNotContainsString('text-xs leading-5 text-black/50', $source, $name);
            $this->assertStringNotContainsString('text-xs leading-5 text-black/55', $source, $name);
            $this->assertStringNotContainsString('text-xs font-bold text-black/50', $source, $name);
            $this->assertStringNotContainsString('text-xs tabular-nums text-black/50', $source, $name);
        }

        // 實際輸出的 helper 也已升級。
        $checkout = $this->checkoutPage();
        $this->assertStringContainsString('用來接收訂單與電子發票通知。', $checkout);
        $this->assertMatchesRegularExpression(
            '/id="email-hint" class="[^"]*text-sm[^"]*text-black\/65[^"]*"/u',
            $checkout,
        );
    }

    // ------------------------------------------------------------------
    // R1:三個手機可見缺陷的結構反證
    // ------------------------------------------------------------------

    public function test_the_platform_tab_provides_its_own_gap_not_a_dropped_component_class(): void
    {
        /*
         * ⛔ platform-brand-icon 不輸出 $attributes,呼叫端傳 class 會被丟掉。
         * 因此間距必須由 .platform-tab 自己的 gap 提供,不能假設 component
         * 會轉發 class。
         */
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $start = strpos($css, '.platform-tab {');
        $this->assertNotFalse($start);
        $block = substr($css, $start, strpos($css, '}', $start) - $start);

        $this->assertStringContainsString('gap-2', $block);

        // 呼叫端不得再傳無效的 class(避免誤導後續維護者)。
        $blade = (string) file_get_contents(resource_path('views/storefront/platform.blade.php'));
        $this->assertStringNotContainsString('size="sm" class="mr-2"', $blade);

        // 元件本身仍不轉發任意屬性(維持無注入面)。
        $component = (string) file_get_contents(resource_path('views/components/platform-brand-icon.blade.php'));
        $this->assertStringNotContainsString('{{ $attributes', $component);

        // tab 仍是真實連結,active 樣式與 Logo 尺寸不變。
        $html = $this->instagramHub();
        $this->assertStringContainsString('href="'.route('platform', 'facebook').'"', $html);
        $this->assertStringContainsString('platform-tab--active', $html);
        $this->assertStringContainsString('width="20"', $html);
    }

    public function test_the_mobile_header_keeps_brand_and_both_links_without_an_oversized_wordmark(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // 四個必要元素都有 probe hook,且都是真實連結。
        // ⭐ `nav-order-check` 是 Owner 本輪新增的獨立訂單查詢入口。
        $this->assertStringContainsString('data-probe="brand"', $html);
        $this->assertStringContainsString('data-probe="nav-faq"', $html);
        $this->assertStringContainsString('data-probe="nav-order-check"', $html);
        $this->assertStringContainsString('data-probe="nav-cta"', $html);
        $this->assertStringContainsString('href="'.route('faq').'"', $html);
        $this->assertStringContainsString('href="'.route('order-check').'"', $html);
        $this->assertStringContainsString('#platforms', $html);

        // ⛔ 品牌名稱仍以 alt 提供,不得只剩沒有名字的圖。
        $this->assertMatchesRegularExpression('/<img[^>]*iglikefollow-logo\.png[^>]*alt="IGLIKEFOLLOW"/u', $html);

        /*
         * 手機 wordmark 縮到 w-32,桌機仍是 w-52
         * (⛔ 原本 w-40 會溢出並壓到「常見問題」)。
         *
         * ⭐ 本輪新增「查訂單」後,<400px 再收一階到 w-24——⛔ 縮 logo,
         * 不刪連結;品牌 alt 仍完整保留(見上面的 regex)。
         */
        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString('w-24 max-w-full min-[400px]:w-32 sm:w-52', $layout);

        // 方形 mark 在 <640px 收起,sm 以上才出現。
        $this->assertStringContainsString('hidden h-11 w-11 shrink-0 rounded-xl sm:block', $layout);

        /*
         * ⭐ 三個 mobile 連結都必須維持 44px 觸控高度且不換行。
         *
         * ⛔ 這個數字從 2 改成 3，是因為 Owner 本輪新增了「查訂單」入口——
         * ⛔ 不是把原本的保證放寬。每一個連結仍然逐一受同一條規則約束。
         *
         * ⛔ 手機用較短的「查訂單」而非「訂單查詢」：這一列在 390px 已經很緊，
         * 既有註解記錄過 wordmark 溢出壓到「常見問題」的教訓。
         */
        $this->assertSame(3, substr_count($layout, 'min-h-11 items-center whitespace-nowrap'));

        // ⛔ 手機用短文字,桌面才用完整「訂單查詢」。
        $this->assertStringContainsString('>查訂單</a>', $layout);

        // ⛔ 不得改成 JS-only。
        $this->assertStringNotContainsString('onclick', $layout);
    }

    public function test_variant_cards_stack_the_price_on_mobile_and_restore_the_row_from_sm(): void
    {
        $html = $this->productPage();

        // 手機堆疊、sm 以上恢復左右排列。
        $this->assertStringContainsString('flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between', $html);

        // ⛔ 單價不得再用無條件 shrink-0 被擠出畫面。
        $this->assertStringNotContainsString('<span class="shrink-0 text-sm tabular-nums text-black/60">', $html);
        $this->assertStringContainsString('sm:shrink-0', $html);

        // 名稱、單價、上下限三者都在同一張卡內,且有 probe hook。
        $this->assertStringContainsString('data-probe="variant-price"', $html);
        $this->assertStringContainsString('data-probe="variant-bounds"', $html);

        // 價格值、單位與提交行為不變。
        $variant = ServiceVariant::query()->published()
            ->whereHas('service', fn ($q) => $q->where('product_slug', 'ig買粉絲'))
            ->orderBy('sort_order')->firstOrFail();

        $this->assertStringContainsString('NT$'.number_format((float) $variant->unit_price, 2), $html);
        $this->assertStringContainsString('form="checkout-form"', $html);
        $this->assertStringContainsString('type="radio"', $html);
    }

    public function test_footer_small_text_keeps_enough_contrast(): void
    {
        foreach ([resource_path('views/layouts/app.blade.php'), resource_path('views/layouts/checkout.blade.php')] as $path) {
            $source = (string) file_get_contents($path);

            // 12px 可保留,但顏色不得低於 /60。
            $this->assertStringNotContainsString('text-xs leading-6 text-black/50', $source);
            $this->assertStringNotContainsString('text-sm text-black/50', $source);
        }
    }

    // ------------------------------------------------------------------
    // 4. SEO 表面 0 改動
    // ------------------------------------------------------------------

    public function test_seo_surface_and_visible_copy_are_unchanged(): void
    {
        Artisan::call('m2c:apply-r3');
        Artisan::call('m2c:apply-r5-faq');

        $pages = ['/', '/faq', '/services/instagram', '/services/facebook', '/services/threads'];

        foreach (Service::query()->whereNotNull('product_slug')->get() as $service) {
            $pages[] = $service->primaryUrl();
        }

        $titles = [];
        $descriptions = [];
        $canonicals = [];

        foreach ($pages as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            preg_match('/<title>(.*?)<\/title>/su', $html, $t);
            preg_match('/name="description" content="([^"]*)"/', $html, $d);
            preg_match('/<link rel="canonical" href="([^"]+)"/', $html, $c);
            preg_match_all('/<h1[^>]*>(.*?)<\/h1>/su', $html, $h);

            // 每頁單一 H1、可索引宣告與 canonical 都在。
            $this->assertCount(1, $h[1], $path);
            $this->assertNotSame('', $t[1] ?? '', $path);
            $this->assertNotSame('', $c[1] ?? '', $path);
            $this->assertStringContainsString('name="robots"', $html, $path);

            $titles[] = $t[1];
            $descriptions[] = $d[1] ?? '';
            $canonicals[] = $c[1];
        }

        // 14 頁 Title／Description／canonical 仍各自唯一。
        $this->assertCount(14, $titles);
        $this->assertCount(14, array_unique($titles));
        $this->assertCount(14, array_unique($descriptions));
        $this->assertCount(14, array_unique($canonicals));

        // 關鍵可見文案仍在(⛔ 本輪只改 class,不動文字)。
        $home = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('選購 IG、Facebook、Threads 買讚、粉絲與觀看方案', $home);
        $this->assertStringContainsString('查看全部常見問題', $home);

        $product = $this->productPage();
        $this->assertStringContainsString('繼續結帳', $product);
        $this->assertStringContainsString('適合目標', $product);
        $this->assertStringContainsString('需要填寫', $product);
        $this->assertStringContainsString('必要條件', $product);
    }
}
