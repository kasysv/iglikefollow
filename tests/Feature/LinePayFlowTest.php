<?php

namespace Tests\Feature;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPaid;
use App\Models\IntegrationSetting;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Payments\LinePayGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * LINE Pay request and confirm, against faked HTTP.
 *
 * ⛔ Every response below is a fixture. Nothing in this file reaches the
 * network — `Http::preventStrayRequests()` turns an unfaked call into a test
 * failure rather than a real request — so none of it is evidence that the live
 * sandbox works. That needs credentials and a public HTTPS URL, and is
 * recorded as NOT VERIFIED.
 *
 * The point of the fixtures is the part that is ours: what we do with a reply
 * we cannot control.
 */
class LinePayFlowTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://sandbox-api-pay.line.me';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::LinePay, IntegrationEnvironment::Sandbox)
            ->create(['identifier' => 'channel-0001']);

        $setting->credentials = ['ChannelSecret' => 'test-channel-secret-0001'];
        $setting->save();

        DB::table('integration_settings')->where('id', $setting->id)->update(['is_enabled' => true]);
    }

    private function attempt(int $amount = 590): PaymentAttempt
    {
        $order = Order::factory()->create(['total_amount' => $amount]);

        return PaymentAttempt::factory()->create([
            'order_id' => $order->id,
            'provider' => 'line-pay',
            'amount' => $amount,
            'status' => PaymentStatus::Initiated,
        ]);
    }

    private function gateway(): LinePayGateway
    {
        return app(LinePayGateway::class);
    }

    /** @param array<string, mixed> $info */
    private function fakeRequestSuccess(array $info = []): void
    {
        Http::fake([
            self::BASE.'/v4/payments/request' => Http::response([
                'returnCode' => '0000',
                'returnMessage' => 'Success.',
                'info' => array_merge([
                    'transactionId' => '2026081700000001',
                    'paymentUrl' => ['web' => 'https://sandbox-web-pay.line.me/web/payment/wait?t=abc'],
                ], $info),
            ]),
        ]);
    }

    // ============================================ 1. request payment

    public function test_a_successful_request_stores_the_transaction_id(): void
    {
        $this->fakeRequestSuccess();
        $attempt = $this->attempt();

        $result = $this->gateway()->initiate($attempt);

        $this->assertTrue($result->isRedirect());
        $this->assertSame('2026081700000001', $attempt->fresh()->provider_reference);
        // 付款中，⛔ 不是已付款。
        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $attempt->order->fresh()->order_status);
    }

    public function test_the_request_sends_only_server_side_values(): void
    {
        $this->fakeRequestSuccess();
        $attempt = $this->attempt(590);

        $this->gateway()->initiate($attempt);

        Http::assertSent(function ($request) use ($attempt) {
            $body = $request->data();

            // ⛔ 金額與訂單編號來自伺服器端快照。
            return $body['amount'] === 590
                && $body['currency'] === 'TWD'
                && $body['orderId'] === $attempt->reference;
        });
    }

    public function test_the_request_carries_signature_headers(): void
    {
        $this->fakeRequestSuccess();
        $this->gateway()->initiate($this->attempt());

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-LINE-ChannelId')
                && $request->hasHeader('X-LINE-Authorization-Nonce')
                && $request->hasHeader('X-LINE-Authorization');
        });
    }

    public function test_the_request_never_sends_the_customer_target(): void
    {
        $this->fakeRequestSuccess();
        $attempt = $this->attempt();

        $this->gateway()->initiate($attempt);

        Http::assertSent(function ($request) {
            // ⛔ 商品名稱是固定安全字串，不帶客人的 IG 帳號或貼文網址。
            return ! str_contains(json_encode($request->data()), 'example_account');
        });
    }

    // ============================================ 2. redirect 白名單

    public function test_a_redirect_to_an_unexpected_host_is_refused(): void
    {
        $this->fakeRequestSuccess([
            'paymentUrl' => ['web' => 'https://evil.example.com/collect'],
        ]);

        $result = $this->gateway()->initiate($this->attempt());

        // ⛔ 這會變成 open redirect，而且正好出現在客人準備輸入卡號的時候。
        $this->assertTrue($result->isFailed());
    }

    public function test_a_non_https_redirect_is_refused(): void
    {
        $this->fakeRequestSuccess([
            'paymentUrl' => ['web' => 'http://sandbox-web-pay.line.me/web/payment'],
        ]);

        $this->assertTrue($this->gateway()->initiate($this->attempt())->isFailed());
    }

    // ============================================ 3. request 失敗與不明

    public function test_a_business_error_does_not_start_a_payment(): void
    {
        Http::fake([
            self::BASE.'/v4/payments/request' => Http::response([
                'returnCode' => '1106',
                'returnMessage' => 'Header info error',
            ]),
        ]);

        $attempt = $this->attempt();
        $result = $this->gateway()->initiate($attempt);

        $this->assertTrue($result->isFailed());
        // 尚未開始付款，狀態不變。
        $this->assertSame(PaymentStatus::Initiated, $attempt->fresh()->status);
    }

    public function test_malformed_json_is_treated_as_unreadable(): void
    {
        Http::fake([
            self::BASE.'/v4/payments/request' => Http::response('not json at all', 200),
        ]);

        $this->assertTrue($this->gateway()->initiate($this->attempt())->isFailed());
    }

    public function test_a_missing_transaction_id_is_refused(): void
    {
        $this->fakeRequestSuccess(['transactionId' => null]);

        // 沒有 transactionId 就無從 confirm，⛔ 不能把客人送出去。
        $this->assertTrue($this->gateway()->initiate($this->attempt())->isFailed());
    }

    // ============================================ 4. confirm 才是付款證明

    private function pendingAttempt(): PaymentAttempt
    {
        $this->fakeRequestSuccess();
        $attempt = $this->attempt();
        $this->gateway()->initiate($attempt);

        return $attempt->fresh();
    }

    private function fakeConfirm(array $json, int $status = 200): void
    {
        Http::fake([
            self::BASE.'/v4/payments/*/confirm' => Http::response($json, $status),
        ]);
    }

    public function test_a_confirmed_payment_marks_the_order_paid(): void
    {
        $attempt = $this->pendingAttempt();

        $this->fakeConfirm([
            'returnCode' => '0000',
            'info' => [
                'transactionId' => '2026081700000001',
                'orderId' => $attempt->reference,
                'amount' => 590,
                'currency' => 'TWD',
            ],
        ]);

        $this->get("/payments/linepay/{$attempt->order->reference}/confirm")
            ->assertRedirect();

        $this->assertSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
        $this->assertSame(OrderStatus::Paid, $attempt->order->fresh()->order_status);
    }

    public function test_a_browser_return_alone_cannot_mark_paid(): void
    {
        $attempt = $this->pendingAttempt();

        // 對方說「這筆不存在」——瀏覽器回來了，但確認失敗。
        $this->fakeConfirm(['returnCode' => '1150', 'returnMessage' => 'no such transaction']);

        $this->get("/payments/linepay/{$attempt->order->reference}/confirm");

        // ⛔ 未知代碼進人工對帳，絕不標記為已付款。
        $this->assertNotSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $attempt->order->fresh()->order_status);
    }

    public function test_an_amount_mismatch_never_marks_paid(): void
    {
        $attempt = $this->pendingAttempt();

        $this->fakeConfirm([
            'returnCode' => '0000',
            'info' => [
                'transactionId' => '2026081700000001',
                'orderId' => $attempt->reference,
                // ⛔ 對方說 1 元，我們的訂單是 590。
                'amount' => 1,
                'currency' => 'TWD',
            ],
        ]);

        $this->get("/payments/linepay/{$attempt->order->reference}/confirm");

        $this->assertSame(PaymentStatus::ReconciliationRequired, $attempt->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $attempt->order->fresh()->order_status);
    }

    public function test_an_order_id_mismatch_never_marks_paid(): void
    {
        $attempt = $this->pendingAttempt();

        $this->fakeConfirm([
            'returnCode' => '0000',
            'info' => [
                'transactionId' => '2026081700000001',
                'orderId' => 'SOMEONE-ELSES-ORDER',
                'amount' => 590,
                'currency' => 'TWD',
            ],
        ]);

        $this->get("/payments/linepay/{$attempt->order->reference}/confirm");

        $this->assertNotSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
    }

    public function test_a_currency_mismatch_never_marks_paid(): void
    {
        $attempt = $this->pendingAttempt();

        $this->fakeConfirm([
            'returnCode' => '0000',
            'info' => [
                'transactionId' => '2026081700000001',
                'orderId' => $attempt->reference,
                'amount' => 590,
                'currency' => 'JPY',
            ],
        ]);

        $this->get("/payments/linepay/{$attempt->order->reference}/confirm");

        $this->assertNotSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
    }

    public function test_a_confirm_timeout_goes_to_reconciliation(): void
    {
        $attempt = $this->pendingAttempt();

        Http::fake([
            self::BASE.'/v4/payments/*/confirm' => fn () => throw new ConnectionException('timeout'),
        ]);

        $this->get("/payments/linepay/{$attempt->order->reference}/confirm");

        // ⛔ 錢可能已經扣了：不得記為失敗，也不得自動重送。
        $this->assertSame(PaymentStatus::ReconciliationRequired, $attempt->fresh()->status);
    }

    public function test_the_confirm_uses_our_own_amount(): void
    {
        $attempt = $this->pendingAttempt();

        $this->fakeConfirm([
            'returnCode' => '0000',
            'info' => [
                'transactionId' => '2026081700000001',
                'orderId' => $attempt->reference,
                'amount' => 590, 'currency' => 'TWD',
            ],
        ]);

        $this->get("/payments/linepay/{$attempt->order->reference}/confirm");

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/confirm')) {
                return true;
            }

            // ⛔ 確認金額取自我們的紀錄，不是 query 參數。
            return $request->data()['amount'] === 590;
        });
    }

    // ============================================ 5. 取消與重複

    public function test_cancel_marks_an_open_attempt_canceled(): void
    {
        $attempt = $this->pendingAttempt();

        $this->get("/payments/linepay/{$attempt->order->reference}/cancel")->assertRedirect();

        $this->assertSame(PaymentStatus::Canceled, $attempt->fresh()->status);
    }

    public function test_cancel_cannot_downgrade_a_paid_order(): void
    {
        $attempt = $this->pendingAttempt();

        $this->fakeConfirm([
            'returnCode' => '0000',
            'info' => [
                'transactionId' => '2026081700000001',
                'orderId' => $attempt->reference,
                'amount' => 590, 'currency' => 'TWD',
            ],
        ]);

        $this->get("/payments/linepay/{$attempt->order->reference}/confirm");
        $this->assertSame(OrderStatus::Paid, $attempt->order->fresh()->order_status);

        // 之後才到的取消。
        $this->get("/payments/linepay/{$attempt->order->reference}/cancel");

        // ⛔ 已付款不得被降級。
        $this->assertSame(OrderStatus::Paid, $attempt->order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Succeeded, $attempt->fresh()->status);
    }

    public function test_a_repeated_confirm_dispatches_order_paid_once(): void
    {
        Event::fake([OrderPaid::class]);

        $attempt = $this->pendingAttempt();

        $this->fakeConfirm([
            'returnCode' => '0000',
            'info' => [
                'transactionId' => '2026081700000001',
                'orderId' => $attempt->reference,
                'amount' => 590, 'currency' => 'TWD',
            ],
        ]);

        $url = "/payments/linepay/{$attempt->order->reference}/confirm";
        $this->get($url);
        $this->get($url);
        $this->get($url);

        Event::assertDispatched(OrderPaid::class, 1);
    }

    // ============================================ 6. 落盤衛生

    public function test_no_signature_or_secret_is_stored(): void
    {
        $attempt = $this->pendingAttempt();

        $this->fakeConfirm([
            'returnCode' => '1106',
            'returnMessage' => 'ChannelSecret=test-channel-secret-0001 buyer@example.com',
        ]);

        $this->get("/payments/linepay/{$attempt->order->reference}/confirm");

        $raw = json_encode([
            DB::table('payment_attempts')->get(),
            DB::table('orders')->get(),
            DB::table('order_events')->get(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['test-channel-secret-0001', 'buyer@example.com', 'X-LINE-Authorization'] as $marker) {
            $this->assertStringNotContainsString($marker, $raw, "落盤出現敏感字串：{$marker}");
        }
    }

    public function test_payment_routes_are_noindex(): void
    {
        $attempt = $this->pendingAttempt();

        $this->get("/payments/{$attempt->order->reference}/status")
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_the_status_page_never_claims_paid_before_confirmation(): void
    {
        $attempt = $this->pendingAttempt();

        $this->get("/payments/{$attempt->order->reference}/status")
            ->assertOk()
            ->assertDontSee('付款已完成');
    }
}
