<?php

namespace Tests\Feature;

use Tests\TestCase;

class StorefrontTest extends TestCase
{
    public function test_home_contains_primary_content_in_initial_html(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<h1', false)
            ->assertSee('讓成長，', false)
            ->assertSee('Instagram 粉絲服務')
            ->assertSee('購買前常見問題')
            ->assertSee('followers-1000', false);
    }

    public function test_mock_checkout_validates_input(): void
    {
        $this->post('/checkout/mock', [])
            ->assertSessionHasErrors(['plan', 'target', 'payment']);
    }

    public function test_mock_checkout_never_creates_a_real_order(): void
    {
        $this->post('/checkout/mock', [
            'plan' => 'followers-1000',
            'target' => 'example_account',
            'payment' => 'line-pay',
        ])->assertOk()
            ->assertSee('本機 MOCK')
            ->assertSee('沒有扣款、沒有建立資料庫訂單')
            ->assertSee('example_account');
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
