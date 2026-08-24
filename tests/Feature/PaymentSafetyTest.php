<?php

namespace Tests\Feature;

use App\Actions\Orders\MarkPaymentUncertain;
use App\Actions\Orders\RecordPaymentResult;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\OrderStatus;
use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Models\IntegrationSetting;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\ServiceVariant;
use App\Services\Integrations\ProviderEndpoints;
use App\Services\Payments\EcpayPaymentGateway;
use App\Services\Payments\LinePayGateway;
use App\Services\Payments\PaymentGatewayRegistry;
use Database\Seeders\CatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * What happens when payments are switched off, misconfigured, or forged.
 *
 * The failure everyone remembers is a payment that breaks. The failure that
 * actually costs money is a payment that appears to work while nothing was
 * charged, so most of these tests check that we refuse plainly rather than
 * degrade quietly.
 */
class PaymentSafetyTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->runningAsLiveSite();
        $this->seed(CatalogSeeder::class);
    }

    private function registry(): PaymentGatewayRegistry
    {
        return app(PaymentGatewayRegistry::class);
    }

    /** Owner 已開啟綠界付款,credential 齊全。 */
    private function configureEcpay(): void
    {
        $this->enableChannel(IntegrationProvider::EcpayPayment, '3000001');
    }

    // ============================================ 1. 預設關閉

    /**
     * ⛔ 填了 credential 不等於開始收款:還要 Owner 明確按下啟用。
     *
     * M4C 之後這一條的依據換了(從 env 旗標換成後台開關),但要證明的事完全
     * 沒變:把金鑰存進資料庫這個動作本身,不得讓任何一筆交易變成可能。
     */
    public function test_a_configured_but_disabled_channel_is_unavailable(): void
    {
        $this->configureChannelWithoutEnabling(IntegrationProvider::EcpayPayment, '3000001');

        $this->assertFalse($this->registry()->availableToCustomer('ecpay'));
        $this->assertNull($this->registry()->for('ecpay'));
    }

    /** ⛔ 完全沒有設定時同樣不可用,而且不是丟例外。 */
    public function test_a_channel_with_no_row_at_all_is_unavailable(): void
    {
        $this->assertFalse($this->registry()->availableToCustomer('ecpay'));
        $this->assertNull($this->registry()->for('ecpay'));
    }

    /**
     * 半套 credential:Owner 的開關開著,但金鑰不全。
     *
     * ⛔ 必須誠實失敗,不假裝付款開始了——半套的金鑰只會在對方系統得到一個
     * 看不懂的錯誤,而客人已經離開結帳頁了。
     */
    public function test_a_half_configured_provider_fails_closed(): void
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayPayment, IntegrationEnvironment::Production)
            ->create(['identifier' => '3000001']);

        // ⛔ 只有一半的金鑰;繞過 model 直接開啟,模擬偽造或資料損壞。
        $setting->credentials = ['HashKey' => 'k'];
        $setting->save();
        DB::table('integration_settings')->where('id', $setting->id)->update(['is_enabled' => true]);

        $this->assertNull($this->registry()->for('ecpay'));

        $attempt = PaymentAttempt::factory()->create([
            'order_id' => Order::factory()->create()->id,
            'provider' => 'ecpay',
        ]);

        // ⛔ 直接從 container 取出 adapter 也一樣拒絕。
        $this->assertTrue(app(EcpayPaymentGateway::class)->initiate($attempt)->isFailed());
    }

    /**
     * 有 credential 但 Owner 沒有啟用。
     *
     * ⛔ registry 必須回 null(而不是回一個會失敗的 adapter):通道關著的時候
     * 連 adapter 都不該存在,否則「拿得到 adapter」就成了一個看起來可以付款
     * 的訊號。⛔ 直接從 container 取出 adapter 也一樣拒絕。
     */
    public function test_a_disabled_credential_fails_closed(): void
    {
        $this->configureChannelWithoutEnabling(IntegrationProvider::EcpayPayment, '3000001');

        $attempt = PaymentAttempt::factory()->create([
            'order_id' => Order::factory()->create()->id,
            'provider' => 'ecpay',
        ]);

        $this->assertNull($this->registry()->for('ecpay'));
        $this->assertTrue(app(EcpayPaymentGateway::class)->initiate($attempt)->isFailed());
    }

    public function test_an_unknown_provider_has_no_adapter(): void
    {
        $this->enableAllChannels();

        $this->assertNull($this->registry()->for('some-other-provider'));
    }

    public function test_a_disabled_registry_returns_nothing_rather_than_a_stand_in(): void
    {
        // credential 齊全但 Owner 沒有啟用——這才是「關閉」的狀態。
        $this->configureChannelWithoutEnabling(IntegrationProvider::EcpayPayment, '3000001');
        $this->configureChannelWithoutEnabling(IntegrationProvider::LinePay, 'channel-0001');

        // ⛔ 關閉時回 null，⛔ 不回一個「會成功」的替身：
        // 靜靜假裝付款成功，比明確拒絕危險得多。
        foreach (['ecpay', 'line-pay'] as $provider) {
            $this->assertNull($this->registry()->for($provider));
        }
    }

    public function test_the_registry_only_knows_the_two_real_adapters(): void
    {
        $this->enableAllChannels();

        $this->assertInstanceOf(
            EcpayPaymentGateway::class,
            $this->registry()->for('ecpay')
        );
        $this->assertInstanceOf(
            LinePayGateway::class,
            $this->registry()->for('line-pay')
        );
    }

    // ============================================ 2. 環境邊界

    /**
     * ⛔ 本機／測試環境永遠不可用,即使 Owner 的通道是開著的。
     *
     * M4C 反轉了原本的「production 一律拒絕」:那讓正式站永遠收不到款。
     * 剩下的環境邊界是這一條,而它是技術邊界,不是 Owner 的營運開關——
     * 少了它,任何開發機器只要有一份正式 credential 就會開始真的收款。
     */
    public function test_a_local_machine_is_refused_even_when_the_owner_enabled_it(): void
    {
        $this->enableAllChannels();
        $this->runningAsLiveSite('local');

        $this->assertNull($this->registry()->for('ecpay'));
        $this->assertNull($this->registry()->for('line-pay'));
        $this->assertFalse($this->registry()->outboundAllowed());
    }

    /** ⛔ production 是 Owner 營運的環境,開關開著就必須可用。 */
    public function test_production_is_usable_once_the_owner_enables_it(): void
    {
        $this->enableAllChannels();
        $this->runningAsLiveSite('production');

        $this->assertInstanceOf(EcpayPaymentGateway::class, $this->registry()->for('ecpay'));
        $this->assertInstanceOf(LinePayGateway::class, $this->registry()->for('line-pay'));
    }

    /**
     * ⛔ 交易端點必須恰好是官方正式網址,而且固定在版本控制中。
     *
     * 這一條取代了舊的「production 端點必須為空」:端點不再是開關,它是
     * SSRF 邊界。可以由後台輸入的網址,等於這台伺服器會帶著我們的金鑰去連
     * 任何有人填進去的主機。
     */
    public function test_the_transaction_endpoints_are_exactly_the_official_ones(): void
    {
        $endpoints = config('integrations.endpoints');

        $this->assertSame(ProviderEndpoints::ECPAY_PAYMENT, $endpoints['ecpay_payment']['production']);
        $this->assertSame(ProviderEndpoints::LINE_PAY_API, $endpoints['line_pay']['production']);
        $this->assertSame(ProviderEndpoints::ECPAY_INVOICE_ISSUE, $endpoints['ecpay_invoice']['production']);
        $this->assertSame(ProviderEndpoints::ECPAY_INVOICE_QUERY, $endpoints['ecpay_invoice_query']['production']);

        // ⛔ 自動派單仍未獲批准,production 端點必須維持空字串。
        $this->assertSame('', $endpoints['themostpanel']['production']);
    }

    // ============================================ 3. 啟動付款的授權

    private function startCheckout(): array
    {
        $variant = ServiceVariant::where('sku', 'ig-followers-standard')->firstOrFail();

        $this->post('/checkout/start', [
            'variant' => $variant->id,
            'quantity' => $variant->default_quantity,
        ]);

        return [
            'target' => 'example_account',
            'payment' => 'ecpay',
            'customer_email' => 'buyer@example.com',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'email',
        ];
    }

    public function test_starting_a_payment_while_disabled_creates_no_order(): void
    {
        $form = $this->startCheckout();

        $this->post('/payments/start', $form)->assertRedirect();

        // ⛔ 付款無法開始時，不留下半途而廢的訂單。
        $this->assertSame(0, Order::count());
    }

    public function test_the_form_cannot_choose_its_own_price(): void
    {
        $this->enableAllChannels();
        $this->configureEcpay();

        $form = $this->startCheckout();
        // 試圖從表單指定金額。
        $form['amount'] = 1;
        $form['total_amount'] = 1;
        $form['price'] = 1;

        $this->post('/payments/start', $form);

        $order = Order::latest('id')->first();

        // 590 來自伺服器端重算。
        $this->assertNotNull($order);
        $this->assertSame(590, (int) $order->total_amount);
    }

    public function test_the_form_cannot_set_the_order_status(): void
    {
        $this->enableAllChannels();
        $this->configureEcpay();

        $form = $this->startCheckout();
        $form['order_status'] = 'paid';
        $form['payment_status'] = 'succeeded';

        $this->post('/payments/start', $form);

        $order = Order::latest('id')->firstOrFail();

        // ⛔ 只有經驗證的 server-to-server 結果可以標記已付款。
        $this->assertSame(OrderStatus::PendingPayment, $order->order_status);
    }

    public function test_starting_a_payment_never_marks_it_paid(): void
    {
        $this->enableAllChannels();
        $this->configureEcpay();

        $this->post('/payments/start', $this->startCheckout());

        $order = Order::latest('id')->firstOrFail();

        // 開始付款與完成付款是兩件事。
        $this->assertSame(OrderStatus::PendingPayment, $order->order_status);
        $this->assertNull($order->paid_at);
    }

    // ============================================ 4. 資料庫層防線

    public function test_the_database_refuses_a_zero_amount_attempt(): void
    {
        $order = Order::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('payment_attempts')->insert([
            'order_id' => $order->id, 'provider' => 'ecpay', 'reference' => 'X1',
            'status' => 'initiated', 'amount' => 0, 'currency' => 'TWD',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_database_refuses_a_non_twd_attempt(): void
    {
        $order = Order::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('payment_attempts')->insert([
            'order_id' => $order->id, 'provider' => 'ecpay', 'reference' => 'X2',
            'status' => 'initiated', 'amount' => 590, 'currency' => 'USD',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_database_refuses_an_unknown_provider(): void
    {
        $order = Order::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('payment_attempts')->insert([
            'order_id' => $order->id, 'provider' => 'mystery-provider', 'reference' => 'X3',
            'status' => 'initiated', 'amount' => 590, 'currency' => 'TWD',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_database_refuses_an_unknown_status(): void
    {
        $order = Order::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('payment_attempts')->insert([
            'order_id' => $order->id, 'provider' => 'ecpay', 'reference' => 'X4',
            'status' => 'definitely-paid', 'amount' => 590, 'currency' => 'TWD',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_provider_reference_is_unique_per_provider(): void
    {
        $order = Order::factory()->create();

        PaymentAttempt::factory()->create([
            'order_id' => $order->id, 'provider' => 'ecpay',
            'provider_reference' => 'SHARED-TXN',
        ]);

        // ⛔ 兩筆嘗試共用同一個交易編號，就無從分辨哪一次付款屬於哪張訂單。
        $this->expectException(UniqueConstraintViolationException::class);

        PaymentAttempt::factory()->create([
            'order_id' => Order::factory()->create()->id, 'provider' => 'ecpay',
            'provider_reference' => 'SHARED-TXN',
        ]);
    }

    // ============================================ 5. reconciliation 語意

    public function test_reconciliation_is_not_a_terminal_result(): void
    {
        // ⛔ 「不明」不是結果：不得經由 RecordPaymentResult 寫入。
        $this->assertFalse(PaymentStatus::ReconciliationRequired->isTerminal());
        $this->assertTrue(PaymentStatus::ReconciliationRequired->needsReconciliation());
    }

    public function test_record_payment_result_refuses_reconciliation(): void
    {
        $attempt = PaymentAttempt::factory()->create([
            'order_id' => Order::factory()->create()->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(RecordPaymentResult::class)
            ->handle($attempt, PaymentStatus::ReconciliationRequired);
    }

    public function test_an_uncertain_attempt_keeps_no_completion_time(): void
    {
        $attempt = PaymentAttempt::factory()->create([
            'order_id' => Order::factory()->create()->id,
            'status' => PaymentStatus::Pending,
        ]);

        app(MarkPaymentUncertain::class)
            ->handle($attempt, PaymentFailureReason::Timeout);

        // ⛔ 這筆嘗試並沒有「完成」，只是無法確認。
        $this->assertNull($attempt->fresh()->completed_at);
        $this->assertSame(PaymentStatus::ReconciliationRequired, $attempt->fresh()->status);
    }

    public function test_a_succeeded_attempt_cannot_be_downgraded_to_uncertain(): void
    {
        $attempt = PaymentAttempt::factory()->create([
            'order_id' => Order::factory()->create()->id,
            'status' => PaymentStatus::Pending,
        ]);

        app(RecordPaymentResult::class)
            ->handle($attempt, PaymentStatus::Succeeded, 'TXN-1');

        app(MarkPaymentUncertain::class)
            ->handle($attempt->fresh(), PaymentFailureReason::Timeout);

        // ⛔ 已成功的付款不得因為後續一個逾時就變成待對帳。
        $this->assertSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
    }

    // ============================================ 6. 沒有原始 payload 欄位

    public function test_payment_attempts_have_no_raw_payload_columns(): void
    {
        $columns = Schema::getColumnListing('payment_attempts');

        foreach (['raw_request', 'raw_response', 'response_body', 'payload', 'signature', 'nonce'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "payment_attempts 出現可存原始內容的欄位：{$forbidden}");
        }
    }
}
