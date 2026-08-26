<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ⭐ R2（Owner 指定）：平台 Hub Hero 右側的抽象 SVG 假圖必須完整刪除。
 *
 * ⛔ Owner 的要求是「刪除」，不是「隱藏」——因此驗收條件是那段 markup
 * **不存在於 HTML**，而不是它看不見。CSS 隱藏的假圖仍然會進 HTML、進
 * 檢視原始碼、進爬蟲，而且下一個改版的人看到它還在，就會以為它還該在。
 *
 * ⛔ 同時必須確認刪掉之後版面沒有壞：桌面不留空欄、手機不留空白區。
 * 只刪 SVG 而留下雙欄 grid，會讓文字被擠在左半邊、右邊空一大塊——那是
 * 「假圖沒了但版面壞了」，不是 Owner 要的結果。
 *
 * ⛔ 後台上傳真實圖片的能力必須保留。
 */
class PlatformHeroFallbackRemovalTest extends TestCase
{
    use RefreshDatabase;

    /** 假圖的識別特徵：`viewBox` 與漸層 id 都只出現在那段 markup。 */
    private const FALLBACK_MARKERS = [
        'viewBox="0 0 420 300"',
        'linearGradient',
        'url(#g1)',
    ];

    /** 雙欄 grid 的識別 class。 */
    private const TWO_COLUMN_CLASS = 'lg:grid-cols-[1.15fr_0.85fr]';

    private function platform(string $slug, string $name, array $overrides = []): Platform
    {
        $platform = Platform::factory()->published()->create(array_merge([
            'slug' => $slug,
            'name' => $name,
            'hero_image_path' => null,
        ], $overrides));

        Service::factory()->published()->create([
            'platform_id' => $platform->id,
            'slug' => 'followers',
            'product_slug' => $slug.'-followers',
        ]);

        return $platform->fresh();
    }

    /**
     * ⛔ 三個共用同一支 Blade 的平台都必須生效。
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function platformProvider(): array
    {
        return [
            'instagram' => ['instagram', 'Instagram'],
            'facebook' => ['facebook', 'Facebook'],
            'threads' => ['threads', 'Threads'],
        ];
    }

    #[DataProvider('platformProvider')]
    public function test_the_fallback_svg_is_absent_when_there_is_no_hero_image(string $slug, string $name): void
    {
        $this->platform($slug, $name);

        $html = (string) $this->get('/services/'.$slug)->assertOk()->getContent();

        foreach (self::FALLBACK_MARKERS as $marker) {
            $this->assertStringNotContainsString(
                $marker,
                $html,
                "⛔ 假圖 markup `{$marker}` 必須從 Blade 完整刪除，不是 CSS 隱藏。",
            );
        }
    }

    /**
     * ⛔ 沒有圖時不得留下空的右欄容器。
     *
     * 具體檢查兩件事：Hero section 不帶雙欄 class，且不存在那個
     * `aria-hidden` 的空容器。
     */
    #[DataProvider('platformProvider')]
    public function test_the_hero_is_single_column_when_there_is_no_hero_image(string $slug, string $name): void
    {
        $this->platform($slug, $name);

        $html = (string) $this->get('/services/'.$slug)->assertOk()->getContent();

        $this->assertStringNotContainsString(
            self::TWO_COLUMN_CLASS,
            $html,
            '⛔ 無圖時桌面不得保留右側空欄。',
        );

        $this->assertStringNotContainsString(
            '<div class="mt-10 lg:mt-0" aria-hidden="true">',
            $html,
            '⛔ 不得留下空的假圖容器。',
        );
    }

    /**
     * ⭐ 真實圖片能力保留：上傳後仍顯示 `<img>`、alt，並回到雙欄。
     *
     * ⛔ 這一條是刪除工作的另一半。只驗證「假圖不見了」而不驗證「真圖還在」，
     * 就無法分辨「正確刪掉 fallback」與「把整個 Hero 圖片功能砍掉」。
     */
    public function test_a_real_hero_image_is_still_rendered_in_two_columns(): void
    {
        Storage::fake('public');

        $path = UploadedFile::fake()->image('hero.jpg', 1200, 675)->store('uploads', 'public');

        $this->platform('instagram', 'Instagram', [
            'hero_image_path' => $path,
            'hero_image_alt' => '平台主視覺',
        ]);

        $html = (string) $this->get('/services/instagram')->assertOk()->getContent();

        $this->assertStringContainsString($path, $html);
        $this->assertStringContainsString('平台主視覺', $html);
        $this->assertStringContainsString('<img', $html);

        // 有真實圖片時才回到雙欄。
        $this->assertStringContainsString(self::TWO_COLUMN_CLASS, $html);

        // ⛔ 即使有真圖，假圖 markup 仍然不該存在。
        $this->assertStringNotContainsString('viewBox="0 0 420 300"', $html);
    }

    /**
     * ⛔ SEO 與內容不得因為刪圖而回歸。
     *
     * 單一 H1、平台 logo、tagline、intro 與服務連結都必須留在初始 HTML。
     */
    public function test_the_hero_content_and_seo_survive_the_deletion(): void
    {
        $platform = $this->platform('instagram', 'Instagram', [
            'h1' => 'Instagram 社群成長服務',
            'tagline' => '這是一句話介紹。',
            'intro' => '這是詳細介紹。',
        ]);

        $html = (string) $this->get('/services/instagram')->assertOk()->getContent();

        // ⛔ 單一 H1。
        $this->assertSame(1, substr_count($html, '<h1'), '⛔ 必須恰好一個 H1。');
        $this->assertStringContainsString($platform->h1, $html);

        $this->assertStringContainsString('這是一句話介紹。', $html);
        $this->assertStringContainsString('這是詳細介紹。', $html);

        // 平台 logo（裝飾性 brand icon）仍在。
        $this->assertStringContainsString('Instagram', $html);

        // 服務連結仍在初始 HTML。
        $this->assertStringContainsString('instagram-followers', $html);
    }

    /**
     * ⛔ 無圖時文字不得縮在整列的左三分之一。
     *
     * 單欄之後若沿用 `max-w-xl`，視覺上會像右邊還有東西沒載入；
     * 但也不得放到滿版——行寬超過可讀範圍同樣不行。
     */
    public function test_the_text_column_widens_when_there_is_no_hero_image(): void
    {
        $this->platform('instagram', 'Instagram', ['tagline' => '這是一句話介紹。']);

        $html = (string) $this->get('/services/instagram')->assertOk()->getContent();

        $this->assertStringContainsString('max-w-3xl', $html, '⛔ 無圖時文字區應合理放寬。');
    }
}
