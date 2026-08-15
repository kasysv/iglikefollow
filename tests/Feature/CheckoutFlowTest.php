<?php

namespace Tests\Feature;

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\MockCheckoutController;
use App\Models\ServiceVariant;
use App\Support\CheckoutSession;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two-page flow itself: selection → session → /checkout → mock submit.
 *
 * Product data lives only in the server-side session, so these assert that a
 * customer cannot influence what is being bought from the checkout form, and
 * that a stale or invalid selection degrades safely instead of erroring.
 */
class CheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    private function variant(string $sku = 'ig-followers-standard'): ServiceVariant
    {
        return ServiceVariant::query()->where('sku', $sku)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function orderForm(array $overrides = []): array
    {
        return array_merge([
            'target' => 'example_account',
            'payment' => 'line-pay',
            'customer_email' => 'buyer@example.com',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'email',
        ], $overrides);
    }

    // ---------------------------------------------------------- start endpoint

    public function test_a_valid_selection_redirects_to_checkout(): void
    {
        $this->post('/checkout/start', ['variant' => $this->variant()->id, 'quantity' => 1000])
            ->assertRedirect(route('checkout'));
    }

    public function test_the_redirect_url_carries_no_product_or_personal_data(): void
    {
        $response = $this->post('/checkout/start', [
            'variant' => $this->variant()->id,
            'quantity' => 1000,
        ]);

        $location = $response->headers->get('Location');

        // ⛔ variant、quantity 與任何個資都不得出現在 query string。
        $this->assertSame(route('checkout'), $location);
        $this->assertStringNotContainsString('?', (string) $location);
    }

    public function test_the_session_stores_no_price_and_no_personal_data(): void
    {
        $this->post('/checkout/start', ['variant' => $this->variant()->id, 'quantity' => 1000]);

        $stored = session(CheckoutSession::KEY);

        $this->assertSame(['variant_id', 'quantity', 'return_url'], array_keys($stored));
        // 價格不存 session：⛔ 過期的金額絕不能被拿來結帳。
        $this->assertArrayNotHasKey('amount', $stored);
        $this->assertArrayNotHasKey('unit_price', $stored);
    }

    public function test_start_ignores_a_client_supplied_price(): void
    {
        $this->post('/checkout/start', [
            'variant' => $this->variant()->id,
            'quantity' => 1000,
            'price' => 1,
            'amount' => 1,
        ]);

        // 1000 × 0.59 = 590
        $this->get('/checkout')->assertOk()->assertSee('NT$590');
    }

    public function test_start_rejects_an_archived_variant(): void
    {
        $variant = $this->variant();
        $variant->update(['status' => 'archived']);

        $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => 1000])
            ->assertSessionHasErrors('variant');
    }

    public function test_start_rejects_a_variant_whose_service_is_a_draft(): void
    {
        $variant = $this->variant();
        $variant->service->update(['status' => 'draft']);

        $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => 1000])
            ->assertSessionHasErrors('variant');
    }

    // ---------------------------------------------------------- 安全返回

    public function test_checkout_without_a_session_returns_to_the_storefront(): void
    {
        // ⛔ 直接開 /checkout 不得 500。
        $this->get('/checkout')->assertRedirect();
    }

    public function test_checkout_explains_why_it_sent_the_customer_back(): void
    {
        $this->get('/checkout')->assertSessionHas('checkout_notice');
    }

    public function test_an_unpublished_variant_falls_back_to_the_platform_picker(): void
    {
        $variant = $this->variant();
        $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => 1000]);

        $variant->update(['status' => 'draft']);

        // 商品已下架就查不到它的服務頁，⛔ 因此退回首頁平台區塊而不是壞連結。
        $this->get('/checkout')
            ->assertRedirect(route('home').'#platforms')
            ->assertSessionHas('checkout_notice');
    }

    public function test_a_still_published_product_returns_to_its_own_service_page(): void
    {
        $variant = $this->variant();
        $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => 1000]);

        // 商品仍在架上、只是數量不再合法時，應回到原本的服務頁。
        $variant->update(['min_quantity' => 5000, 'default_quantity' => 5000]);

        $this->get('/checkout')->assertRedirect(
            route('service', ['instagram', $variant->service->slug])
        );
    }

    public function test_a_quantity_that_no_longer_fits_returns_safely(): void
    {
        $variant = $this->variant();
        $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => 1000]);

        // 管理者事後調高最低數量，session 裡的數量就不再合法。
        $variant->update(['min_quantity' => 5000, 'default_quantity' => 5000]);

        $this->get('/checkout')->assertRedirect();
    }

    public function test_returning_to_the_service_page_restores_the_selection(): void
    {
        $taiwan = $this->variant('ig-followers-taiwan');
        $this->post('/checkout/start', ['variant' => $taiwan->id, 'quantity' => 300]);

        // 「返回修改」帶 ?resume=1，不得要求客人重新挑一次。
        $html = $this->get('/services/instagram/followers?resume=1')->assertOk()->getContent();

        $this->assertStringContainsString("variant: '{$taiwan->id}'", $html);
        $this->assertStringContainsString('quantity: 300', $html);
    }

    public function test_a_plain_visit_shows_the_default_variant_not_the_last_selection(): void
    {
        $taiwan = $this->variant('ig-followers-taiwan');
        $featured = $this->variant('ig-followers-standard');

        $this->post('/checkout/start', ['variant' => $taiwan->id, 'quantity' => 300]);

        // ⛔ 一般瀏覽（重新整理、從導覽再進來）必須回到預設項目，
        // 不能一直停在上次選的那張卡片。
        $html = $this->get('/services/instagram/followers')->assertOk()->getContent();

        $this->assertStringContainsString("variant: '{$featured->id}'", $html);
        $this->assertStringNotContainsString("variant: '{$taiwan->id}'", $html);
        $this->assertStringContainsString('quantity: '.$featured->default_quantity, $html);
    }

    public function test_the_default_variant_card_is_checked_on_a_plain_visit(): void
    {
        $featured = $this->variant('ig-followers-standard');

        $html = $this->get('/services/instagram/followers')->assertOk()->getContent();

        // 第一張卡片必須帶 checked，⛔ 否則沒有任何卡片顯示為選中。
        $this->assertMatchesRegularExpression(
            '/value="'.$featured->id.'"[^>]*checked/',
            $html
        );
    }

    public function test_the_return_link_carries_the_resume_flag(): void
    {
        $this->post('/checkout/start', ['variant' => $this->variant()->id, 'quantity' => 1000]);

        $this->get('/checkout')->assertOk()->assertSee('resume=1', false);
    }

    // ---------------------------------------------------------- 最終提交

    public function test_the_form_cannot_override_the_variant_or_quantity(): void
    {
        $this->post('/checkout/start', ['variant' => $this->variant()->id, 'quantity' => 1000]);

        $expensive = $this->variant('ig-followers-taiwan');

        $this->post('/checkout/mock', $this->orderForm([
            'variant' => $expensive->id,
            'quantity' => 99999,
        ]))->assertOk()
            // 仍是 session 裡的一般粉絲 1000 個 = 590，⛔ 不採用表單送來的商品。
            ->assertSee('一般粉絲')
            ->assertSee('NT$590')
            ->assertDontSee('99,999');
    }

    public function test_the_final_amount_uses_the_current_price_not_the_one_at_start(): void
    {
        $variant = $this->variant();
        $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => 1000]);

        $this->get('/checkout')->assertOk()->assertSee('NT$590');

        // 選好之後管理者調價：最終金額必須用「當下」單價重算。
        $variant->update(['unit_price' => 1.00]);

        $this->post('/checkout/mock', $this->orderForm())
            ->assertOk()
            ->assertSee('NT$1,000')
            ->assertDontSee('NT$590');
    }

    public function test_a_successful_submission_clears_the_selection(): void
    {
        $this->post('/checkout/start', ['variant' => $this->variant()->id, 'quantity' => 1000]);

        $this->post('/checkout/mock', $this->orderForm())->assertOk();

        $this->assertNull(session(CheckoutSession::KEY));
    }

    public function test_resubmitting_after_success_creates_nothing(): void
    {
        $this->post('/checkout/start', ['variant' => $this->variant()->id, 'quantity' => 1000]);
        $this->post('/checkout/mock', $this->orderForm())->assertOk();

        // 重新送出時 session 已清空，⛔ 不得再產生任何東西。
        $this->post('/checkout/mock', $this->orderForm())->assertRedirect(route('checkout'));
    }

    // ---------------------------------------------------------- 索引

    public function test_checkout_is_never_indexable(): void
    {
        // 即使全站開放索引，訂單表單也不得進入搜尋結果。
        config()->set('app.env', 'production');
        config()->set('seo.allow_indexing', true);
        config()->set('seo.indexable_host', 'localhost');

        $this->post('/checkout/start', ['variant' => $this->variant()->id, 'quantity' => 1000]);

        $response = $this->get('/checkout')->assertOk();

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString(
            '<meta name="robots" content="noindex, nofollow">',
            $response->getContent()
        );
    }

    public function test_checkout_is_not_linked_from_public_navigation(): void
    {
        foreach (['/', '/services/instagram'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('href="'.route('checkout').'"', false);
        }
    }

    public function test_the_checkout_layout_drops_the_platform_navigation(): void
    {
        $this->post('/checkout/start', ['variant' => $this->variant()->id, 'quantity' => 1000]);

        $html = $this->get('/checkout')->assertOk()->getContent();

        // enclosed checkout：⛔ 不放會讓客人中途離開的平台導覽。
        $this->assertStringNotContainsString('aria-label="主要導覽"', $html);
        $this->assertStringNotContainsString('aria-label="頁尾服務導覽"', $html);
        // 但品牌 Logo 仍在。
        $this->assertStringContainsString('iglikefollow-logo', $html);
    }

    // ---------------------------------------------------------- 環境限制

    public function test_the_checkout_endpoints_are_local_only(): void
    {
        $this->assertTrue(app()->environment('testing'));

        // 三個端點都在 controller 內檢查 environment。
        foreach ([
            CheckoutController::class,
            MockCheckoutController::class,
        ] as $controller) {
            $source = file_get_contents((new \ReflectionClass($controller))->getFileName());
            $this->assertStringContainsString("environment(['local', 'testing'])", $source);
        }
    }
}
