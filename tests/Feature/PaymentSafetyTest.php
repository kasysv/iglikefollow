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
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->seed(CatalogSeeder::class);
    }

    private function registry(): PaymentGatewayRegistry
    {
        return app(PaymentGatewayRegistry::class);
    }

    private function enableSandbox(): void
    {
        config()->set('integrations.payments.sandbox_enabled', true);
    }

    private function configureEcpay(): void
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayPayment, IntegrationEnvironment::Sandbox)
            ->create(['identifier' => '3000001']);

        $setting->credentials = ['HashKey' => 'k', 'HashIV' => 'v'];
        $setting->save();

        DB::table('integration_settings')->where('id', $setting->id)->update(['is_enabled' => true]);
    }

    // ============================================ 1. 預設關閉

    public function test_sandbox_payments_are_off_by_default(): void
    {
        // ⛔ 填了 credential 也不等於開始送出請求。
        $this->configureEcpay();

        $this->assertFalse($this->registry()->sandboxEnabled());
        $this->assertNull($this->registry()->for('ecpay'));
    }

    public function test_an_unconfigured_provider_fails_closed(): void
    {
        $this->enableSandbox();

        // 開關開了，但沒有 credential。
        $gateway = $this->registry()->for('ecpay');
        $this->assertNotNull($gateway);

        $attempt = PaymentAttempt::factory()->create([
            'order_id' => Order::factory()->create()->id,
            'provider' => 'ecpay',
        ]);

        // ⛔ 誠實失敗，不假裝付款開始了。
        $this->assertTrue($gateway->initiate($attempt)->isFailed());
    }

    public function test_a_disabled_credential_fails_closed(): void
    {
        $this->enableSandbox();

        // 有 credential 但沒有啟用。
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayPayment, IntegrationEnvironment::Sandbox)
            ->create(['identifier' => '3000001']);
        $setting->credentials = ['HashKey' => 'k', 'HashIV' => 'v'];
        $setting->save();

        $attempt = PaymentAttempt::factory()->create([
            'order_id' => Order::factory()->create()->id,
            'provider' => 'ecpay',
        ]);

        $this->assertTrue($this->registry()->for('ecpay')->initiate($attempt)->isFailed());
    }

    public function test_an_unknown_provider_has_no_adapter(): void
    {
        $this->enableSandbox();

        $this->assertNull($this->registry()->for('some-other-provider'));
    }

    public function test_a_disabled_registry_returns_nothing_rather_than_a_stand_in(): void
    {
        $this->configureEcpay();

        // ⛔ 關閉時回 null，⛔ 不回一個「會成功」的替身：
        // 靜靜假裝付款成功，比明確拒絕危險得多。
        foreach (['ecpay', 'line-pay'] as $provider) {
            $this->assertNull($this->registry()->for($provider));
        }
    }

    public function test_the_registry_only_knows_the_two_real_adapters(): void
    {
        $this->enableSandbox();

        $this->assertInstanceOf(
            EcpayPaymentGateway::class,
            $this->registry()->for('ecpay')
        );
        $this->assertInstanceOf(
            LinePayGateway::class,
            $this->registry()->for('line-pay')
        );
    }

    // ============================================ 2. production 永遠拒絕

    public function test_production_is_refused_even_when_enabled(): void
    {
        $this->enableSandbox();
        $this->app->detectEnvironment(fn () => 'production');

        // ⛔ 這一輪只做 sandbox：改一個 config 值不該就開始收真錢。
        $this->assertNull($this->registry()->for('ecpay'));
        $this->assertNull($this->registry()->for('line-pay'));
    }

    public function test_no_production_endpoint_is_configured(): void
    {
        foreach (config('integrations.endpoints') as $provider => $environments) {
            $this->assertSame('', $environments['production'] ?? '', "{$provider} 不得有 production 端點");
        }
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
        $this->enableSandbox();
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
        $this->enableSandbox();
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
        $this->enableSandbox();
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
