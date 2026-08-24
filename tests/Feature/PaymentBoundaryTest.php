<?php

namespace Tests\Feature;

use App\Actions\Payments\ResolvePaymentAttempt;
use App\DTO\LinePayResponse;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\IntegrationSetting;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Payments\EcpayCheckMac;
use App\Services\Payments\EcpayPaymentGateway;
use App\Services\Payments\LinePayGateway;
use App\Services\Payments\LinePaySignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * The boundary: what happens outside the ordinary controller path.
 *
 * A guard that only sits in the registry protects the front door of a building
 * with several. The ECPay callback is a public route that never passes through
 * it, and both gateways can be pulled straight out of the container. These
 * tests go in through those other doors on purpose.
 *
 * Also covers the difference between "we never sent the request" and "we sent
 * it and lost the answer" — the first is safe to retry, the second is how a
 * customer gets charged twice.
 */
class PaymentBoundaryTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    private const BASE = 'https://api-pay.line.me';

    private const HASH_KEY = 'test-hash-key-0001';

    private const HASH_IV = 'test-hash-iv-0001';

    private const MERCHANT = '3000001';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->runningAsLiveSite();

        $ecpay = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayPayment, IntegrationEnvironment::Production)
            ->create(['identifier' => self::MERCHANT]);
        $ecpay->credentials = ['HashKey' => self::HASH_KEY, 'HashIV' => self::HASH_IV];
        $ecpay->save();

        $line = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::LinePay, IntegrationEnvironment::Production)
            ->create(['identifier' => 'channel-0001']);
        $line->credentials = ['ChannelSecret' => 'test-channel-secret-0001'];
        $line->save();

        DB::table('integration_settings')->update(['is_enabled' => true]);
    }

    private function attempt(string $provider = 'ecpay', int $amount = 590): PaymentAttempt
    {
        $order = Order::factory()->create(['total_amount' => $amount]);

        return PaymentAttempt::factory()->create([
            'order_id' => $order->id,
            'provider' => $provider,
            'amount' => $amount,
            'status' => PaymentStatus::Pending,
        ]);
    }

    /** @param array<string, string> $overrides */
    private function signed(PaymentAttempt $attempt, array $overrides = []): array
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

    // ==================================== R2-3：邊界必須擋在每個入口

    public function test_the_ecpay_callback_is_dead_when_sandbox_is_off(): void
    {
        $attempt = $this->attempt();
        // ⛔ M4C:關閉付款＝Owner 在後台停用那一列,不是改已 deprecated 的 env 旗標。
        DB::table('integration_settings')->update(['is_enabled' => false]);

        $before = DB::table('payment_attempts')->get()->toJson();

        $this->postJson('/payments/ecpay/callback', $this->signed($attempt))
            ->assertOk()->assertDontSee('1|OK');

        // ⛔ 開關關閉時，公開 callback 必須 0 寫入。
        $this->assertSame($before, DB::table('payment_attempts')->get()->toJson());
        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->status);
    }

    /**
     * M4C 反轉了這一條:production 是 Owner 真正營運的環境,callback 必須活著。
     *
     * ⛔ 這不是把防線拆掉,是換掉依據。舊規則「production 一律拒絕」的結果是
     * 正式站永遠收不到款;現在拒絕的依據是 Owner 的後台開關,而下面兩個測試
     * 證明「開關關著」與「本機」兩種情況仍然一個位元組都不寫。
     */
    public function test_the_ecpay_callback_is_alive_in_production(): void
    {
        $attempt = $this->attempt();
        $this->runningAsLiveSite('production');

        $this->postJson('/payments/ecpay/callback', $this->signed($attempt))
            ->assertOk()->assertSee('1|OK');

        $this->assertSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
    }

    public function test_the_ecpay_callback_is_dead_on_a_local_machine(): void
    {
        $attempt = $this->attempt();

        // ⛔ 本機永遠不得參與真實金流，即使資料庫裡的通道是開著的。
        $this->runningAsLiveSite('local');

        $before = DB::table('payment_attempts')->get()->toJson();

        $this->postJson('/payments/ecpay/callback', $this->signed($attempt))
            ->assertOk()->assertDontSee('1|OK');

        $this->assertSame($before, DB::table('payment_attempts')->get()->toJson());
        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->status);
    }

    public function test_the_ecpay_adapter_refuses_when_resolved_directly(): void
    {
        $attempt = $this->attempt();
        // ⛔ M4C:關閉付款＝Owner 在後台停用那一列,不是改已 deprecated 的 env 旗標。
        DB::table('integration_settings')->update(['is_enabled' => false]);

        // ⛔ 繞過 registry 直接從 container 取出。
        $result = app(EcpayPaymentGateway::class)->initiate($attempt);

        $this->assertTrue($result->isFailed());
    }

    public function test_the_line_adapter_refuses_when_resolved_directly(): void
    {
        $attempt = $this->attempt('line-pay');
        // ⛔ M4C:關閉付款＝Owner 在後台停用那一列,不是改已 deprecated 的 env 旗標。
        DB::table('integration_settings')->update(['is_enabled' => false]);
        Http::fake();

        $result = app(LinePayGateway::class)->initiate($attempt);

        $this->assertTrue($result->isFailed());
        // ⛔ 一個 request 都不得送出。
        Http::assertNothingSent();
    }

    /**
     * ⛔ 本機／測試環境永遠不得送出真實付款請求。
     *
     * 這是 M4C 之後剩下的那一道環境邊界,而且它是技術邊界、不是營運開關:
     * 資料庫裡的通道開著也不行。少了它,任何在本機跑起來的開發環境只要有
     * 一份正式 credential 就會開始真的收款。
     */
    public function test_the_line_adapter_refuses_on_a_local_machine(): void
    {
        $attempt = $this->attempt('line-pay');
        $this->runningAsLiveSite('local');
        Http::fake();

        $this->assertTrue(app(LinePayGateway::class)->initiate($attempt)->isFailed());
        Http::assertNothingSent();
    }

    public function test_the_line_return_is_dead_when_sandbox_is_off(): void
    {
        $attempt = $this->attempt('line-pay');
        $attempt->forceFill(['provider_reference' => 'TXN-1'])->save();
        // ⛔ M4C:關閉付款＝Owner 在後台停用那一列,不是改已 deprecated 的 env 旗標。
        DB::table('integration_settings')->update(['is_enabled' => false]);
        Http::fake();

        $url = "/payments/linepay/{$attempt->order->reference}/confirm"
            ."?orderId={$attempt->reference}&transactionId=TXN-1";

        $this->get($url)->assertRedirect();

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->status);
    }

    public function test_the_line_cancel_is_dead_when_sandbox_is_off(): void
    {
        $attempt = $this->attempt('line-pay');
        $attempt->forceFill(['provider_reference' => 'TXN-1'])->save();
        // ⛔ M4C:關閉付款＝Owner 在後台停用那一列,不是改已 deprecated 的 env 旗標。
        DB::table('integration_settings')->update(['is_enabled' => false]);

        $url = "/payments/linepay/{$attempt->order->reference}/cancel"
            ."?orderId={$attempt->reference}&transactionId=TXN-1";

        $this->get($url)->assertRedirect();

        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->status);
    }

    // ==================================== R2-2：送出後結果不明不得可重送

    private function lineAttempt(): PaymentAttempt
    {
        $order = Order::factory()->create(['total_amount' => 590]);

        return PaymentAttempt::factory()->create([
            'order_id' => $order->id,
            'provider' => 'line-pay',
            'amount' => 590,
            'status' => PaymentStatus::Pending,
        ]);
    }

    public function test_a_request_timeout_goes_to_reconciliation(): void
    {
        $attempt = $this->lineAttempt();

        Http::fake([
            self::BASE.'/v4/payments/request' => fn () => throw new ConnectionException('timeout'),
        ]);

        app(LinePayGateway::class)->initiate($attempt);

        // ⛔ 送出去了卻不知道結果：對方可能已建立交易，不得留在可重送狀態。
        $this->assertSame(PaymentStatus::ReconciliationRequired, $attempt->fresh()->status);
    }

    public function test_a_malformed_request_response_goes_to_reconciliation(): void
    {
        $attempt = $this->lineAttempt();

        Http::fake([self::BASE.'/v4/payments/request' => Http::response('not json', 200)]);

        app(LinePayGateway::class)->initiate($attempt);

        $this->assertSame(PaymentStatus::ReconciliationRequired, $attempt->fresh()->status);
    }

    public function test_an_unknown_request_code_goes_to_reconciliation(): void
    {
        $attempt = $this->lineAttempt();

        Http::fake([
            self::BASE.'/v4/payments/request' => Http::response(['returnCode' => '9999'], 200),
        ]);

        app(LinePayGateway::class)->initiate($attempt);

        $this->assertSame(PaymentStatus::ReconciliationRequired, $attempt->fresh()->status);
    }

    public function test_a_success_without_a_transaction_id_goes_to_reconciliation(): void
    {
        $attempt = $this->lineAttempt();

        Http::fake([
            self::BASE.'/v4/payments/request' => Http::response([
                'returnCode' => '0000',
                'info' => ['paymentUrl' => ['web' => 'https://web-pay.line.me/x']],
            ], 200),
        ]);

        app(LinePayGateway::class)->initiate($attempt);

        // 對方可能真的建立了交易，只是我們沒拿到編號。
        $this->assertSame(PaymentStatus::ReconciliationRequired, $attempt->fresh()->status);
    }

    public function test_an_unsafe_redirect_goes_to_reconciliation(): void
    {
        $attempt = $this->lineAttempt();

        Http::fake([
            self::BASE.'/v4/payments/request' => Http::response([
                'returnCode' => '0000',
                'info' => [
                    'transactionId' => '123',
                    'paymentUrl' => ['web' => 'https://evil.example.com/collect'],
                ],
            ], 200),
        ]);

        app(LinePayGateway::class)->initiate($attempt);

        $this->assertSame(PaymentStatus::ReconciliationRequired, $attempt->fresh()->status);
    }

    public function test_a_reconciling_attempt_cannot_be_restarted(): void
    {
        $attempt = $this->lineAttempt();

        Http::fake([
            self::BASE.'/v4/payments/request' => fn () => throw new ConnectionException('timeout'),
        ]);

        app(LinePayGateway::class)->initiate($attempt);

        // ⛔ 再次啟動付款必須被拒絕，⛔ 而且不得再送出任何 HTTP。
        $this->assertNull(
            app(ResolvePaymentAttempt::class)
                ->handle($attempt->order->fresh(), 'line-pay')
        );
    }

    /**
     * ⛔ 非 200 的回應，body 寫什麼都不算數。
     */
    public function test_an_http_500_with_a_success_body_is_not_trusted(): void
    {
        $attempt = $this->lineAttempt();

        Http::fake([
            self::BASE.'/v4/payments/request' => Http::response([
                'returnCode' => '0000',
                'info' => [
                    'transactionId' => '123',
                    'paymentUrl' => ['web' => 'https://web-pay.line.me/x'],
                ],
            ], 500),
        ]);

        $result = app(LinePayGateway::class)->initiate($attempt);

        $this->assertTrue($result->isFailed());
        $this->assertSame(PaymentStatus::ReconciliationRequired, $attempt->fresh()->status);
    }

    public function test_a_confirm_http_500_with_a_success_body_never_marks_paid(): void
    {
        $attempt = $this->lineAttempt();
        $attempt->forceFill(['provider_reference' => 'TXN-1'])->save();

        Http::fake([
            self::BASE.'/v4/payments/*/confirm' => Http::response([
                'returnCode' => '0000',
                'info' => [
                    'orderId' => $attempt->reference,
                    'transactionId' => 'TXN-1',
                    'payInfo' => [['method' => 'BALANCE', 'amount' => 590]],
                ],
            ], 500),
        ]);

        $this->get("/payments/linepay/{$attempt->order->reference}/confirm"
            ."?orderId={$attempt->reference}&transactionId=TXN-1");

        $this->assertNotSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $attempt->order->fresh()->order_status);
    }

    /**
     * ⭐ R3 更正：這個測試的名字原本說「可重試」，斷言的卻是 `Pending`。
     *
     * ⛔ 那正是卡死狀態——resolver 會擋下任何 pending，所以那張訂單其實
     * 永遠付不了款。「可重試」的意思是收斂成 failed 之後真的能再開一筆。
     */
    public function test_a_missing_credential_converges_so_a_retry_is_possible(): void
    {
        $attempt = $this->lineAttempt();
        DB::table('integration_settings')->update(['is_enabled' => false]);
        Http::fake();

        app(LinePayGateway::class)->initiate($attempt);

        // ⛔ 根本沒送出去：對方那邊什麼都沒發生。
        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Failed, $attempt->fresh()->status);
        $this->assertNotNull($attempt->fresh()->completed_at);
    }

    // ==================================== R2-4：綠界 callback 完整性

    public function test_a_success_without_a_trade_number_goes_to_reconciliation(): void
    {
        $attempt = $this->attempt();

        $this->postJson('/payments/ecpay/callback', $this->signed($attempt, ['TradeNo' => '']));

        // ⛔ 沒有對方交易編號就無從對帳；「成功但不知道是哪一筆」不是成功。
        $this->assertSame(PaymentStatus::ReconciliationRequired, $attempt->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $attempt->order->fresh()->order_status);
    }

    public function test_simulate_paid_with_a_mismatched_amount_writes_nothing(): void
    {
        $attempt = $this->attempt(amount: 590);

        $before = [
            'attempts' => DB::table('payment_attempts')->get()->toJson(),
            'orders' => DB::table('orders')->get()->toJson(),
            'events' => DB::table('order_events')->get()->toJson(),
        ];

        // ⛔ 測試回呼的金額本來就不必對得上；不得因此把正常訂單推進待對帳。
        $this->postJson('/payments/ecpay/callback', $this->signed($attempt, [
            'SimulatePaid' => '1',
            'TradeAmt' => '1',
        ]))->assertSee('1|OK');

        $this->assertSame($before['attempts'], DB::table('payment_attempts')->get()->toJson());
        $this->assertSame($before['orders'], DB::table('orders')->get()->toJson());
        $this->assertSame($before['events'], DB::table('order_events')->get()->toJson());
    }

    // ==================================== R2-5：payInfo 形狀與簽章證據

    public static function unacceptablePayInfoProvider(): array
    {
        return [
            'integral float' => [[['method' => 'BALANCE', 'amount' => 590.0]]],
            'associative array' => [['first' => ['method' => 'BALANCE', 'amount' => 590]]],
            'string amount' => [[['method' => 'BALANCE', 'amount' => '590']]],
            'negative' => [[['method' => 'BALANCE', 'amount' => -590]]],
            'overflow' => [[
                ['method' => 'A', 'amount' => PHP_INT_MAX],
                ['method' => 'B', 'amount' => PHP_INT_MAX],
            ]],
        ];
    }

    /**
     * ⛔ 「形狀不對但湊得出數字」不能當成「數字正確」。
     */
    #[DataProvider('unacceptablePayInfoProvider')]
    public function test_unacceptable_pay_info_is_rejected(array $payInfo): void
    {
        $response = LinePayResponse::fromArray([
            'returnCode' => '0000',
            'info' => ['orderId' => 'X', 'transactionId' => 'Y', 'payInfo' => $payInfo],
        ]);

        $this->assertFalse($response->payInfoIsValid);
        $this->assertNull($response->payInfoTotal);
    }

    public function test_a_legitimate_split_totals_correctly(): void
    {
        $response = LinePayResponse::fromArray([
            'returnCode' => '0000',
            'info' => [
                'orderId' => 'X', 'transactionId' => 'Y',
                'payInfo' => [
                    ['method' => 'BALANCE', 'amount' => 500],
                    ['method' => 'POINT', 'amount' => 90],
                ],
            ],
        ]);

        $this->assertTrue($response->payInfoIsValid);
        $this->assertSame(590, $response->payInfoTotal);
    }

    /**
     * A fixed expected LINE Pay signature.
     *
     * ⛔ Every other signature test compares this implementation against
     * itself, which shows it is consistent but not that it is correct. The
     * secret below is an obviously fake string invented here; the digest was
     * computed once from the documented algorithm (channelSecret + URI + body +
     * nonce, HMAC-SHA256, base64) and pinned so a future change to the
     * construction order fails loudly.
     */
    public function test_the_line_signature_matches_a_pinned_vector(): void
    {
        $signature = LinePaySignature::sign(
            'public-fake-secret-for-tests',
            '/v4/payments/request',
            '{"amount":590,"currency":"TWD","orderId":"PAYTEST0000001"}',
            'fixed-nonce-0001',
        );

        $this->assertSame('9g1HPoKx4liRGxigiAve/D8XnUb9XkRJ2a+1b10wIhM=', $signature);
    }
}
