<?php

namespace Tests\Feature;

use App\Models\Platform;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The home platform cards carry their brand marks as local, fixed-version,
 * decorative inline SVG — nothing external, nothing scripted, nothing from
 * the database.
 *
 * ⛔ The component is an allowlist: instagram / facebook / threads. An
 * unknown slug renders a neutral fallback and the slug itself never reaches
 * the HTML, so there is no injection surface to escape in the first place.
 */
class HomePlatformBrandIconsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->seed(CatalogSeeder::class);
    }

    /**
     * 讓 Threads 卡出現:seeder 裡 Threads 是 draft(首頁不列),線上
     * 實況是 published 但無服務(顯示「準備中」)。⛔ 只調整測試 DB 的
     * 狀態欄位重現線上版面,不改任何 availability 邏輯。
     */
    private function publishThreads(): void
    {
        Platform::query()->where('slug', 'threads')->update(['status' => 'published']);
    }

    /** 平台卡區塊的 HTML(#platforms section)。 */
    private function platformsSection(): string
    {
        $html = $this->get('/')->assertOk()->getContent();

        $start = strpos($html, 'id="platforms"');
        $end = strpos($html, 'id="process"');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    public function test_the_initial_html_carries_all_three_brand_svgs_beside_their_names(): void
    {
        $this->publishThreads();
        $section = $this->platformsSection();

        // 三張卡各一個品牌 SVG,不依賴任何 JS。
        $this->assertSame(3, substr_count($section, '<svg'));
        // 裝飾性 accessibility(卡片清單的圓點 span 另有自己的 aria-hidden,故以組合屬性計數)。
        $this->assertSame(3, substr_count($section, 'aria-hidden="true" focusable="false"'));
        $this->assertSame(3, substr_count($section, 'focusable="false"'));

        // 克制品牌色(單色 fill)。
        $this->assertStringContainsString('fill="#E4405F"', $section);
        $this->assertStringContainsString('fill="#0866FF"', $section);
        $this->assertStringContainsString('fill="#10110F"', $section);

        // 名稱 H3 保留,標誌只是裝飾。
        foreach (['Instagram', 'Facebook', 'Threads'] as $name) {
            $this->assertStringContainsString($name, $section);
        }
    }

    public function test_threads_still_shows_its_mark_while_remaining_unavailable(): void
    {
        $this->publishThreads();
        $section = $this->platformsSection();

        // Threads 準備中:標誌照顯示,availability 文案不變。
        $this->assertStringContainsString('fill="#10110F"', $section);
        $this->assertStringContainsString('準備中', $section);
        $this->assertStringNotContainsString('/services/threads/', $section);
    }

    public function test_the_icons_are_fixed_size_local_and_script_free(): void
    {
        $this->publishThreads();
        $section = $this->platformsSection();

        // 固定寬高。
        $this->assertSame(3, substr_count($section, 'width="28" height="28"'));
        $this->assertSame(3, substr_count($section, 'viewBox="0 0 24 24"'));

        // ⛔ 無外部 icon URL、script、event handler 或 DB SVG。
        $this->assertStringNotContainsString('<script', $section);
        $this->assertStringNotContainsString('onerror', $section);
        $this->assertStringNotContainsString('onclick', $section);
        $this->assertStringNotContainsString('onload', $section);
        $this->assertStringNotContainsString('cdn.', $section);
        $this->assertStringNotContainsString('.svg', $section);

        foreach (explode('<svg', $section) as $i => $chunk) {
            if ($i === 0) {
                continue;
            }
            $svg = substr($chunk, 0, strpos($chunk, '</svg>'));
            // SVG 內不得引用任何外部資源(只有本機 path 資料;xmlns 是 namespace 不是連結)。
            $this->assertStringNotContainsString('href', $svg);
            $this->assertStringNotContainsString('src=', $svg);
            $this->assertStringNotContainsString('url(', $svg);
            $this->assertStringNotContainsString('<image', $svg);
            $this->assertStringNotContainsString('<use', $svg);
        }
    }

    public function test_an_unknown_slug_renders_the_neutral_fallback_without_echoing_the_slug(): void
    {
        $malicious = '<script>alert(1)</script>';

        $html = Blade::render('<x-platform-brand-icon :slug="$slug" />', ['slug' => $malicious]);

        // 安全中性 fallback:固定尺寸、裝飾性;⛔ slug 完全不出現在輸出。
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('width="28" height="28"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('<circle', $html);
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('alert', $html);
    }

    public function test_non_string_and_unlisted_slugs_fall_back_neutrally(): void
    {
        foreach ([null, 'tiktok', 'INSTAGRAM', ''] as $slug) {
            $html = Blade::render('<x-platform-brand-icon :slug="$slug" />', ['slug' => $slug]);

            $this->assertStringContainsString('<circle', $html, var_export($slug, true));
            $this->assertStringNotContainsString('#E4405F', $html, var_export($slug, true));
        }
    }

    public function test_home_seo_surface_is_unchanged_by_the_icons(): void
    {
        $response = $this->get('/')->assertOk();
        $html = $response->getContent();

        // 單一 H1;title/description 與施工前一致;noindex 不變;無 canonical(與施工前一致)。
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('<title>社群成長服務｜Instagram、Facebook｜IGLIKEFOLLOW</title>', $html);
        $this->assertStringContainsString('IGLIKEFOLLOW 提供 Instagram 與 Facebook 的粉絲、讚、留言與影片觀看服務。先選擇平台，再選擇需要的服務類型，最後挑選數量方案並免會員結帳。', $html);
        // M2-C:首頁輸出自我 canonical(尾斜線根形式);noindex 不變。
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/').'/">', $html);
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }
}
