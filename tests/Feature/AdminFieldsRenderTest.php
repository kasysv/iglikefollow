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

    public function test_admin_supplied_intro_is_escaped_not_executed(): void
    {
        $platform = $this->platform(['intro' => '<script>alert(1)</script>']);
        $this->service($platform, ['intro' => '<script>alert(2)</script>']);

        $this->get('/services/instagram')->assertOk()->assertDontSee('<script>alert(1)</script>', false);
        $this->get('/services/instagram/followers')->assertOk()->assertDontSee('<script>alert(2)</script>', false);
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
        foreach (['SERVICE-H1-値', 'SUMMARY-値', 'SERVICE-INTRO-値', 'INPUT-LABEL-値', 'INPUT-HINT-値', 'FAQ-QUESTION-値', 'FAQ-ANSWER-値'] as $value) {
            $page->assertSee($value, false);
        }
    }
}
