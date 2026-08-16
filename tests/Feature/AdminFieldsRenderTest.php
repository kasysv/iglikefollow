<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\Platform;
use App\Models\Service;
use App\Models\ServiceVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every field the admin offers must appear somewhere on the public site.
 *
 * A field that can be filled in but never renders is worse than a missing
 * feature: the editor believes the text is live, so nobody goes looking for
 * the content that silently is not there. "詳細介紹" (intro) was exactly this
 * on the service page, and on the platform page it appeared only in the
 * "no services yet" branch — so it vanished the moment a platform went live.
 */
class AdminFieldsRenderTest extends TestCase
{
    use RefreshDatabase;

    private function platform(array $attributes = []): Platform
    {
        return Platform::factory()->published()->create(array_merge([
            'slug' => 'instagram',
            'name' => 'Instagram',
        ], $attributes));
    }

    private function service(Platform $platform, array $attributes = []): Service
    {
        $service = Service::factory()->published()->create(array_merge([
            'platform_id' => $platform->id,
            'slug' => 'followers',
            'name' => 'Instagram 粉絲',
        ], $attributes));

        ServiceVariant::factory()->published()->create(['service_id' => $service->id]);

        return $service;
    }

    public function test_the_service_intro_is_rendered_on_the_service_page(): void
    {
        $platform = $this->platform();
        $this->service($platform, ['intro' => '這是服務的詳細介紹內容。']);

        $this->get('/services/instagram/followers')
            ->assertOk()
            ->assertSee('這是服務的詳細介紹內容。');
    }

    public function test_the_service_intro_survives_alongside_content_sections(): void
    {
        $platform = $this->platform();
        $service = $this->service($platform, ['intro' => '服務詳細介紹。']);

        $service->contentSections()->create([
            'heading' => '購買前須知',
            'body' => '段落內容。',
            'status' => 'published',
            'sort_order' => 0,
        ]);

        $this->get('/services/instagram/followers')
            ->assertOk()
            ->assertSee('服務詳細介紹。')
            ->assertSee('購買前須知')
            ->assertSee('段落內容。');
    }

    public function test_the_service_page_stays_clean_when_no_intro_is_set(): void
    {
        $platform = $this->platform();
        $this->service($platform, ['intro' => null]);

        // 沒填就不該留下空區塊。
        $this->get('/services/instagram/followers')->assertOk();
    }

    public function test_multi_line_intro_keeps_its_paragraph_breaks(): void
    {
        $platform = $this->platform();
        $this->service($platform, ['intro' => "第一段。\n第二段。"]);

        $this->get('/services/instagram/followers')
            ->assertOk()
            // whitespace-pre-line 讓後台的換行在前台保留。
            ->assertSee('whitespace-pre-line', false)
            ->assertSee('第二段。');
    }

    public function test_the_platform_intro_is_rendered_when_the_platform_has_services(): void
    {
        $platform = $this->platform(['intro' => '這是平台的詳細介紹內容。']);
        $this->service($platform);

        // 之前只有「服務準備中」的空狀態會顯示這段。
        $this->get('/services/instagram')
            ->assertOk()
            ->assertSee('這是平台的詳細介紹內容。');
    }

    public function test_the_platform_intro_is_still_rendered_in_the_empty_state(): void
    {
        $this->platform(['intro' => '平台服務準備中的說明。']);

        $this->get('/services/instagram')
            ->assertOk()
            ->assertSee('平台服務準備中的說明。');
    }

    public function test_the_platform_intro_appears_near_the_top_of_the_page(): void
    {
        $platform = $this->platform(['intro' => 'PLATFORM-INTRO-値', 'tagline' => 'TAGLINE-値']);
        $this->service($platform);

        $html = $this->get('/services/instagram')->assertOk()->getContent();

        $intro = strpos($html, 'PLATFORM-INTRO-値');
        $tagline = strpos($html, 'TAGLINE-値');
        // 「選擇服務」在頁首導覽也是純文字，⛔ 必須比對 <h2> 標籤才是服務區塊本身。
        $picker = strpos($html, '>選擇服務</h2>');

        // 詳細介紹屬於頁首內容：接在一句話介紹之後、服務列表之前。
        // ⛔ 之前輸出在整頁約 79% 的位置，管理者填了會以為沒有生效。
        $this->assertNotFalse($intro);
        $this->assertGreaterThan($tagline, $intro, '詳細介紹應該排在一句話介紹之後');
        $this->assertLessThan($picker, $intro, '詳細介紹應該排在服務列表之前');
    }

