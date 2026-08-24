<?php

namespace Tests\Feature;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPaid;
use App\Models\IntegrationSetting;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\PaymentAttempt;
use App\Services\Payments\EcpayCheckMac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * The ECPay callback: the only thing that may mark an ECPay order paid.
 *
 * Everything here is about refusing to believe a claim. A POST to this URL is
 * free for anyone to make, so the tests push forged merchants, forged amounts,
 * forged signatures and replays at it and check that not one row moves.
 *
 * ⛔ The credentials below are invented for this file.
 */
class EcpayCallbackTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    private const HASH_KEY = 'test-hash-key-0001';

    private const HASH_IV = 'test-hash-iv-0001';

    private const MERCHANT = '3000001';

    protected function setUp(): void
    {
        parent::setUp();

        // ⛔ 本輪 Claude 的執行不得有任何外部呼叫。
        Http::preventStrayRequests();
        $this->runningAsLiveSite();

        // R2：sandbox 付款預設關閉，測試必須明確開啟。

        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayPayment, IntegrationEnvironment::Production)
            ->create(['identifier' => self::MERCHANT]);

        $setting->credentials = ['HashKey' => self::HASH_KEY, 'HashIV' => self::HASH_IV];
        $setting->save();

        DB::table('integration_settings')->where('id', $setting->id)->update(['is_enabled' => true]);
    }

    private function attempt(int $amount = 590): PaymentAttempt
    {
        $order = Order::factory()->create(['total_amount' => $amount]);

        return PaymentAttempt::factory()->create([
            'order_id' => $order->id,
            'provider' => 'ecpay',
            'amount' => $amount,
            'status' => PaymentStatus::Pending,
        ]);
    }

    /**
     * A callback payload, signed the way ECPay would sign it.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(PaymentAttempt $attempt, array $overrides = []): array
    {
        $payload = array_merge([
            'MerchantID' => self::MERCHANT,
            'MerchantTradeNo' => $attempt->reference,
            'RtnCode' => '1',
            'RtnMsg' => 'Succeeded',
            'TradeNo' => 'ECPAY-TXN-0001',
            'TradeAmt' => (string) (int) $attempt->amount,
            'PaymentDate' => '2026/08/17 10:05:00',
            'PaymentType' => 'Credit_CreditCard',
            'TradeDate' => '2026/08/17 10:00:00',
        ], $overrides);

        $payload['CheckMacValue'] = EcpayCheckMac::generate($payload, self::HASH_KEY, self::HASH_IV);

        return $payload;
    }

    private function notify(array $payload)
    {
        return $this->postJson('/payments/ecpay/callback', $payload);
    }

    // ============================================ 1. 合法通知

    public function test_a_valid_callback_marks_the_order_paid(): void
    {
        $attempt = $this->attempt();

        $response = $this->notify($this->payload($attempt));

        // 綠界要求成功時回純文字 1|OK。
        $response->assertOk()->assertSee('1|OK');

        $this->assertSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
        $this->assertSame(OrderStatus::Paid, $attempt->order->fresh()->order_status);
        $this->assertSame('ECPAY-TXN-0001', $attempt->fresh()->provider_reference);
    }

    public function test_a_valid_callback_dispatches_order_paid_once(): void
    {
        Event::fake([OrderPaid::class]);

        $attempt = $this->attempt();
        $this->notify($this->payload($attempt));

        Event::assertDispatched(OrderPaid::class, 1);
    }

    // ============================================ 2. 偽造一律 0 寫入

    public function test_a_forged_merchant_id_changes_nothing(): void
    {
        $attempt = $this->attempt();

        $payload = $this->payload($attempt, ['MerchantID' => '9999999']);

        $this->notify($payload)->assertOk()->assertDontSee('1|OK');

        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $attempt->order->fresh()->order_status);
    }

    public function test_an_unknown_trade_number_changes_nothing(): void
    {
        $attempt = $this->attempt();

        $this->notify($this->payload($attempt, ['MerchantTradeNo' => 'DOES-NOT-EXIST']))
            ->assertOk()->assertDontSee('1|OK');

        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->status);
    }

    public function test_a_mismatched_amount_never_marks_paid(): void
    {
        $attempt = $this->attempt(590);

        // ⛔ 對方說收了 1 元，我們的訂單是 590：絕不可採信。
        $this->notify($this->payload($attempt, ['TradeAmt' => '1']))
            ->assertOk()->assertDontSee('1|OK');

        $fresh = $attempt->fresh();

        $this->assertNotSame(PaymentStatus::Succeeded, $fresh->status);
        $this->assertSame(OrderStatus::PendingPayment, $attempt->order->fresh()->order_status);
        // 金額不符屬於「不明」，需要人看。
        $this->assertSame(PaymentStatus::ReconciliationRequired, $fresh->status);
    }

    public function test_a_forged_signature_changes_nothing(): void
    {
        $attempt = $this->attempt();

        $payload = $this->payload($attempt);
        $payload['CheckMacValue'] = str_repeat('A', 64);

        $this->notify($payload)->assertOk()->assertDontSee('1|OK');

        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->status);
    }

    public function test_a_missing_signature_changes_nothing(): void
    {
        $attempt = $this->attempt();

        $payload = $this->payload($attempt);
        unset($payload['CheckMacValue']);

        $this->notify($payload)->assertOk()->assertDontSee('1|OK');

        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->status);
    }

    public function test_tampering_with_any_field_invalidates_the_signature(): void
    {
        $attempt = $this->attempt();

        // 先正確簽章，再偷改一個欄位——簽章就不再吻合。
        $payload = $this->payload($attempt);
        $payload['TradeAmt'] = '1';

        $this->notify($payload)->assertOk()->assertDontSee('1|OK');

        $this->assertNotSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
    }

    // ============================================ 3. SimulatePaid 絕不算付款

    public function test_simulate_paid_never_marks_the_order_paid(): void
    {
        Event::fake([OrderPaid::class]);

        $attempt = $this->attempt();

        // ⛔ 綠界明說這只測回呼，沒有任何金流發生。
        $response = $this->notify($this->payload($attempt, ['SimulatePaid' => '1']));

        // 仍要回 1|OK，否則綠界會一直重送。
        $response->assertOk()->assertSee('1|OK');

        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $attempt->order->fresh()->order_status);
        Event::assertNotDispatched(OrderPaid::class);
    }

    public function test_simulate_paid_creates_no_order_paid_event(): void
    {
        $attempt = $this->attempt();

        $this->notify($this->payload($attempt, ['SimulatePaid' => '1']));

        $this->assertSame(
            0,
            $attempt->order->events()->where('type', OrderEvent::TYPE_ORDER_PAID)->count()
        );
    }

    // ============================================ 4. 重複與亂序

    public function test_a_repeated_callback_is_idempotent(): void
    {
        Event::fake([OrderPaid::class]);

        $attempt = $this->attempt();
        $payload = $this->payload($attempt);

        $this->notify($payload)->assertSee('1|OK');
        $this->notify($payload)->assertSee('1|OK');
        $this->notify($payload)->assertSee('1|OK');

        // ⛔ 重複的合法通知只能成立一次。
        Event::assertDispatched(OrderPaid::class, 1);
        $this->assertSame(1, $attempt->order->events()->where('type', OrderEvent::TYPE_ORDER_PAID)->count());
    }

    public function test_a_late_failure_cannot_undo_a_success(): void
    {
        $attempt = $this->attempt();

        $this->notify($this->payload($attempt));
        $this->assertSame(OrderStatus::Paid, $attempt->order->fresh()->order_status);

        // 之後才到的失敗通知。
        $this->notify($this->payload($attempt, ['RtnCode' => '10100058', 'TradeNo' => 'ECPAY-TXN-0001']));

        // ⛔ 已付款的訂單不得被降級。
        $this->assertSame(OrderStatus::Paid, $attempt->order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
    }

    public function test_an_unknown_return_code_goes_to_reconciliation(): void
    {
        $attempt = $this->attempt();

        // ⛔ 不認識的代碼不等於確定失敗：可能已經扣款了。
        $this->notify($this->payload($attempt, ['RtnCode' => '999999']));

        $this->assertSame(PaymentStatus::ReconciliationRequired, $attempt->fresh()->status);
    }

    public function test_a_known_decline_is_recorded_as_failed(): void
    {
        $attempt = $this->attempt();

        $this->notify($this->payload($attempt, ['RtnCode' => '10100058']));

        $this->assertSame(PaymentStatus::Failed, $attempt->fresh()->status);
        // 訂單保留，客人可以重新付款。
        $this->assertSame(OrderStatus::PendingPayment, $attempt->order->fresh()->order_status);
    }

    // ============================================ 5. 落盤不得有 raw payload

    public function test_the_provider_message_is_never_stored(): void
    {
        $attempt = $this->attempt();

        $this->notify($this->payload($attempt, [
            'RtnCode' => '10100058',
            // ⛔ 對方的自由文字常回音請求內容。
            'RtnMsg' => 'MerchantID=3000001 buyer@example.com HashKey=LEAKME',
        ]));

        $raw = json_encode([
            DB::table('payment_attempts')->get(),
            DB::table('orders')->get(),
            DB::table('order_events')->get(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['LEAKME', 'buyer@example.com', self::HASH_KEY, self::HASH_IV] as $marker) {
            $this->assertStringNotContainsString($marker, $raw, "落盤出現敏感字串：{$marker}");
        }
    }

    public function test_the_callback_is_noindex(): void
    {
        $attempt = $this->attempt();

        $this->notify($this->payload($attempt))
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
