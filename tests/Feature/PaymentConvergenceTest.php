<?php

namespace Tests\Feature;

use App\Actions\Payments\ResolvePaymentAttempt;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\IntegrationSetting;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\ServiceVariant;
use App\Services\Payments\EcpayPaymentGateway;
use App\Services\Payments\LinePayGateway;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * A claimed attempt must always reach a state the customer can move on from.
 *
 * The claim in ResolvePaymentAttempt marks the attempt `pending` before the
 * provider is contacted, which is what stops two payments starting at once. But
 * a claim taken and never resolved is worse than no claim: the resolver
 * correctly refuses to start anything while an attempt is pending, so an
 * initiation that fails without writing back leaves the order unpayable
 * forever. The customer sees an error and cannot try again — not even with a
 * different provider.
 *
 * The distinction that decides the outcome is whether a payment session might
 * exist at the provider:
 *
 *   nothing was sent, or they explicitly refused → `failed`, retry allowed
 *   we sent and lost the answer                  → `reconciliation_required`
 *
 * ⛔ These tests take their attempts from the real resolver or the controller,
 * never from a factory: a hand-built row would not have been through the claim,
 * which is the whole thing being tested.
 */
class PaymentConvergenceTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    private const BASE = 'https://api-pay.line.me';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->runningAsLiveSite();
        $this->seed(CatalogSeeder::class);
    }

    private function order(int $amount = 590): Order
    {
        return Order::factory()->create(['total_amount' => $amount]);
    }

    private function resolve(): ResolvePaymentAttempt
    {
        return app(ResolvePaymentAttempt::class);
    }

    private function configureLine(bool $enabled = true): IntegrationSetting
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::LinePay, IntegrationEnvironment::Production)
            ->create(['identifier' => 'channel-0001']);

        $setting->credentials = ['ChannelSecret' => 'test-channel-secret-0001'];
        $setting->save();

        DB::table('integration_settings')->where('id', $setting->id)
            ->update(['is_enabled' => $enabled]);

        return $setting->fresh();
    }

    private function configureEcpay(bool $enabled = true): IntegrationSetting
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayPayment, IntegrationEnvironment::Production)
            ->create(['identifier' => '3000001']);

        $setting->credentials = ['HashKey' => 'test-hash-key-0001', 'HashIV' => 'test-hash-iv-0001'];
        $setting->save();

        DB::table('integration_settings')->where('id', $setting->id)
            ->update(['is_enabled' => $enabled]);

        return $setting->fresh();
    }

    // ==================================== 1. 沒送出＝可重試

    /**
     * ⭐ R2 的核心缺陷。
     *
     * ⛔ R2 的 `a_missing_credential_stays_retryable` 斷言 attempt 仍是
     * `Pending`——那個名字說「可重試」，但 resolver 正確地擋下任何 pending，
     * 所以那張訂單其實**永遠付不了款**。測試把卡死狀態寫成了通過預期。
     */
    public function test_a_missing_line_credential_converges_to_failed(): void
    {
        $order = $this->order();
        // ⛔ 沒有設定任何 LINE credential。
        $attempt = $this->resolve()->handle($order, 'line-pay');
        Http::fake();

        app(LinePayGateway::class)->initiate($attempt);

        // 根本沒送出，對方那邊什麼都沒發生。
        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Failed, $attempt->fresh()->status);
        $this->assertNotNull($attempt->fresh()->completed_at);
    }

    public function test_a_failed_line_initiation_lets_the_customer_try_again(): void
    {
        $order = $this->order();
        $first = $this->resolve()->handle($order, 'line-pay');
        Http::fake();

        app(LinePayGateway::class)->initiate($first);

        // ⛔ 這才是「可重試」的意思：resolver 願意再給一筆。
        $second = $this->resolve()->handle($order->fresh(), 'line-pay');

        $this->assertNotNull($second);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(PaymentStatus::Pending, $second->status);
        // 舊紀錄保留。
        $this->assertSame(PaymentStatus::Failed, $first->fresh()->status);
    }

    public function test_a_disabled_line_credential_converges_to_failed(): void
    {
        $this->configureLine(enabled: false);

        $order = $this->order();
        $attempt = $this->resolve()->handle($order, 'line-pay');
        Http::fake();

        app(LinePayGateway::class)->initiate($attempt);

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Failed, $attempt->fresh()->status);
    }

    public function test_a_missing_ecpay_credential_converges_to_failed(): void
    {
        $order = $this->order();
        $attempt = $this->resolve()->handle($order, 'ecpay');

        app(EcpayPaymentGateway::class)->initiate($attempt);

        // ⛔ 沒有 credential 就產不出 handoff form，這是確定沒有付款 session。
        $this->assertSame(PaymentStatus::Failed, $attempt->fresh()->status);
    }

    public function test_a_disabled_ecpay_credential_converges_to_failed(): void
    {
        $this->configureEcpay(enabled: false);

        $order = $this->order();
        $attempt = $this->resolve()->handle($order, 'ecpay');

        app(EcpayPaymentGateway::class)->initiate($attempt);

        $this->assertSame(PaymentStatus::Failed, $attempt->fresh()->status);
    }

    public function test_a_blank_ecpay_endpoint_converges_to_failed(): void
    {
        $this->configureEcpay();
        config()->set('integrations.endpoints.ecpay_payment.production', '');

        $order = $this->order();
        $attempt = $this->resolve()->handle($order, 'ecpay');

        app(EcpayPaymentGateway::class)->initiate($attempt);

        $this->assertSame(PaymentStatus::Failed, $attempt->fresh()->status);
    }

    public function test_a_failed_ecpay_initiation_lets_the_customer_switch_provider(): void
    {
        $order = $this->order();
        $first = $this->resolve()->handle($order, 'ecpay');

        app(EcpayPaymentGateway::class)->initiate($first);

        // 綠界設定不全，客人改用 LINE Pay——⛔ 必須能換。
        $second = $this->resolve()->handle($order->fresh(), 'line-pay');

        $this->assertNotNull($second);
        $this->assertSame('line-pay', $second->provider);
    }

    // ==================================== 2. 對方明確拒絕＝可重試

    public function test_a_deterministic_rejection_converges_to_failed(): void
    {
        $this->configureLine();

        $order = $this->order();
        $attempt = $this->resolve()->handle($order, 'line-pay');

        // 1124：金額資訊錯誤——對方明確拒絕，沒有建立付款 session。
        Http::fake([
            self::BASE.'/v4/payments/request' => Http::response([
                'returnCode' => '1124',
                'returnMessage' => 'Amount information error',
            ], 200),
        ]);

        app(LinePayGateway::class)->initiate($attempt);

        $this->assertSame(PaymentStatus::Failed, $attempt->fresh()->status);
        // ⛔ 恰好一次 HTTP。
        Http::assertSentCount(1);
        // 可以重試。
        $this->assertNotNull($this->resolve()->handle($order->fresh(), 'line-pay'));
    }

    // ==================================== 3. 送出後不明＝維持待對帳

    public static function uncertainOutcomeProvider(): array
    {
        return [
            'timeout' => ['timeout'],
            'http 500 with success body' => ['http500'],
            'malformed body' => ['malformed'],
            'unknown code' => ['unknown'],
        ];
    }

    #[DataProvider('uncertainOutcomeProvider')]
    public function test_an_uncertain_outcome_stays_in_reconciliation(string $kind): void
    {
        $this->configureLine();

        $order = $this->order();
        $attempt = $this->resolve()->handle($order, 'line-pay');

        Http::fake([
            self::BASE.'/v4/payments/request' => match ($kind) {
                'timeout' => fn () => throw new ConnectionException('timeout'),
                'http500' => Http::response(['returnCode' => '0000'], 500),
                'malformed' => Http::response('not json', 200),
                'unknown' => Http::response(['returnCode' => '9999'], 200),
            },
        ]);

        app(LinePayGateway::class)->initiate($attempt);

        // ⛔ 對方可能已經建立交易：不得記為失敗，也不得再開新的付款。
        $this->assertSame(PaymentStatus::ReconciliationRequired, $attempt->fresh()->status);
        $this->assertNull($this->resolve()->handle($order->fresh(), 'line-pay'));
    }

    // ==================================== 4. order 狀態必須跟著 attempt

    public function test_a_successful_handoff_leaves_the_order_pending(): void
    {
        $this->configureEcpay();

        $order = $this->order();
        $attempt = $this->resolve()->handle($order, 'ecpay');

        $result = app(EcpayPaymentGateway::class)->initiate($attempt);

        $this->assertTrue($result->isFormPost());
        // ⛔ 不得出現 attempt=pending 而 order=initiated 的不一致。
        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
    }

    public function test_a_failed_initiation_marks_the_order_payment_failed(): void
    {
        $order = $this->order();
        $attempt = $this->resolve()->handle($order, 'ecpay');

        app(EcpayPaymentGateway::class)->initiate($attempt);

        $this->assertSame(PaymentStatus::Failed, $order->fresh()->payment_status);
        // 訂單本身保留，客人還可以重新付款。
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
    }

    // ==================================== 5. 走完整 controller 的實況

    private function startCheckout(string $payment = 'ecpay'): array
    {
        $variant = ServiceVariant::where('sku', 'ig-followers-standard')->firstOrFail();

        $this->post('/checkout/start', [
            'variant' => $variant->id,
            'quantity' => $variant->default_quantity,
        ]);

        return [
            'target' => 'example_account',
            'payment' => $payment,
            'customer_email' => 'buyer@example.com',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'email',
        ];
    }

    /**
     * 通道是開著的、credential 齊全,但端點設定被改壞——initiation 中途失敗。
     *
     * ⛔ M4C 之後「完全沒有開通道」不再走到這裡:那種情況 controller 在建立
     * 任何訂單之前就拒絕了(見 test_a_disabled_channel_creates_no_order)。
     * 這個測試要驗的是另一件事,而且它仍然重要:訂單已經建立、attempt 已經
     * claim 之後失敗時,訂單不得被鎖死。
     *
     * ⛔ 留在 pending 的 attempt 會被 resolver 正確地擋下,於是那張訂單再也
     * 付不了款——連換一家 provider 都不行。
     */
    public function test_the_controller_leaves_a_retryable_order_after_a_failed_start(): void
    {
        $this->configureEcpay();
        // ⛔ 端點不符白名單:adapter 會在送出任何東西之前放棄。
        config()->set('integrations.endpoints.ecpay_payment.production', '');

        $this->post('/payments/start', $this->startCheckout())->assertRedirect();

        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(PaymentStatus::Failed, $order->payment_status);
        $this->assertSame(PaymentStatus::Failed, $order->paymentAttempts()->latest('id')->value('status'));

        // ⛔ 關鍵：resolver 願意再給一筆，訂單沒有被鎖死。
        $this->assertNotNull($this->resolve()->handle($order, 'line-pay'));
    }

    /**
     * ⛔ 通道全關時,連訂單都不該建立。
     *
     * 先建單再回錯誤,會在資料庫留下一張永遠不會被付款的訂單,而後台看起來
     * 像有人下了單。正確的行為是根本不開始。
     */
    public function test_a_disabled_channel_creates_no_order(): void
    {
        $this->post('/payments/start', $this->startCheckout())->assertRedirect('/checkout');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, PaymentAttempt::count());
    }

    // ==================================== 6. guard 關閉時不得取得 claim

    public function test_a_disabled_sandbox_never_claims_an_attempt(): void
    {
        $this->configureEcpay();
        // ⛔ M4C:關閉付款＝Owner 在後台停用那一列,不是改已 deprecated 的 env 旗標。
        DB::table('integration_settings')->update(['is_enabled' => false]);

        $this->post('/payments/start', $this->startCheckout())->assertRedirect();

        // ⛔ 開關關閉時連訂單都不該建立，更不該有 claim。
        $this->assertSame(0, Order::count());
        $this->assertSame(0, PaymentAttempt::count());
    }
}
