<?php

namespace Tests\Feature;

use Tests\TestCase;

class StorefrontTest extends TestCase
{
    public function test_home_presents_company_and_all_platforms_in_initial_html(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<h1', false)
            ->assertSee('多平台社群服務', false)
            ->assertSee('Instagram')
            ->assertSee('Facebook')
            ->assertSee('Threads')
            ->assertSee('購買前常見問題');
    }

    public function test_home_links_to_every_platform_hub(): void
    {
        $response = $this->get('/');

        foreach (['instagram', 'facebook', 'threads'] as $platform) {
            $response->assertSee('/services/'.$platform, false);
        }
    }

    public function test_home_has_exactly_one_h1(): void
    {
        $this->assertSame(1, substr_count($this->get('/')->getContent(), '<h1'));
    }

    public function test_home_does_not_embed_the_checkout_form(): void
    {
        // 首頁不應預設所有人購買單一服務；結帳移到選定服務之後。
        $this->get('/')->assertDontSee('name="plan"', false);
    }

    public function test_instagram_hub_lists_its_services(): void
    {
        $response = $this->get('/services/instagram')
            ->assertOk()
            ->assertSee('Instagram 粉絲')
            ->assertSee('Instagram 單篇貼文讚')
            ->assertSee('Instagram 自動貼文讚')
            ->assertSee('Instagram 貼文留言')
            ->assertSee('Instagram Reel／IGTV 影片觀看');

        $this->assertSame(1, substr_count($response->getContent(), '<h1'));
    }

    public function test_facebook_hub_lists_its_services(): void
    {
        $this->get('/services/facebook')
            ->assertOk()
            ->assertSee('Facebook 粉專／個人／社團粉絲')
            ->assertSee('Facebook 貼文讚')
            ->assertSee('Facebook 貼文留言／粉專評論')
            ->assertSee('Facebook Reel／影片觀看');
    }

    public function test_threads_hub_is_an_honest_empty_state(): void
    {
        $this->get('/services/threads')
            ->assertOk()
            ->assertSee('服務資料準備中')
            ->assertDontSee('name="plan"', false)
            ->assertDontSee('NT$', false);
    }

    public function test_service_page_shows_plans_and_correct_input_label(): void
    {
        $this->get('/services/instagram/post-likes')
            ->assertOk()
            ->assertSee('Instagram 單篇貼文讚')
            ->assertSee('Instagram 貼文網址')
            ->assertSee('ig-post-likes-500', false);
    }

    public function test_auto_likes_page_explains_prepaid_delivery(): void
    {
        $this->get('/services/instagram/auto-likes')
            ->assertOk()
            ->assertSee('預付')
            ->assertSee('公開帳號', false);
    }

    public function test_unknown_platform_or_service_returns_404(): void
    {
        $this->get('/services/nope')->assertNotFound();
        $this->get('/services/instagram/nope')->assertNotFound();
    }

    public function test_mock_checkout_validates_input(): void
    {
        $this->post('/checkout/mock', [])
            ->assertSessionHasErrors(['plan', 'target', 'payment']);
    }

    public function test_mock_checkout_never_creates_a_real_order(): void
    {
        $this->post('/checkout/mock', [
            'plan' => 'ig-followers-1000',
            'target' => 'example_account',
            'payment' => 'line-pay',
        ])->assertOk()
            ->assertSee('本機 MOCK')
            ->assertSee('沒有扣款、沒有建立資料庫訂單')
            ->assertSee('example_account');
    }

    public function test_mock_checkout_accepts_plans_from_any_platform(): void
    {
        $this->post('/checkout/mock', [
            'plan' => 'fb-views-5000',
            'target' => 'https://facebook.com/reel/123456',
            'payment' => 'ecpay',
        ])->assertOk()
            ->assertSee('Facebook')
            ->assertSee('綠界付款');
    }

    public function test_mock_checkout_rejects_unknown_plan_and_payment(): void
    {
        $this->post('/checkout/mock', [
            'plan' => 'not-a-plan',
            'target' => 'example_account',
            'payment' => 'not-a-gateway',
        ])->assertSessionHasErrors(['plan', 'payment']);
    }

    public function test_unknown_page_uses_custom_404(): void
    {
        $this->get('/does-not-exist')
            ->assertNotFound()
            ->assertSee('這個頁面不存在');
    }
}
