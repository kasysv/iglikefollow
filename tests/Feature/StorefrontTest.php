<?php

namespace Tests\Feature;

use App\Models\PaymentAttempt;
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

    /** Select a product first; /checkout reads it from the session. */
    private function startCheckout(?int $variantId = null, int $quantity = 1000): void
    {
        $this->post('/checkout/start', [
            'variant' => $variantId ?? $this->followersVariantId(),
            'quantity' => $quantity,
        ])->assertRedirect(route('checkout'));
    }

    /**
     * The order form payload.
     *
     * Carries no variant or quantity: those come from the checkout session, so
     * the form cannot override what the customer selected on the service page.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function checkoutPayload(array $overrides = []): array
    {
        return array_merge([
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
            ->assertSee('選擇服務項目')
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
        // 單位改由 Alpine 隨服務項目切換，初始 HTML 仍必須帶著預設單位；
        // ⛔ 原本的缺陷是這裡渲染成「輸入數量（）」。
        $this->get('/services/instagram/followers')
            ->assertOk()
            ->assertSee('輸入數量（<span x-text="b.unit">個</span>）', false)
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

    public function test_checkout_start_validates_the_product_selection(): void
    {
        $this->post('/checkout/start', [])->assertSessionHasErrors(['variant', 'quantity']);
    }

    public function test_mock_checkout_validates_the_order_form(): void
    {
        $this->startCheckout();

        // variant／quantity 來自 session，⛔ 這裡只驗訂單表單本身的欄位。
        $this->post('/checkout/mock', [])
            ->assertSessionHasErrors(['target', 'payment', 'customer_email', 'invoice_kind']);
    }

    public function test_mock_checkout_creates_a_local_order_but_charges_nothing(): void
    {
        $this->startCheckout();

        // M3A 起會真的建立本站訂單；⛔ 但仍不扣款、不呼叫任何金流。
        $this->post('/checkout/mock', $this->checkoutPayload())
            ->assertOk()
            ->assertSee('本機 MOCK')
            ->assertSee('沒有扣款、沒有呼叫任何金流或履約服務')
            ->assertSee('example_account');
    }

    public function test_mock_checkout_accepts_variants_from_any_platform(): void
    {
        $id = ServiceVariant::query()->where('sku', 'fb-views-standard')->value('id');

        $this->startCheckout($id, 5000);

        $this->post('/checkout/mock', $this->checkoutPayload([
            'target' => 'https://facebook.com/reel/123456',
            'payment' => 'ecpay',
        ]))->assertOk()
            ->assertSee('Facebook')
            ->assertSee('https://facebook.com/reel/123456');

        // 付款 provider 記在該次付款嘗試上，⛔ 不是訂單上的一段文字。
        $this->assertSame('ecpay', PaymentAttempt::latest('id')->value('provider'));
    }

    public function test_checkout_start_rejects_quantity_below_minimum(): void
    {
        $this->post('/checkout/start', [
            'variant' => $this->followersVariantId(),
            'quantity' => 10,
        ])->assertSessionHasErrors('quantity');
    }

    public function test_checkout_start_rejects_quantity_above_maximum(): void
    {
        $id = ServiceVariant::query()->where('sku', 'ig-followers-taiwan')->value('id');

        $this->post('/checkout/start', ['variant' => $id, 'quantity' => 999999])
            ->assertSessionHasErrors('quantity');
    }

    public function test_checkout_start_rejects_quantity_not_matching_step(): void
    {
        $this->post('/checkout/start', [
            'variant' => $this->followersVariantId(),
            'quantity' => 155,
        ])->assertSessionHasErrors('quantity');
    }

    public function test_mock_checkout_recalculates_amount_server_side(): void
    {
        // 1000 × 0.59 = 590；前端即使送出別的金額也不會被採用。
        $this->startCheckout();

        $this->post('/checkout/mock', $this->checkoutPayload([
            'price' => 1,
            'amount' => 1,
        ]))->assertOk()->assertSee('NT$590');
    }

    public function test_checkout_start_rejects_an_unknown_variant(): void
    {
        $this->post('/checkout/start', ['variant' => 999999, 'quantity' => 1000])
            ->assertSessionHasErrors('variant');
    }

    public function test_mock_checkout_rejects_an_unknown_payment(): void
    {
        $this->startCheckout();

        $this->post('/checkout/mock', $this->checkoutPayload(['payment' => 'not-a-gateway']))
            ->assertSessionHasErrors('payment');
    }

    public function test_draft_variants_are_not_purchasable(): void
    {
        $variant = ServiceVariant::query()->where('sku', 'ig-followers-real')->first();
        $variant->update(['status' => 'draft']);

        $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => 1000])
            ->assertSessionHasErrors('variant');
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
