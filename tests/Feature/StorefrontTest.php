<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceVariant;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 前台資料一律來自資料庫；seeder 只是把既有 fixture 匯入。
        $this->seed(CatalogSeeder::class);
    }

    private function followersVariantId(): int
    {
        return ServiceVariant::query()->where('sku', 'ig-followers-standard')->value('id');
    }

    /**
     * A complete mock checkout payload.
     *
     * Contact and e-invoice fields are required, so every checkout test needs
     * them; overriding one key here keeps each test focused on what it asserts.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function checkoutPayload(array $overrides = []): array
    {
        return array_merge([
            'variant' => $this->followersVariantId(),
            'quantity' => 1000,
            'target' => 'example_account',
            'payment' => 'line-pay',
            'customer_email' => 'buyer@example.com',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'email',
        ], $overrides);
    }

    public function test_home_presents_company_and_published_platforms_in_initial_html(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<h1', false)
            ->assertSee('多平台社群服務', false)
            ->assertSee('Instagram')
            ->assertSee('Facebook')
            ->assertSee('購買前常見問題')
            // Threads 仍是草稿；⛔ 導覽不得連到會 404 的頁面。
            ->assertDontSee('/services/threads', false);
    }

    public function test_home_links_to_every_published_platform_hub(): void
    {
        $response = $this->get('/');

        foreach (['instagram', 'facebook'] as $platform) {
            $response->assertSee('/services/'.$platform, false);
        }
    }

    public function test_home_has_exactly_one_h1(): void
    {
        $this->assertSame(1, substr_count($this->get('/')->getContent(), '<h1'));
    }

    public function test_home_does_not_embed_the_checkout_form(): void
    {
        $this->get('/')->assertDontSee('name="plan"', false);
    }

    public function test_instagram_hub_lists_its_services(): void
    {
        $response = $this->get('/services/instagram')
            ->assertOk()
            ->assertSee('粉絲')
            ->assertSee('單篇貼文讚')
            ->assertSee('自動貼文讚')
            ->assertSee('貼文留言');

        $this->assertSame(1, substr_count($response->getContent(), '<h1'));
    }

    public function test_facebook_hub_lists_its_services(): void
    {
        $this->get('/services/facebook')
            ->assertOk()
            ->assertSee('粉專／個人／社團粉絲')
            ->assertSee('貼文讚');
    }

    public function test_threads_hub_is_an_honest_empty_state(): void
    {
        // Threads 沒有已發布服務，屬 draft 平台，公開頁應為 404。
        $this->get('/services/threads')->assertNotFound();
    }

    public function test_service_page_shows_variants_and_correct_input_label(): void
    {
        $this->get('/services/instagram/post-likes')
            ->assertOk()
            ->assertSee('單篇貼文讚')
            ->assertSee('Instagram 貼文網址');
    }

    public function test_followers_page_offers_every_variant_in_initial_html(): void
    {
        $this->get('/services/instagram/followers')
            ->assertOk()
            ->assertSee('選擇款式')
            ->assertSee('一般粉絲')
            ->assertSee('真人粉絲')
            ->assertSee('台灣粉絲');
    }

    public function test_service_page_uses_free_quantity_input_not_fixed_tiers(): void
    {
        $this->get('/services/instagram/followers')
            ->assertOk()
            ->assertSee('name="quantity"', false)
            ->assertSee('type="number"', false)
            ->assertSee('輸入數量', false);
    }

    public function test_every_service_declares_a_quantity_unit(): void
    {
        foreach (ServiceVariant::all() as $variant) {
            $this->assertNotEmpty($variant->quantity_unit, "variant {$variant->sku} is missing quantity_unit");
        }
    }

    public function test_followers_page_renders_its_quantity_unit(): void
    {
        $this->get('/services/instagram/followers')
            ->assertOk()
            ->assertSee('輸入數量（個）')
            ->assertDontSee('輸入數量（）');
    }

    public function test_catalog_carries_no_unevidenced_performance_claims(): void
    {
        $banned = ['互動率較高', '保證', '最快', '100%', '永久'];
        $text = ServiceVariant::all()->pluck('description')->join(' ')
            .Service::all()->pluck('summary')->join(' ');

        foreach ($banned as $claim) {
            $this->assertStringNotContainsString($claim, $text);
        }
    }

    public function test_hub_has_one_featured_service_and_goal_navigation(): void
    {
        $this->get('/services/instagram')
            ->assertOk()
            ->assertSee('主打服務')
            ->assertSee('你想達成什麼目標？')
            ->assertSee('帳號規模')
            ->assertSee('影片曝光')
            ->assertSee('服務比較');
    }

    public function test_hub_links_every_published_service_with_native_anchors(): void
    {
        $response = $this->get('/services/instagram')->assertOk();

        $slugs = Service::query()
            ->whereHas('platform', fn ($q) => $q->where('slug', 'instagram'))
            ->published()
            ->pluck('slug');

        foreach ($slugs as $slug) {
            $response->assertSee('/services/instagram/'.$slug, false);
        }
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
            ->assertSessionHasErrors(['variant', 'quantity', 'target', 'payment']);
    }

    public function test_mock_checkout_never_creates_a_real_order(): void
    {
        $this->post('/checkout/mock', $this->checkoutPayload())
            ->assertOk()
            ->assertSee('本機 MOCK')
            ->assertSee('沒有扣款、沒有建立資料庫訂單')
            ->assertSee('example_account');
    }

    public function test_mock_checkout_accepts_variants_from_any_platform(): void
    {
        $id = ServiceVariant::query()->where('sku', 'fb-views-standard')->value('id');

        $this->post('/checkout/mock', $this->checkoutPayload([
            'variant' => $id,
            'quantity' => 5000,
            'target' => 'https://facebook.com/reel/123456',
            'payment' => 'ecpay',
        ]))->assertOk()
            ->assertSee('Facebook')
            ->assertSee('綠界付款');
    }

    public function test_mock_checkout_rejects_quantity_below_minimum(): void
    {
        $this->post('/checkout/mock', $this->checkoutPayload(['quantity' => 10]))
            ->assertSessionHasErrors('quantity');
    }

    public function test_mock_checkout_rejects_quantity_above_maximum(): void
    {
        $id = ServiceVariant::query()->where('sku', 'ig-followers-taiwan')->value('id');

        $this->post('/checkout/mock', $this->checkoutPayload([
            'variant' => $id,
            'quantity' => 999999,
        ]))->assertSessionHasErrors('quantity');
    }

    public function test_mock_checkout_rejects_quantity_not_matching_step(): void
    {
        $this->post('/checkout/mock', $this->checkoutPayload(['quantity' => 155]))
            ->assertSessionHasErrors('quantity');
    }

    public function test_mock_checkout_recalculates_amount_server_side(): void
    {
        // 1000 × 0.59 = 590；前端即使送出別的金額也不會被採用。
        $this->post('/checkout/mock', $this->checkoutPayload([
            'price' => 1,
            'amount' => 1,
        ]))->assertOk()->assertSee('NT$590');
    }

    public function test_mock_checkout_rejects_unknown_variant_and_payment(): void
    {
        $this->post('/checkout/mock', [
            'variant' => 999999,
            'quantity' => 1000,
            'target' => 'example_account',
            'payment' => 'not-a-gateway',
        ])->assertSessionHasErrors(['variant', 'payment']);
    }

    public function test_draft_variants_are_not_purchasable(): void
    {
        $variant = ServiceVariant::query()->where('sku', 'ig-followers-real')->first();
        $variant->update(['status' => 'draft']);

        $this->post('/checkout/mock', [
            'variant' => $variant->id,
            'quantity' => 1000,
            'target' => 'example_account',
            'payment' => 'line-pay',
        ])->assertSessionHasErrors('variant');
    }

    public function test_draft_service_is_not_publicly_reachable(): void
    {
        Service::query()
            ->whereHas('platform', fn ($q) => $q->where('slug', 'instagram'))
            ->where('slug', 'comments')
            ->update(['status' => 'draft']);

        $this->get('/services/instagram/comments')->assertNotFound();
        $this->get('/services/instagram')->assertOk()->assertDontSee('/services/instagram/comments', false);
    }

    public function test_unknown_page_uses_custom_404(): void
    {
        $this->get('/does-not-exist')
            ->assertNotFound()
            ->assertSee('這個頁面不存在');
    }
}
