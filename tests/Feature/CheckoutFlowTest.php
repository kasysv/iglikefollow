<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceVariant;
use App\Support\CheckoutSession;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
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

        // token 是防重複建單用的識別碼，⛔ 不是個資也不是價格。
        $this->assertSame(['variant_id', 'quantity', 'return_url', 'token'], array_keys($stored));
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
            $variant->service->fresh()->primaryUrl()
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

        $this->post('/checkout/return')->assertRedirect(
            Service::query()->where('product_slug', 'ig買粉絲')->firstOrFail()->primaryUrl().'#checkout'
        );

        // 「返回修改」不得要求客人重新挑一次。
        $html = $this->get('/product/ig買粉絲/')->assertOk()->getContent();

        $this->assertStringContainsString("variant: '{$taiwan->id}'", $html);
        $this->assertStringContainsString('quantity: 300', $html);
    }

    public function test_the_resume_marker_is_consumed_after_one_render(): void
    {
        $taiwan = $this->variant('ig-followers-taiwan');
        $featured = $this->variant('ig-followers-standard');

        $this->post('/checkout/start', ['variant' => $taiwan->id, 'quantity' => 300]);
        $this->post('/checkout/return');

        // 第一次：恢復原選擇。
        $this->get('/product/ig買粉絲/')->assertOk()
            ->assertSee("variant: '{$taiwan->id}'", false);

        // 重新整理同一個乾淨網址：⛔ marker 已用掉，回到預設項目。
        $this->get('/product/ig買粉絲/')->assertOk()
            ->assertSee("variant: '{$featured->id}'", false)
            ->assertDontSee("variant: '{$taiwan->id}'", false);
    }

    public function test_no_public_url_carries_a_resume_parameter(): void
    {
        $this->post('/checkout/start', ['variant' => $this->variant()->id, 'quantity' => 1000]);

        // ⛔ 商品頁不得因返回而多出第二條可抓取網址。
        $this->get('/checkout')->assertOk()->assertDontSee('resume=1', false);

        $location = $this->post('/checkout/return')->headers->get('Location');

        $this->assertStringNotContainsString('resume', (string) $location);
        $this->assertStringNotContainsString('?', (string) $location);
    }

    public function test_the_return_endpoint_cannot_be_pointed_elsewhere(): void
    {
        $this->post('/checkout/start', ['variant' => $this->variant()->id, 'quantity' => 1000]);

        // 目的地只由 server-side session 重新推導，⛔ 請求帶什麼都不採用。
        $location = $this->post('/checkout/return', [
            'return_url' => 'https://evil.example.com',
            'url' => 'https://evil.example.com',
        ])->headers->get('Location');

        $this->assertSame(
            Service::query()->where('product_slug', 'ig買粉絲')->firstOrFail()->primaryUrl().'#checkout',
            $location
        );
    }

    public function test_the_return_endpoint_without_a_selection_recovers_safely(): void
    {
        $this->post('/checkout/return')
            ->assertRedirect()
            ->assertSessionHas('checkout_notice');
    }

    public function test_a_plain_visit_shows_the_default_variant_not_the_last_selection(): void
    {
        $taiwan = $this->variant('ig-followers-taiwan');
        $featured = $this->variant('ig-followers-standard');

        $this->post('/checkout/start', ['variant' => $taiwan->id, 'quantity' => 300]);

        // ⛔ 一般瀏覽（重新整理、從導覽再進來）必須回到預設項目，
        // 不能一直停在上次選的那張卡片。
        $html = $this->get('/product/ig買粉絲/')->assertOk()->getContent();

        $this->assertStringContainsString("variant: '{$featured->id}'", $html);
        $this->assertStringNotContainsString("variant: '{$taiwan->id}'", $html);
        $this->assertStringContainsString('quantity: '.$featured->default_quantity, $html);
    }

    public function test_the_default_variant_card_is_checked_on_a_plain_visit(): void
    {
        $featured = $this->variant('ig-followers-standard');

        $html = $this->get('/product/ig買粉絲/')->assertOk()->getContent();

        // 第一張卡片必須帶 checked，⛔ 否則沒有任何卡片顯示為選中。
        $this->assertMatchesRegularExpression(
            '/value="'.$featured->id.'"[^>]*checked/',
            $html
        );
    }

    public function test_the_return_control_is_a_posted_form(): void
    {
        $this->post('/checkout/start', ['variant' => $this->variant()->id, 'quantity' => 1000]);

        // 返回意圖走 POST＋CSRF，⛔ 不是帶參數的 GET 連結。
        $this->get('/checkout')->assertOk()
            ->assertSee('action="'.route('checkout.return').'" method="post"', false)
            ->assertSee('返回修改');
    }

    // ---------------------------------------------------------- 無 JavaScript 送出

    public function test_the_service_form_has_no_duplicate_hidden_variant_field(): void
    {
        $html = $this->get('/product/ig買粉絲/')->assertOk()->getContent();

        // radio 已用 form="checkout-form" 關聯，⛔ 再放 hidden 會送出重複 key。
        $this->assertStringNotContainsString('type="hidden" name="variant"', $html);
        $this->assertSame(3, substr_count($html, 'name="variant"'));
    }

    public function test_a_non_default_variant_submits_correctly_without_javascript(): void
    {
        $taiwan = $this->variant('ig-followers-taiwan');

        // 模擬關閉 JS：瀏覽器只送出被選中的 radio，沒有 Alpine 同步任何值。
        $this->post('/checkout/start', ['variant' => $taiwan->id, 'quantity' => 300])
            ->assertRedirect(route('checkout'));

        $this->get('/checkout')->assertOk()->assertSee($taiwan->label);
    }

    // ---------------------------------------------------------- 驗證失敗後的狀態

    public function test_a_rejected_quantity_keeps_the_chosen_variant(): void
    {
        $taiwan = $this->variant('ig-followers-taiwan');

        $this->from('/product/ig買粉絲/')
            ->post('/checkout/start', ['variant' => $taiwan->id, 'quantity' => 7])
            ->assertSessionHasErrors('quantity');

        $html = $this->get('/product/ig買粉絲/')->assertOk()->getContent();

        // 數量被擋不該連帶把選好的服務項目也丟掉。
        $this->assertStringContainsString("variant: '{$taiwan->id}'", $html);
    }

    public function test_an_unknown_old_variant_is_not_restored(): void
    {
        $featured = $this->variant('ig-followers-standard');

        $this->from('/product/ig買粉絲/')
            ->post('/checkout/start', ['variant' => 999999, 'quantity' => 7])
            ->assertSessionHasErrors();

        $this->get('/product/ig買粉絲/')->assertOk()
            ->assertSee("variant: '{$featured->id}'", false);
    }

    public function test_a_draft_old_variant_is_not_restored(): void
    {
        $real = $this->variant('ig-followers-real');
        $real->update(['status' => 'draft']);
        $featured = $this->variant('ig-followers-standard');

        $this->from('/product/ig買粉絲/')
            ->post('/checkout/start', ['variant' => $real->id, 'quantity' => 7])
            ->assertSessionHasErrors();

        $this->get('/product/ig買粉絲/')->assertOk()
            ->assertSee("variant: '{$featured->id}'", false);
    }

    public function test_an_old_variant_from_another_service_is_not_restored(): void
    {
        $other = $this->variant('ig-post-likes-standard');
        $featured = $this->variant('ig-followers-standard');

        $this->from('/product/ig買粉絲/')
            ->post('/checkout/start', ['variant' => $other->id, 'quantity' => 7])
            ->assertSessionHasErrors('quantity');

        // ⛔ 別的服務的 old input 不得跨頁顯示。
        $this->get('/product/ig買粉絲/')->assertOk()
            ->assertSee("variant: '{$featured->id}'", false);
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

    public function test_every_checkout_response_is_noindex_even_when_indexing_is_open(): void
    {
        // 即使全站開放索引，整條結帳流程都不得可被索引。
        config()->set('app.env', 'production');
        config()->set('seo.allow_indexing', true);
        config()->set('seo.indexable_host', 'localhost');

        $header = 'noindex, nofollow';

        // 1. start 的 redirect
        $this->post('/checkout/start', ['variant' => $this->variant()->id, 'quantity' => 1000])
            ->assertHeader('X-Robots-Tag', $header);

        // 2. checkout 頁
        $this->get('/checkout')->assertOk()->assertHeader('X-Robots-Tag', $header);

        // 3. return 的 redirect
        $this->post('/checkout/return')->assertHeader('X-Robots-Tag', $header);

        // 4. mock success
        $this->post('/checkout/mock', $this->orderForm())
            ->assertOk()
            ->assertHeader('X-Robots-Tag', $header);
    }

    public function test_a_validation_failure_response_is_also_noindex(): void
    {
        config()->set('app.env', 'production');
        config()->set('seo.allow_indexing', true);
        config()->set('seo.indexable_host', 'localhost');

        $this->post('/checkout/start', ['variant' => $this->variant()->id, 'quantity' => 1000]);

        // 驗證失敗丟出的 redirect 不經過 controller 的 return，⛔ 仍必須帶 header。
        $this->post('/checkout/mock', $this->orderForm(['customer_email' => 'nope']))
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_both_checkout_pages_emit_a_noindex_meta_tag(): void
    {
        config()->set('app.env', 'production');
        config()->set('seo.allow_indexing', true);
        config()->set('seo.indexable_host', 'localhost');

        $meta = '<meta name="robots" content="noindex, nofollow">';

        $this->post('/checkout/start', ['variant' => $this->variant()->id, 'quantity' => 1000]);
        $this->assertStringContainsString($meta, $this->get('/checkout')->getContent());

        $this->assertStringContainsString(
            $meta,
            $this->post('/checkout/mock', $this->orderForm())->getContent()
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

    /** ⛔ mock 送完整合法資料:它型別提示 CheckoutRequest,form request 驗證會
     * **先於** environment guard 執行,空資料只會撞到驗證而根本測不到 guard。
     *
     * @return array<string, mixed>
     */
    private function completeCheckoutPayload(): array
    {
        return [
            'target' => 'https://instagram.com/fictional_account',
            'payment' => 'ecpay',
            'customer_email' => 'fictional@example.invalid',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'email',
        ];
    }

    /**
     * 兩個 checkout surface 都必須把 production 擋在外面。
     *
     * ⛔ M4C-STAGING-CHECKOUT-GUARD-A 起,兩者的允許清單不再相同:選購流程
     * 多了 `staging`(staging 存在的目的就是演練真實購買流程),但 mock 仍
     * 只限 local／testing。
     *
     * ⛔ 改成驗「行為」而不是 grep 原始碼字串:舊寫法會在 guard 被重構成
     * 常數或共用方法時失敗,卻對「production 其實被放行了」這種真正危險的
     * 改動毫無感覺——它測的是文字,不是邊界。
     */
    /**
     * M4C 又把這條線挪了一次:正式站必須能選購。
     *
     * 舊規則讓 `production` 的整條選購介面 404,而那等於沒有網站。選商品這一步
     * 與「錢可不可以移動」是兩件事,後者由 Owner 的後台開關決定。
     *
     * ⛔ 但 mock 仍然只限 local／testing:它會直接把訂單標成付款成功,在正式站
     * 上那就是「假裝收到錢」。⛔ 而且這一整段必須 0 筆訂單:沒有開啟任何付款
     * 通道時,選購走得到,付款走不到。
     */
    public function test_the_selection_surface_works_in_production_but_the_mock_does_not(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->post('/checkout/start', [
            'variant' => $this->variant()->id,
            'quantity' => 1000,
        ])->assertRedirect('/checkout');

        $this->get('/checkout')->assertOk();

        // ⛔ mock 在正式站上必須不存在。
        $this->post('/checkout/mock', $this->completeCheckoutPayload())->assertNotFound();

        // ⛔ 沒有任何通道開啟,所以一筆訂單都不該存在。
        $this->assertDatabaseCount('orders', 0);
    }

    /** ⛔ mock 會直接把訂單標成付款成功,因此 staging 也不得使用。 */
    public function test_the_mock_stays_local_only_even_on_staging(): void
    {
        $this->app->detectEnvironment(fn () => 'staging');
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->post('/checkout/mock', $this->completeCheckoutPayload())->assertNotFound();

        $this->assertDatabaseCount('orders', 0);
    }
}
