<?php

namespace Tests\Feature;

use App\Actions\Orders\MarkPaymentUncertain;
use App\Actions\Orders\RecordPaymentResult;
use App\Actions\Payments\ResolvePaymentAttempt;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\OrderStatus;
use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Models\IntegrationSetting;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\PaymentAttempt;
use App\Services\Payments\EcpayCheckMac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Switching payment method, retrying, and getting out of reconciliation.
 *
 * A customer whose card is declined will try again, often with a different
 * provider. Two things must hold: the second payment is recorded against the
 * provider that actually handled it, and an attempt whose outcome we never
 * learned does not silently become an invitation to pay twice.
 */
class PaymentRetryTest extends TestCase
{
    use RefreshDatabase;

    private const HASH_KEY = 'test-hash-key-0001';

    private const HASH_IV = 'test-hash-iv-0001';

    private const MERCHANT = '3000001';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // R2：sandbox 付款預設關閉，測試必須明確開啟。
        config()->set('integrations.payments.sandbox_enabled', true);

        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayPayment, IntegrationEnvironment::Sandbox)
            ->create(['identifier' => self::MERCHANT]);

        $setting->credentials = ['HashKey' => self::HASH_KEY, 'HashIV' => self::HASH_IV];
        $setting->save();

        DB::table('integration_settings')->where('id', $setting->id)->update(['is_enabled' => true]);
    }

    private function resolve(): ResolvePaymentAttempt
    {
        return app(ResolvePaymentAttempt::class);
    }

    private function order(int $amount = 590): Order
    {
        return Order::factory()->create(['total_amount' => $amount]);
    }

    // ==================================== R1-4：gateway 與 attempt provider 一致

    public function test_each_provider_gets_its_own_attempt(): void
    {
        $order = $this->order();

        $ecpay = $this->resolve()->handle($order, 'ecpay');
        // 綠界那筆先失敗，客人改用 LINE Pay。
        app(RecordPaymentResult::class)->handle($ecpay, PaymentStatus::Failed);

        $line = $this->resolve()->handle($order->fresh(), 'line-pay');

        // ⛔ 不得把 LINE 的付款記在綠界那筆上：交易編號會屬於一個系統、
        // 紀錄屬於另一個，之後任何 callback 都對不回來。
        $this->assertNotSame($ecpay->id, $line->id);
        $this->assertSame('ecpay', $ecpay->fresh()->provider);
        $this->assertSame('line-pay', $line->provider);
    }

    public function test_a_failed_attempt_is_kept_when_a_new_one_starts(): void
    {
        $order = $this->order();

        $first = $this->resolve()->handle($order, 'ecpay');
        app(RecordPaymentResult::class)->handle($first, PaymentStatus::Failed);

        $second = $this->resolve()->handle($order->fresh(), 'ecpay');

        // 舊紀錄保留，⛔ 每次付款各自留下自己的結果。
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(PaymentStatus::Failed, $first->fresh()->status);
        $this->assertSame(2, $order->paymentAttempts()->count());
    }

    public function test_a_canceled_attempt_allows_a_retry(): void
    {
        $order = $this->order();

        $first = $this->resolve()->handle($order, 'line-pay');
        app(RecordPaymentResult::class)->handle($first, PaymentStatus::Canceled);

        $second = $this->resolve()->handle($order->fresh(), 'line-pay');

        $this->assertNotSame($first->id, $second->id);
    }

    /**
     * ⭐ R2 更正：claim 之後就不能再開始第二筆。
     *
     * ⛔ R1 的舊測試斷言「第二次會拿到不同的 attempt」——那描述的正是雙重
     * 付款風險，卻被寫成通過的預期。resolver 現在會在 order row lock 內把
     * attempt 推進 pending 作為 claim，第二次呼叫必須什麼都拿不到。
     */
    public function test_a_second_start_is_refused_while_one_is_in_flight(): void
    {
        $order = $this->order();

        $first = $this->resolve()->handle($order, 'ecpay');

        // 第一次就已經 claim 成 pending。
        $this->assertSame(PaymentStatus::Pending, $first->status);

        // ⛔ 同時兩次送出時，第二次不得取得任何 attempt。
        $this->assertNull($this->resolve()->handle($order->fresh(), 'ecpay'));
        $this->assertSame(1, $order->paymentAttempts()->count());
    }

    public function test_a_second_start_with_another_provider_is_also_refused(): void
    {
        $order = $this->order();

        $this->resolve()->handle($order, 'ecpay');

        // ⛔ 換 provider 也不能繞過：綠界那邊已經有一個活著的付款。
        $this->assertNull($this->resolve()->handle($order->fresh(), 'line-pay'));
        $this->assertSame(1, $order->paymentAttempts()->count());
    }

    public function test_two_sequential_submits_yield_one_claim(): void
    {
        $order = $this->order();

        // 依序模擬兩次併發送出。⛔ 單行程無法真的並行；resolver 的保證來自
        // order row lock，這裡驗證「後到的看到 pending 就止步」。
        $claims = array_filter([
            $this->resolve()->handle($order, 'ecpay'),
            $this->resolve()->handle($order->fresh(), 'ecpay'),
            $this->resolve()->handle($order->fresh(), 'line-pay'),
        ]);

        $this->assertCount(1, $claims);
    }

    public function test_a_paid_order_cannot_start_another_payment(): void
    {
        $order = $this->order();
        $attempt = $this->resolve()->handle($order, 'ecpay');

        app(RecordPaymentResult::class)->handle($attempt, PaymentStatus::Succeeded, 'TXN-OK');

        // ⛔ 已付款的訂單不得再開新的付款嘗試。
        $this->assertNull($this->resolve()->handle($order->fresh(), 'line-pay'));
        $this->assertSame(OrderStatus::Paid, $order->fresh()->order_status);
    }

    public function test_an_uncertain_attempt_blocks_a_new_payment(): void
    {
        $order = $this->order();
        $attempt = $this->resolve()->handle($order, 'ecpay');
        $attempt->forceFill(['status' => PaymentStatus::Pending])->save();

        app(MarkPaymentUncertain::class)->handle($attempt->fresh(), PaymentFailureReason::Timeout);

        // ⛔ 錢可能已經扣了：再給一次付款機會等於邀請客人重複付款。
        $this->assertNull($this->resolve()->handle($order->fresh(), 'line-pay'));
    }

    // ==================================== R1-5：對帳狀態必須收斂得了

    private function signedCallback(PaymentAttempt $attempt, array $overrides = []): array
    {
        $payload = array_merge([
            'MerchantID' => self::MERCHANT,
            'MerchantTradeNo' => $attempt->reference,
            'RtnCode' => '1',
            'RtnMsg' => 'Succeeded',
            'TradeNo' => 'ECPAY-TXN-0001',
            'TradeAmt' => (string) (int) $attempt->amount,
        ], $overrides);

        $payload['CheckMacValue'] = EcpayCheckMac::generate($payload, self::HASH_KEY, self::HASH_IV);

        return $payload;
    }

    private function uncertainEcpayAttempt(): PaymentAttempt
    {
        $order = $this->order();

        $attempt = PaymentAttempt::factory()->create([
            'order_id' => $order->id,
            'provider' => 'ecpay',
            'amount' => 590,
            'status' => PaymentStatus::Pending,
        ]);

        // 先送一個「簽章正確但金額不符」的通知，把它推進待對帳。
        $this->postJson('/payments/ecpay/callback', $this->signedCallback($attempt, ['TradeAmt' => '1']));

        return $attempt->fresh();
    }

    public function test_an_uncertain_attempt_starts_out_stuck(): void
    {
        $attempt = $this->uncertainEcpayAttempt();

        $this->assertSame(PaymentStatus::ReconciliationRequired, $attempt->status);
        // ⛔ 待對帳不算 open：任何「還開著就再送一次」的流程都撿不走它。
        $this->assertFalse($attempt->status->isOpen());
    }

    /**
     * ⭐ 收斂路徑：後續一個「已驗簽」的成功通知必須能把它結清。
     *
     * 否則這筆嘗試會永遠卡著——客人付了錢，系統卻永遠說不知道。
     */
    public function test_a_later_verified_success_resolves_the_reconciliation(): void
    {
        $attempt = $this->uncertainEcpayAttempt();

        $this->postJson('/payments/ecpay/callback', $this->signedCallback($attempt))
            ->assertSee('1|OK');

        $this->assertSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
        $this->assertSame(OrderStatus::Paid, $attempt->order->fresh()->order_status);
    }

    public function test_a_forged_callback_cannot_resolve_the_reconciliation(): void
    {
        $attempt = $this->uncertainEcpayAttempt();

        $payload = $this->signedCallback($attempt);
        $payload['CheckMacValue'] = str_repeat('F', 64);

        $this->postJson('/payments/ecpay/callback', $payload)->assertDontSee('1|OK');

        // ⛔ 只有經驗證的結果可以收斂。
        $this->assertSame(PaymentStatus::ReconciliationRequired, $attempt->fresh()->status);
    }

    public function test_a_later_verified_failure_also_resolves_it(): void
    {
        $attempt = $this->uncertainEcpayAttempt();

        $this->postJson('/payments/ecpay/callback', $this->signedCallback($attempt, [
            'RtnCode' => '10100058',
        ]));

        $this->assertSame(PaymentStatus::Failed, $attempt->fresh()->status);
        // 訂單保留，客人可以重新付款。
        $this->assertSame(OrderStatus::PendingPayment, $attempt->order->fresh()->order_status);
    }

    public function test_a_late_failure_never_overwrites_a_success(): void
    {
        $attempt = $this->uncertainEcpayAttempt();

        $this->postJson('/payments/ecpay/callback', $this->signedCallback($attempt));
        $this->assertSame(PaymentStatus::Succeeded, $attempt->fresh()->status);

        $this->postJson('/payments/ecpay/callback', $this->signedCallback($attempt, [
            'RtnCode' => '10100058',
        ]));

        // ⛔ 成功永遠不可被降級。
        $this->assertSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
        $this->assertSame(OrderStatus::Paid, $attempt->order->fresh()->order_status);
    }

    public function test_resolving_twice_is_idempotent(): void
    {
        $attempt = $this->uncertainEcpayAttempt();

        $payload = $this->signedCallback($attempt);
        $this->postJson('/payments/ecpay/callback', $payload);
        $this->postJson('/payments/ecpay/callback', $payload);

        $this->assertSame(
            1,
            $attempt->order->events()->where('type', OrderEvent::TYPE_ORDER_PAID)->count()
        );
    }
}
