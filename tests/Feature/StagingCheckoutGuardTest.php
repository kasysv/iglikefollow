<?php

namespace Tests\Feature;

use App\Enums\IntegrationProvider;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\ServiceVariant;
use App\Services\Payments\PaymentGatewayRegistry;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * M4C-STAGING-CHECKOUT-GUARD-A:staging 必須跑得動兩頁式選購。
 *
 * Owner 在 staging 依正常流程按下購買,`POST /checkout/start` 回 404——
 * 因為三個 selection surface 都寫死只允許 local／testing。
 *
 * ⛔ 本檔案要同時證明兩件相反的事:
 *   1. staging(M4C 之後連 production)真的走得完
 *      「選商品 → /checkout → 返回修改」;
 *   2. 放寬 selection 完全沒有放寬「付錢」。mock 仍只限 local／testing,
 *      Owner 通道關閉時安全拒絕且不建單、不外呼。
 *
 * ⛔ 全程 `Http::preventStrayRequests()`:任何外部呼叫都會讓測試失敗。
 */
class StagingCheckoutGuardTest extends TestCase
{
    use ConfiguresLiveIntegrations;
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

    /**
     * local 的既有 mock 開發流程必須保留,而且表單要送得出去。
     *
     * ⛔ 本機永遠沒有真實付款方式可用(環境邊界),所以 mock 是唯一能走完流程
     * 的路徑;少了它,本機就沒辦法測整條建單邏輯。
     *
     * ⛔ radio 也必須存在:mock 的 POST 一樣要求 payment 欄位,只把表單指向
     * mock 卻藏起 radio,送出時會卡在驗證,本機流程根本走不完——這一輪我
     * 第一版就犯了這個錯,靠這個測試釘住。
     */
    public function test_local_still_uses_the_mock(): void
    {
        $this->runningAs('local');
        $this->select();

        $html = $this->get('/checkout')->assertOk()->getContent();

        $this->assertStringContainsString('/checkout/mock', $html);
        $this->assertStringContainsString('name="payment"', $html);
        $this->assertStringContainsString('value="line-pay"', $html);
        $this->assertStringContainsString('value="ecpay"', $html);
    }

    /**
     * Owner 開啟通道後,staging／production 的表單一律送往真正的付款流程。
     *
     * ⛔ local 不在此列,而且是刻意的:本機的環境邊界讓任何通道都不可用,
     * 所以本機永遠走 mock——那正是上一個測試釘住的行為。
     */
    public function test_the_form_targets_payments_once_a_channel_is_enabled(): void
    {
        $this->enableChannel(IntegrationProvider::EcpayPayment, '3000001');

        foreach (['staging', 'production'] as $env) {
            $this->runningAs($env);
            $this->select();

            $html = $this->get('/checkout')->assertOk()->getContent();

            $this->assertStringContainsString('/payments/start', $html, $env);
            $this->assertStringNotContainsString('/checkout/mock', $html, $env);
            // ⛔ 只開了綠界,就只該出現綠界。
            $this->assertStringContainsString('value="ecpay"', $html, $env);
            $this->assertStringNotContainsString('value="line-pay"', $html, $env);
        }
    }

    /**
     * ⛔ 只開一個通道,不得連帶把另一個打開。
     *
     * 共用一個布林值的舊設計會讓「開了其中一個」變成「兩個都開了」,而另一個
     * 沒有 credential——客人選了它,按下去只會得到失敗。
     */
    public function test_enabling_one_provider_does_not_enable_the_other(): void
    {
        $this->runningAs('staging');
        $this->enableChannel(IntegrationProvider::LinePay, 'channel-0001');
        $this->select();

        $html = $this->get('/checkout')->assertOk()->getContent();

        $this->assertStringContainsString('value="line-pay"', $html);
        $this->assertStringNotContainsString('value="ecpay"', $html);

        $registry = app(PaymentGatewayRegistry::class);
        $this->assertSame(['line-pay'], $registry->availableProviders());
    }

    // ================================================== 3. Owner 通道關閉時安全拒絕

