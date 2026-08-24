<?php

namespace Tests\Feature;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\IntegrationSetting;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Payments\EcpayCheckMac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * Nothing may be written on the word of an unverified request.
 *
 * The attack these cover is not "can a forger mark an order paid" — that was
 * already refused. It is quieter: a forger who knows an attempt reference sends
 * a wrong amount with a junk signature, the amount check runs first, and the
 * attempt is parked for reconciliation. The customer's real payment then
 * arrives and cannot complete, because the attempt is no longer open. Denial of
 * service by writing to a record we had no business trusting.
 *
 * So the rule is stricter than "reject bad callbacks": ⛔ no attempt, order or
 * event may change until the signature has verified.
 */
class PaymentAuthenticityTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    private const HASH_KEY = 'test-hash-key-0001';

    private const HASH_IV = 'test-hash-iv-0001';

    private const MERCHANT = '3000001';

    protected function setUp(): void
    {
        parent::setUp();

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
     * A full snapshot of everything a callback could touch.
     *
     * ⛔ Comparing whole rows, not just status: a forged request that only
     * writes a failure_code has still written.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'attempts' => DB::table('payment_attempts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'orders' => DB::table('orders')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'events' => DB::table('order_events')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    /** @param array<string, string> $overrides */
    private function signedPayload(PaymentAttempt $attempt, array $overrides = []): array
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

    // ==================================== R1-1：驗簽前 0 寫入

    /**
     * ⭐ 這是 R1 的核心缺陷。
     *
     * 知道 attempt reference 的人送出錯誤金額＋垃圾簽章，舊版會先比金額、
     * 把 attempt 卡成 reconciliation_required；客人真正的付款隨後就完成不了，
     * 因為那筆 attempt 已經不是 open。
     */
    public function test_a_forged_amount_with_a_junk_signature_writes_nothing(): void
    {
        $attempt = $this->attempt(590);
        $before = $this->snapshot();

        $payload = $this->signedPayload($attempt, ['TradeAmt' => '1']);
        $payload['CheckMacValue'] = str_repeat('F', 64);   // 垃圾簽章

        $this->notify($payload)->assertOk()->assertDontSee('1|OK');

        // ⛔ 三張表逐列完全不變。
        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_forged_amount_with_no_signature_writes_nothing(): void
    {
        $attempt = $this->attempt(590);
        $before = $this->snapshot();

        $payload = $this->signedPayload($attempt, ['TradeAmt' => '1']);
        unset($payload['CheckMacValue']);

        $this->notify($payload)->assertOk()->assertDontSee('1|OK');

        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_forged_callback_leaves_the_attempt_payable(): void
    {
        $attempt = $this->attempt(590);

        // 攻擊者先來一發。
        $forged = $this->signedPayload($attempt, ['TradeAmt' => '1']);
        $forged['CheckMacValue'] = str_repeat('F', 64);
        $this->notify($forged);

        // 客人真正的付款結果隨後到達，⛔ 必須仍然能完成。
        $this->notify($this->signedPayload($attempt))->assertSee('1|OK');

        $this->assertSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
        $this->assertSame(OrderStatus::Paid, $attempt->order->fresh()->order_status);
    }

    public function test_tampering_after_signing_writes_nothing_at_all(): void
    {
        $attempt = $this->attempt();
        $before = $this->snapshot();

        // 正確簽章後再偷改欄位。
        $payload = $this->signedPayload($attempt);
        $payload['TradeAmt'] = '1';

        $this->notify($payload)->assertOk()->assertDontSee('1|OK');

        // ⛔ 不只是「不是 succeeded」：任何欄位都不得被寫。
        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_signed_amount_mismatch_is_recorded(): void
    {
        // 簽章正確但金額不符：這是真的來自綠界，值得記錄下來給人看。
        $attempt = $this->attempt(590);

        $this->notify($this->signedPayload($attempt, ['TradeAmt' => '1']))
            ->assertOk()->assertDontSee('1|OK');

        $this->assertSame(PaymentStatus::ReconciliationRequired, $attempt->fresh()->status);
    }

    public function test_an_unknown_reference_writes_nothing(): void
    {
        $attempt = $this->attempt();
        $before = $this->snapshot();

        $this->notify($this->signedPayload($attempt, ['MerchantTradeNo' => 'NOPE']))
            ->assertOk()->assertDontSee('1|OK');

        $this->assertSame($before, $this->snapshot());
    }
}