    public function test_the_platform_intro_is_not_printed_twice(): void
    {
        $platform = $this->platform(['intro' => 'UNIQUE-PLATFORM-INTRO']);
        $this->service($platform);

        $html = $this->get('/services/instagram')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'UNIQUE-PLATFORM-INTRO'));
    }

    public function test_the_empty_state_does_not_repeat_the_platform_intro(): void
    {
        // 沒有已發布服務的平台：hero 顯示一次即可。
        $this->platform(['intro' => 'UNIQUE-EMPTY-INTRO']);

        $html = $this->get('/services/instagram')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'UNIQUE-EMPTY-INTRO'));
        $this->assertStringContainsString('服務資料準備中', $html);
    }

    public function test_admin_supplied_intro_is_escaped_not_executed(): void
    {
        $platform = $this->platform(['intro' => '<script>alert(1)</script>']);
        $this->service($platform, ['intro' => '<script>alert(2)</script>']);

        $this->get('/services/instagram')->assertOk()->assertDontSee('<script>alert(1)</script>', false);
        $this->get('/services/instagram/followers')->assertOk()->assertDontSee('<script>alert(2)</script>', false);
    }

    public function test_the_variant_summary_box_renders_the_default_variant_without_javascript(): void
    {
        $platform = $this->platform();
        $service = Service::factory()->published()->create([
            'platform_id' => $platform->id, 'slug' => 'followers',
        ]);

        ServiceVariant::factory()->published()->create([
            'service_id' => $service->id,
            'label' => '一般粉絲',
            'description' => '速度快，適合快速建立帳號初期規模。',
            'is_featured' => true,
        ]);
        ServiceVariant::factory()->published()->create([
            'service_id' => $service->id,
            'label' => '真人粉絲',
            'description' => '來源為真實活躍帳號。',
        ]);

        $html = $this->get('/services/instagram/followers')->assertOk()->getContent();

        // 初始 HTML 就要有預設服務項目的簡介，⛔ 不可只靠 Alpine 補畫。
        $this->assertStringContainsString('服務項目簡介', $html);
        $this->assertStringContainsString('速度快，適合快速建立帳號初期規模。', $html);

        // 另一個服務項目仍必須可以被選到；它的說明由 Alpine 從 x-data 取用
        // （Js::from 會做 unicode escape，比對原字串沒有意義，故只驗選項存在）。
        $this->assertStringContainsString('真人粉絲', $html);
        // 只數 radio；表單另有一個 hidden variant 欄位供送出使用。
        $this->assertSame(2, substr_count($html, 'type="radio" name="variant"'));
    }

    public function test_the_variant_description_is_not_printed_twice(): void
    {
        $platform = $this->platform();
        $service = Service::factory()->published()->create([
            'platform_id' => $platform->id, 'slug' => 'followers',
        ]);
        ServiceVariant::factory()->published()->create([
            'service_id' => $service->id,
            'label' => '一般粉絲',
            'description' => 'UNIQUE-DESCRIPTION-TOKEN',
            'is_featured' => true,
        ]);

        $html = $this->get('/services/instagram/followers')->assertOk()->getContent();

        // x-data 的 bounds JSON 也帶著同一段文字供切換使用，那不是顯示出來的內容，
        // 因此只計算可見區域：把 x-data 屬性整段移除後再數。
        $visible = preg_replace('/x-data="[^"]*"/s', '', $html);

        // 卡片內與簡介框重複同一段文字會讓版面變雜；卡片已改為不重複輸出。
        $this->assertSame(
            1,
            substr_count($visible, 'UNIQUE-DESCRIPTION-TOKEN'),
            '服務項目說明在可見的初始 HTML 中出現超過一次'
        );
    }

    public function test_a_multi_line_variant_description_keeps_its_line_breaks(): void
    {
        $platform = $this->platform();
        $service = Service::factory()->published()->create([
            'platform_id' => $platform->id, 'slug' => 'followers',
        ]);

        $description = "- 帳號類型：台灣普通｜男女混合\n- 掉粉機率：極低\n- 執行時間：6–48 小時內啟動";

        ServiceVariant::factory()->published()->create([
            'service_id' => $service->id,
            'description' => $description,
            'is_featured' => true,
        ]);

        $html = $this->get('/services/instagram/followers')->assertOk()->getContent();

        // ⛔ 沒有 whitespace-pre-line 時 HTML 會把後台輸入的換行折成一整段。
        $this->assertMatchesRegularExpression(
            '/whitespace-pre-line[^>]*"\s+x-text="b\.description/',
            $html,
            '服務項目簡介框缺少 whitespace-pre-line，後台的換行不會顯示'
        );

        // 換行字元本身也必須保留在初始 HTML 裡。
        $this->assertStringContainsString("- 掉粉機率：極低\n", $html);
    }

    public function test_a_long_variant_description_survives_the_round_trip(): void
    {
        $platform = $this->platform();
        $service = Service::factory()->published()->create([
            'platform_id' => $platform->id, 'slug' => 'followers',
        ]);

        // 中文在 VARCHAR(255) 是 3 bytes/字，約 90 字就會逼近上限並被截斷。
        $description = str_repeat('帳號類型台灣普通男女混合隨機配置非男女各半。', 12);
        $this->assertGreaterThan(255, strlen($description));

        $variant = ServiceVariant::factory()->published()->create([
            'service_id' => $service->id,
            'description' => $description,
            'is_featured' => true,
        ]);

        $this->assertSame($description, $variant->fresh()->description);
    }

    public function test_the_summary_box_is_omitted_when_no_variant_has_a_description(): void
    {
        $platform = $this->platform();
        $service = Service::factory()->published()->create([
            'platform_id' => $platform->id, 'slug' => 'followers',
        ]);
        ServiceVariant::factory()->published()->create([
            'service_id' => $service->id, 'description' => null, 'is_featured' => true,
        ]);

        // 沒有任何說明時不該留下空框。
        $this->get('/services/instagram/followers')->assertOk()->assertDontSee('服務項目簡介');
    }

    public function test_a_variant_description_is_escaped_not_executed(): void
    {
        $platform = $this->platform();
        $service = Service::factory()->published()->create([
            'platform_id' => $platform->id, 'slug' => 'followers',
        ]);
        ServiceVariant::factory()->published()->create([
            'service_id' => $service->id,
            'description' => '<script>alert(3)</script>',
            'is_featured' => true,
        ]);

        $this->get('/services/instagram/followers')
            ->assertOk()
            ->assertDontSee('<script>alert(3)</script>', false);
    }

    public function test_the_storefront_never_calls_a_variant_a_kuanshi(): void
    {
        $platform = $this->platform(['intro' => '平台介紹']);
        $this->service($platform, ['intro' => '服務介紹']);

        // 使用者決定統一用「服務項目」，⛔ 前台不得再出現舊詞「款式」。
        foreach (['/', '/services/instagram', '/services/instagram/followers'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('款式');
        }
    }

    /**
     * Guards the whole class of bug rather than the one field that had it.
     */
    public function test_every_text_field_the_admin_offers_reaches_the_public_page(): void
    {
        $platform = $this->platform([
            'eyebrow' => 'EYEBROW-値',
            'h1' => 'H1-値',
            'tagline' => 'TAGLINE-値',
            'intro' => 'PLATFORM-INTRO-値',
        ]);

        $service = $this->service($platform, [
            'h1' => 'SERVICE-H1-値',
            'summary' => 'SUMMARY-値',
            'intro' => 'SERVICE-INTRO-値',
            'card_blurb' => 'BLURB-値',
            'goal' => 'GOAL-値',
            'input_label' => 'INPUT-LABEL-値',
            'input_hint' => 'INPUT-HINT-値',
            'delivery_summary' => 'DELIVERY-値',
        ]);

        Faq::create([
            'scope' => 'service',
            'service_id' => $service->id,
            'question' => 'FAQ-QUESTION-値',
            'answer' => 'FAQ-ANSWER-値',
            'status' => 'published',
            'sort_order' => 0,
        ]);

        $hub = $this->get('/services/instagram')->assertOk();
        foreach (['EYEBROW-値', 'H1-値', 'TAGLINE-値', 'PLATFORM-INTRO-値', 'BLURB-値', 'GOAL-値', 'DELIVERY-値'] as $value) {
            $hub->assertSee($value);
        }

        $page = $this->get('/services/instagram/followers')->assertOk();
        foreach (['SERVICE-H1-値', 'SUMMARY-値', 'SERVICE-INTRO-値', 'INPUT-LABEL-値', 'FAQ-QUESTION-値', 'FAQ-ANSWER-値'] as $value) {
            $page->assertSee($value, false);
        }

        // input_hint 是交付欄位的 placeholder，改為兩頁式後屬於 /checkout。
        $variant = $service->variants()->first();
        $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => $variant->default_quantity])
            ->assertRedirect(route('checkout'));

        $this->get('/checkout')->assertOk()
            ->assertSee('INPUT-HINT-値', false)
            ->assertSee('INPUT-LABEL-値', false)
            ->assertSee('DELIVERY-値', false);
    }
}