    /**
     * ⛔ Owner 通道關閉時:必須安全拒絕。
     *
     * 判準有四:回結帳頁而非 404／500、顯示付款不可用、⛔ 0 筆訂單與
     * 0 筆付款嘗試、⛔ 0 次外部呼叫。任何一項不成立都代表這條路徑會做出
     * 它不該做的事。
     *
     * ⛔ 尤其是「0 筆訂單」:先建單再回錯誤,會在資料庫留下一張永遠不會被付款
     * 的訂單,而後台看起來像有人下了單。
     */
    public function test_staging_refuses_payment_safely_when_the_channel_is_off(): void
    {
        $this->runningAs('staging');

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

    // ================================================== 5. production 選購開放,付款仍由開關決定

    /**
     * M4C 把這條線又挪了一次:production 的選購介面必須開放。
     *
     * ⛔ 上一輪的不變式是「放寬 staging 沒有放寬 production」;Owner 於
     * 2026-08-24 明確要求網站直接按正式營運設計,而一個 404 的選購流程就是
     * 沒有網站。
     *
     * ⛔ 真正的安全不變式改成下面這一條,而且它更接近實際風險:選購介面開著,
     * 但沒有任何 Owner 通道啟用時,⛔ 0 筆訂單、0 筆付款嘗試、0 次外部呼叫。
     */
    public function test_production_opens_selection_but_creates_nothing_while_channels_are_off(): void
    {
        $this->runningAs('production');

        $variant = $this->variant();

        $this->post('/checkout/start', [
            'variant' => $variant->id,
            'quantity' => 1000,
        ])->assertRedirect('/checkout');

        $this->get('/checkout')->assertOk();

        // ⛔ 沒有任何通道啟用,所以什麼都不該發生。
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        Http::assertNothingSent();
    }

    /**
     * ⛔ 付款方式全關時,結帳頁必須明說,而且不給一個送得出去的按鈕。
     *
     * 這取代了舊的「production 一律 404」:客人看得到商品與金額,但看到的是
     * 「暫未開放」,⛔ 不是一個按下去才失敗的付款按鈕,更不是先建一張訂單。
     */
    public function test_production_says_payment_is_unavailable_instead_of_offering_a_dead_button(): void
    {
        $this->runningAs('production');
        $this->select();

        $html = $this->get('/checkout')->assertOk()->getContent();

        $this->assertStringContainsString('目前付款方式暫未開放', $html);
        // ⛔ 沒有可提交的付款 radio。
        $this->assertStringNotContainsString('name="payment"', $html);
        // ⛔ 正式站不得指向 mock。
        $this->assertStringNotContainsString('/checkout/mock', $html);
    }

    /**
     * ⛔ 已 deprecated 的 sandbox 旗標不得再影響任何判斷。
     *
     * Owner 之前點綠界付款得到「付款方式目前無法使用」,根因就是這個預設
     * false 的旗標。把它設成 true 也不該讓通道變成可用——營運事實只有
     * Owner 的後台開關一個來源。
     */
    public function test_the_deprecated_sandbox_flag_changes_nothing_in_either_direction(): void
    {
        $this->runningAs('production');

        config()->set('integrations.payments.sandbox_enabled', true);
        $this->assertFalse(app(PaymentGatewayRegistry::class)->availableToCustomer('ecpay'));

        config()->set('integrations.payments.sandbox_enabled', false);
        $this->enableChannel(IntegrationProvider::EcpayPayment, '3000001');
        $this->assertTrue(app(PaymentGatewayRegistry::class)->availableToCustomer('ecpay'));
    }

    // ================================================== 6. 未授權能力仍關閉

    /**
     * ⛔ 本輪不得順手開啟任何 flag;此測試釘住 default-off。
     *
     * ⛔ 付款與發票的 sandbox 旗標已 deprecated,不再列在這裡:它們的值已經
     * 不影響任何行為,而釘住一個沒有作用的值,只會讓人以為它還是一道防線。
     * 真正的「付款預設關閉」由 `integration_settings` 沒有啟用列來保證,
     * 上面的通道測試已經涵蓋。
     */
    public function test_no_capability_flag_was_opened_by_this_change(): void
    {
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
