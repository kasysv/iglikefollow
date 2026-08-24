<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\ServiceVariant;
use App\Services\Payments\SandboxGuard;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * M4C-STAGING-CHECKOUT-GUARD-A:staging 必須跑得動兩頁式選購。
 *
 * Owner 在 staging 依正常流程按下購買,`POST /checkout/start` 回 404——
 * 因為三個 selection surface 都寫死只允許 local／testing。
 *
 * ⛔ 本檔案要同時證明兩件相反的事:
 *   1. staging 現在真的走得完「選商品 → /checkout → 返回修改」;
 *   2. 放寬 selection 完全沒有放寬「付錢」。production 仍全關,
 *      mock 仍只限 local／testing,sandbox 關閉時 staging 安全拒絕
 *      且不建單、不外呼。
 *
 * ⛔ 全程 `Http::preventStrayRequests()`:任何外部呼叫都會讓測試失敗。
 */
class StagingCheckoutGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);

        // ⛔ 選購與結帳都不該打任何外部服務。
        Http::preventStrayRequests();
    }

    private function variant(string $sku = 'ig-followers-standard'): ServiceVariant
    {
        return ServiceVariant::query()->where('sku', $sku)->firstOrFail();
    }

    /**
     * 切換 APP_ENV。
     *
     * ⛔ 用 `$app->detectEnvironment()` 而不是只改 config:`app()->environment()`
     * 讀的是 container 內解析過的值,只改 config 不會真的改變它,測試就會
     * 在「其實還是 testing」的情況下假裝通過。
     */
    private function runningAs(string $env): void
    {
        $this->app->detectEnvironment(fn () => $env);

        $this->assertSame($env, $this->app->environment(), '環境切換未生效');

        /*
         * 一離開 `testing`,CSRF middleware 就會真的生效,POST 會得到 419。
         * ⛔ 這裡只停掉 CSRF 一個 middleware,不是 `withoutMiddleware()` 全關:
         * 全關會連 environment guard 與 NeverIndex 一起關掉,那樣測到的就不是
         * 這輪要驗的東西了。CSRF 本身另有既有測試涵蓋。
         */
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    /** 完成一次合法選購,回傳該 variant。 */
    private function select(?ServiceVariant $variant = null, int $quantity = 1000): ServiceVariant
    {
        $variant ??= $this->variant();

        $this->post('/checkout/start', [
            'variant' => $variant->id,
            'quantity' => $quantity,
        ])->assertRedirect('/checkout');

        return $variant;
    }

    // ================================================== 1. staging 走得完

    /** ⛔ 這是 Owner 回報的那一個 404;必須是 302 到乾淨的 /checkout。 */
    public function test_staging_accepts_a_product_selection(): void
    {
        $this->runningAs('staging');

        $variant = $this->variant();

        $response = $this->post('/checkout/start', [
            'variant' => $variant->id,
            'quantity' => 1000,
        ]);

        $response->assertRedirect('/checkout');
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        // ⛔ 商品與數量不得出現在 query string。
        $this->assertStringNotContainsString('?', (string) $response->headers->get('Location'));

        // 選購不是下單。
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_staging_serves_the_checkout_form_with_noindex(): void
    {
        $this->runningAs('staging');
        $this->select();

        $response = $this->get('/checkout');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->assertSee('name="robots"', false);
        $response->assertSee('noindex', false);

        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_staging_can_go_back_to_the_product_page(): void
    {
        $this->runningAs('staging');
        $variant = $this->select();

        $response = $this->post('/checkout/return');

        $response->assertRedirect($variant->service->primaryUrl().'#checkout');
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    // ================================================== 2. 表單不得指向 mock

    /**
     * ⛔ staging 的結帳表單絕不能指向 /checkout/mock。
     *
     * mock 會直接把訂單標成付款成功;在 staging 指過去等於「假裝收到錢」。
     */
    public function test_the_staging_form_never_targets_the_mock(): void
    {
        $this->runningAs('staging');
        $this->select();

        $html = $this->get('/checkout')->assertOk()->getContent();

        $this->assertStringNotContainsString('/checkout/mock', $html);
        $this->assertStringContainsString('/payments/start', $html);
    }

    /** local 的既有 mock 開發流程必須保留(sandbox 關閉時)。 */
    public function test_local_still_uses_the_mock_when_sandbox_is_off(): void
    {
        $this->runningAs('local');
        config()->set('integrations.payments.sandbox_enabled', false);

        $this->select();

        $html = $this->get('/checkout')->assertOk()->getContent();

        $this->assertStringContainsString('/checkout/mock', $html);
    }

    /** sandbox 開啟時,不論哪個環境都送往真正的付款流程。 */
    public function test_the_form_targets_payments_when_sandbox_is_enabled(): void
    {
        config()->set('integrations.payments.sandbox_enabled', true);

        foreach (['local', 'staging'] as $env) {
            $this->runningAs($env);
            $this->select();

            $html = $this->get('/checkout')->assertOk()->getContent();

            $this->assertStringContainsString('/payments/start', $html, $env);
            $this->assertStringNotContainsString('/checkout/mock', $html, $env);
        }
    }

    // ================================================== 3. sandbox 關閉時安全拒絕

    /**
     * ⛔ staging＋sandbox 關閉:必須安全拒絕。
     *
     * 判準有四:回結帳頁而非 404／500、顯示付款不可用、⛔ 0 筆訂單與
     * 0 筆付款嘗試、⛔ 0 次外部呼叫。任何一項不成立都代表這條路徑
     * 在 staging 會做出它不該做的事。
     */
    public function test_staging_refuses_payment_safely_when_sandbox_is_off(): void
    {
        $this->runningAs('staging');
        config()->set('integrations.payments.sandbox_enabled', false);

        $this->select();

        $response = $this->post('/payments/start', [
            'target' => 'https://instagram.com/fictional_account',
            'payment' => 'ecpay',
            'customer_email' => 'fictional@example.invalid',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'email',
        ]);

        // ⛔ 不是 404、不是 500:安全地回到結帳頁並說明原因。
        $response->assertRedirect('/checkout');
        $response->assertSessionHasErrors('payment');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        Http::assertNothingSent();
    }

    // ================================================== 4. mock 仍只限 local／testing

    /** ⛔ staging 直接 POST /checkout/mock 必須 404,且不留下任何痕跡。 */
    public function test_staging_cannot_reach_the_mock_directly(): void
    {
        $this->runningAs('staging');
        $this->select();

        $this->post('/checkout/mock', [
            'target' => 'https://instagram.com/fictional_account',
            'payment' => 'ecpay',
            'customer_email' => 'fictional@example.invalid',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'email',
        ])->assertNotFound();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        Http::assertNothingSent();
    }

    /**
     * ⛔ 送「完整合法」資料而不是空陣列。
     *
     * `MockCheckoutController::store()` 型別提示 `CheckoutRequest`,所以
     * form request 驗證會**先於** environment guard 執行:空資料只會得到
     * 302 驗證錯誤,根本走不到那道 guard。用空資料測會通過,但證明的是
     * 「驗證擋下來了」,不是「production 進不去」。
     */
    public function test_production_cannot_reach_the_mock_either(): void
    {
        $this->runningAs('production');

        $this->post('/checkout/mock', [
            'target' => 'https://instagram.com/fictional_account',
            'payment' => 'ecpay',
            'customer_email' => 'fictional@example.invalid',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'email',
        ])->assertNotFound();

        $this->assertDatabaseCount('orders', 0);
    }

    // ================================================== 5. production 仍全關

    /**
     * ⛔ 本輪的核心安全不變式:放寬 staging 沒有放寬 production。
     *
     * 三個 selection surface 在 production 全部維持 404。
     */
    public function test_production_still_refuses_every_selection_surface(): void
    {
        $this->runningAs('production');

        $variant = $this->variant();

        $this->post('/checkout/start', [
            'variant' => $variant->id,
            'quantity' => 1000,
        ])->assertNotFound();

        $this->get('/checkout')->assertNotFound();
        $this->post('/checkout/return')->assertNotFound();

        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    /** ⛔ production 連 sandbox flag 開著也不得放行(SandboxGuard 硬性拒絕)。 */
    public function test_production_stays_closed_even_if_the_sandbox_flag_is_on(): void
    {
        $this->runningAs('production');
        config()->set('integrations.payments.sandbox_enabled', true);

        $this->get('/checkout')->assertNotFound();

        $this->assertFalse(SandboxGuard::enabled());
    }

    // ================================================== 6. 未授權能力仍關閉

    /** ⛔ 本輪不得順手開啟任何 flag;此測試釘住 default-off。 */
    public function test_no_capability_flag_was_opened_by_this_change(): void
    {
        $this->assertFalse((bool) config('integrations.payments.sandbox_enabled'));
        $this->assertFalse((bool) config('fulfillment.dispatch_enabled'));
        $this->assertFalse((bool) config('fulfillment.status_polling_enabled'));
        $this->assertSame('disabled', config('fulfillment.driver'));
    }

    /** ⛔ 沒有任何訂單／付款嘗試是本輪程式碼自己造出來的。 */
    public function test_the_selection_flow_creates_nothing(): void
    {
        $this->runningAs('staging');

        $this->select();
        $this->get('/checkout')->assertOk();
        $this->post('/checkout/return');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, PaymentAttempt::query()->count());
        Http::assertNothingSent();
    }
}
