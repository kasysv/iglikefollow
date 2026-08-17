<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three selling points under the homepage headline.
 *
 * They used to be hardcoded in the Blade template while everything around them
 * was editable, so changing a word needed a developer. These tests cover the
 * two things that matter once editors can touch them: the homepage never
 * renders a blank or half-finished strip, and whatever is typed is escaped.
 */
class HomeHighlightsTest extends TestCase
{
    use RefreshDatabase;

    private function settings(array $attributes = []): SiteSetting
    {
        return SiteSetting::create(array_merge([
            'company_name' => 'IGLIKEFOLLOW',
            'home_h1' => '多平台社群服務，一次選好。',
            // ⛔ 固定一段不含特色關鍵字的介紹：預設介紹文裡就有「免會員結帳」，
            // 會讓「預設是否被取代」的斷言誤判成通過。
            'home_intro' => '本頁介紹文字，與下方特色列無關。',
        ], $attributes));
    }

    /**
     * 只取出特色橫列那一段 HTML。
     *
     * ⛔ 整頁比對會誤判：`免會員結帳` 也出現在預設介紹文，`sm:grid-cols-3`
     * 也出現在頁面其他區塊。斷言必須限定在這條列之內，否則測的是別的東西。
     */
    private function highlightStrip(string $html): string
    {
        preg_match('/<div data-home-highlights.*?<\/div>\s*<\/div>/s', $html, $matches);

        return $matches[0] ?? '';
    }

    public function test_the_defaults_render_when_nothing_is_configured(): void
    {
        $this->settings();

        $response = $this->get('/');

        $response->assertOk();
        // ⛔ 沒設定時不得整條空白：預設文字必須出現。
        $response->assertSee('免會員結帳');
        $response->assertSee('不需註冊即可下單');
        $response->assertSee('服務分類清楚');
    }

    public function test_the_defaults_render_when_no_settings_row_exists(): void
    {
        // ⛔ 全新站台連 settings 都還沒建立時，首頁仍要正常。
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('免會員結帳');
    }

    public function test_configured_highlights_replace_the_defaults(): void
    {
        $this->settings([
            'home_highlight_1_title' => '價格透明',
            'home_highlight_1_body' => '結帳金額與頁面顯示一致',
            'home_highlight_2_title' => '快速交付',
            'home_highlight_2_body' => '下單後即刻開始',
            'home_highlight_3_title' => '專人客服',
            'home_highlight_3_body' => '購買前後都能詢問',
        ]);

        $response = $this->get('/');

        $response->assertOk();

        $strip = $this->highlightStrip($response->getContent());

        $this->assertStringContainsString('價格透明', $strip);
        $this->assertStringContainsString('結帳金額與頁面顯示一致', $strip);
        $this->assertStringContainsString('專人客服', $strip);

        // ⛔ 後台填了就完全取代預設，不得兩組並存。
        $this->assertStringNotContainsString('免會員結帳', $strip);
        $this->assertStringNotContainsString('後端重新驗價', $strip);
    }

    public function test_a_half_filled_highlight_is_not_rendered(): void
    {
        $this->settings([
            'home_highlight_1_title' => '價格透明',
            'home_highlight_1_body' => '結帳金額與頁面顯示一致',
            // ⛔ 只有標題沒有說明：不得單獨顯示成一個斷句。
            'home_highlight_2_title' => '只有標題',
            'home_highlight_3_body' => '只有說明',
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('價格透明');
        $response->assertDontSee('只有標題');
        $response->assertDontSee('只有說明');
    }

    public function test_whitespace_only_input_counts_as_empty(): void
    {
        $this->settings([
            'home_highlight_1_title' => '  ',
            'home_highlight_1_body' => '  ',
        ]);

        // 只有空白＝沒填，⛔ 回到預設而不是顯示空欄位。
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('免會員結帳');
    }

    public function test_the_column_count_follows_the_number_of_highlights(): void
    {
        $this->settings([
            'home_highlight_1_title' => '甲標題',
            'home_highlight_1_body' => '甲說明',
            'home_highlight_2_title' => '乙標題',
            'home_highlight_2_body' => '乙說明',
        ]);

        $strip = $this->highlightStrip($this->get('/')->getContent());

        // ⛔ 只有兩項時不可留一個空格：欄數必須跟著筆數。
        $this->assertStringContainsString('sm:grid-cols-2', $strip);
        $this->assertStringNotContainsString('sm:grid-cols-3', $strip);
    }

    public function test_three_highlights_use_three_columns(): void
    {
        $this->settings([
            'home_highlight_1_title' => '甲', 'home_highlight_1_body' => '甲說明',
            'home_highlight_2_title' => '乙', 'home_highlight_2_body' => '乙說明',
            'home_highlight_3_title' => '丙', 'home_highlight_3_body' => '丙說明',
        ]);

        $strip = $this->highlightStrip($this->get('/')->getContent());

        $this->assertStringContainsString('sm:grid-cols-3', $strip);
        $this->assertStringNotContainsString('sm:grid-cols-2', $strip);
    }

    public function test_editor_text_is_escaped(): void
    {
        $this->settings([
            'home_highlight_1_title' => '<script>alert(1)</script>',
            'home_highlight_1_body' => '"><img src=x onerror=alert(2)>',
        ]);

        $response = $this->get('/');

        $response->assertOk();
        // ⛔ 後台文字一律逸出：這個欄位由人手輸入，不是可信的 HTML 來源。
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertDontSee('<img src=x onerror=alert(2)>', false);
        $response->assertSee('&lt;script&gt;', false);
    }

    public function test_the_model_returns_only_complete_pairs(): void
    {
        $setting = $this->settings([
            'home_highlight_1_title' => '甲',
            'home_highlight_1_body' => '甲說明',
            'home_highlight_2_title' => '乙',
            'home_highlight_3_title' => '丙',
            'home_highlight_3_body' => '丙說明',
        ]);

        $this->assertSame([
            ['title' => '甲', 'body' => '甲說明'],
            ['title' => '丙', 'body' => '丙說明'],
        ], $setting->homeHighlights());
    }
}
