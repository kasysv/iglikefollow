<?php

namespace Tests\Feature;

use App\Actions\Payments\ResolvePaymentAttempt;
use App\DTO\LinePayResponse;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Models\IntegrationSetting;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Payments\LinePayGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What a provider sends is untyped, and must be treated that way.
 *
 * PHP will happily turn an array into the string "Array", so a malformed
 * success body could satisfy a `!== null` check, be stored as the provider
 * reference, and send the customer off to pay — after which the confirm call
 * quotes a transaction id that never existed and the payment can never be
 * settled. The value looked present the whole way through.
 *
 * So each field is checked for the shape it must have, not merely for being
 * non-null, and anything else means we could not read the response — which is
 * an uncertain outcome, not a failure.
 */
class PaymentResponseShapeTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://sandbox-api-pay.line.me';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('integrations.payments.sandbox_enabled', true);

        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::LinePay, IntegrationEnvironment::Sandbox)
            ->create(['identifier' => 'channel-0001']);

        $setting->credentials = ['ChannelSecret' => 'test-channel-secret-0001'];
        $setting->save();

        DB::table('integration_settings')->where('id', $setting->id)->update(['is_enabled' => true]);
    }

    /** A claimed attempt, taken from the real resolver. */
    private function claimed(): PaymentAttempt
    {
        $order = Order::factory()->create(['total_amount' => 590]);

        return app(ResolvePaymentAttempt::class)->handle($order, 'line-pay');
    }

    private function fakeRequest(array $info): void
    {
        Http::fake([
            self::BASE.'/v4/payments/request' => Http::response([
                'returnCode' => '0000',
                'returnMessage' => 'Success.',
                'info' => $info,
            ], 200),
        ]);
    }

    // ==================================== R4-1：scalar shape 必須 fail closed

    public static function badTransactionIdProvider(): array
    {
        return [
            'array' => [['bad']],
            'nested array' => [[['deeper']]],
            'bool true' => [true],
            'bool false' => [false],
            'float' => [12.5],
            'null' => [null],
            'empty string' => [''],
            'object' => [new \stdClass],
        ];
    }

    /**
     * ⭐ 這是 R4 的核心缺陷。
     *
     * ⛔ PHP 會把陣列轉成字串 "Array"，所以一個畸形的成功回應可以通過
     * `!== null` 檢查、被存成 provider reference、還把客人導去付款——
     * 之後 confirm 引用一個從不存在的交易編號，這筆付款永遠結不了案。
     */
    #[DataProvider('badTransactionIdProvider')]
    public function test_a_malformed_transaction_id_never_becomes_a_reference(mixed $transactionId): void
    {
        $attempt = $this->claimed();

        $this->fakeRequest([
            'transactionId' => $transactionId,
            'paymentUrl' => ['web' => 'https://sandbox-web-pay.line.me/x'],
        ]);

        $result = app(LinePayGateway::class)->initiate($attempt);

        // ⛔ 不得 redirect：客人不能被送到一個我們無法 confirm 的付款。
        $this->assertFalse($result->isRedirect());

        $fresh = $attempt->fresh();
        // ⛔ 不得保存假的參考碼，尤其不是字串 "Array"。
        $this->assertNotSame('Array', $fresh->provider_reference);
        $this->assertNull($fresh->provider_reference);

        // 讀不懂回應＝結果不明，⛔ 不是失敗。
        $this->assertSame(PaymentStatus::ReconciliationRequired, $fresh->status);

        // 下一次付款必須被擋下。
        $this->assertNull(
            app(ResolvePaymentAttempt::class)->handle($attempt->order->fresh(), 'line-pay')
        );
    }

    public function test_a_string_transaction_id_is_accepted(): void
    {
        $attempt = $this->claimed();

        $this->fakeRequest([
            'transactionId' => '2026081700000001',
            'paymentUrl' => ['web' => 'https://sandbox-web-pay.line.me/x'],
        ]);

        $result = app(LinePayGateway::class)->initiate($attempt);

        $this->assertTrue($result->isRedirect());
        $this->assertSame('2026081700000001', $attempt->fresh()->provider_reference);
    }

    public function test_an_integer_transaction_id_is_accepted(): void
    {
        $attempt = $this->claimed();

        // LINE Pay 的交易編號在 JSON 中可能是數字。
        $this->fakeRequest([
            'transactionId' => 2026081700000001,
            'paymentUrl' => ['web' => 'https://sandbox-web-pay.line.me/x'],
        ]);

        $result = app(LinePayGateway::class)->initiate($attempt);

        $this->assertTrue($result->isRedirect());
        $this->assertSame('2026081700000001', $attempt->fresh()->provider_reference);
    }

    public static function badPaymentUrlProvider(): array
    {
        return [
            'array' => [['bad']],
            'bool' => [true],
            'float' => [1.5],
            'empty string' => [''],
        ];
    }

    #[DataProvider('badPaymentUrlProvider')]
    public function test_a_malformed_payment_url_never_redirects(mixed $web): void
    {
        $attempt = $this->claimed();

        $this->fakeRequest([
            'transactionId' => '2026081700000001',
            'paymentUrl' => ['web' => $web],
        ]);

        $result = app(LinePayGateway::class)->initiate($attempt);

        $this->assertFalse($result->isRedirect());
        $this->assertSame(PaymentStatus::ReconciliationRequired, $attempt->fresh()->status);
    }

    public static function badOrderIdProvider(): array
    {
        return [
            'array' => [['bad']],
            'bool' => [true],
            'float' => [1.5],
            'empty string' => [''],
        ];
    }

    /** confirm 回應的 orderId 同樣只接受非空字串。 */
    #[DataProvider('badOrderIdProvider')]
    public function test_a_malformed_confirm_order_id_is_null(mixed $orderId): void
    {
        $response = LinePayResponse::fromArray([
            'returnCode' => '0000',
            'info' => [
                'orderId' => $orderId,
                'transactionId' => 'TXN-1',
                'payInfo' => [['method' => 'BALANCE', 'amount' => 590]],
            ],
        ]);

        // ⛔ 讀不懂就是 null，之後的比對必然失敗——不得因為「有值」而通過。
        $this->assertNull($response->orderId);
    }

    public function test_no_warning_is_emitted_for_a_malformed_body(): void
    {
        // ⛔ 寬鬆 cast 會噴 PHP warning；那既是雜訊也是「我們沒有檢查型別」的證據。
        set_error_handler(function (int $severity, string $message): bool {
            $this->fail("畸形回應不得產生 PHP warning：{$message}");
        }, E_ALL);

        try {
            LinePayResponse::fromArray([
                'returnCode' => '0000',
                'info' => ['transactionId' => ['bad'], 'orderId' => ['also bad']],
            ]);
        } finally {
            restore_error_handler();
        }

        $this->addToAssertionCount(1);
    }

    // ==================================== R4-2：result code 必須符合官方定義

    public function test_1155_is_not_called_an_amount_mismatch(): void
    {
        $reason = LinePayResponse::fromArray(['returnCode' => '1155'])->reason();

        // 官方定義是 invalid transaction ID，⛔ 與金額無關。
        $this->assertNotSame(PaymentFailureReason::AmountMismatch, $reason);
        // 無法證明錢沒有移動，⛔ 必須進人工對帳。
        $this->assertTrue($reason->isUncertain());
    }

    public function test_1198_is_not_called_a_timeout(): void
    {
        $reason = LinePayResponse::fromArray(['returnCode' => '1198'])->reason();

        // 官方定義是 API request duplicated，⛔ 不是逾時。
        $this->assertNotSame(PaymentFailureReason::Timeout, $reason);
        $this->assertTrue($reason->isUncertain());
    }

    public function test_1105_is_provider_unavailable(): void
    {
        $this->assertSame(
            PaymentFailureReason::ProviderUnavailable,
            LinePayResponse::fromArray(['returnCode' => '1105'])->reason(),
        );
    }

    public static function merchantSideCodeProvider(): array
    {
        return [['1104'], ['1106'], ['1124'], ['1183']];
    }

    /** ⛔ 這些是我們送錯，不得說成客戶被拒。 */
    #[DataProvider('merchantSideCodeProvider')]
    public function test_merchant_side_codes_do_not_blame_the_customer(string $code): void
    {
        $reason = LinePayResponse::fromArray(['returnCode' => $code])->reason();

        $this->assertNotSame(PaymentFailureReason::Declined, $reason);
        // 確定沒有付款 session，可以安全重試。
        $this->assertFalse($reason->isUncertain());
    }

    public function test_a_raw_return_message_is_never_stored(): void
    {
        $attempt = $this->claimed();

        Http::fake([
            self::BASE.'/v4/payments/request' => Http::response([
                'returnCode' => '1155',
                'returnMessage' => 'ChannelSecret=test-channel-secret-0001 buyer@example.com',
            ], 200),
        ]);

        app(LinePayGateway::class)->initiate($attempt);

        $raw = json_encode(DB::table('payment_attempts')->get(), JSON_UNESCAPED_UNICODE);

        foreach (['test-channel-secret-0001', 'buyer@example.com'] as $marker) {
            $this->assertStringNotContainsString($marker, $raw);
        }
    }

    // ==================================== R4-3：redirect host 逐一反證

    public static function rejectedRedirectProvider(): array
    {
        return [
            'production web host' => ['https://web-pay.line.me/web/payment'],
            'production short host' => ['https://pay.line.me/web/payment'],
            // API 端點不是給人看的頁面。
            'sandbox api host' => ['https://sandbox-api-pay.line.me/v4/payments'],
            'arbitrary host' => ['https://evil.example.com/collect'],
            'lookalike host' => ['https://sandbox-web-pay.line.me.evil.example.com/x'],
            'non https' => ['http://sandbox-web-pay.line.me/x'],
        ];
    }

    #[DataProvider('rejectedRedirectProvider')]
    public function test_a_disallowed_redirect_host_is_refused(string $url): void
    {
        $attempt = $this->claimed();

        $this->fakeRequest([
            'transactionId' => '2026081700000001',
            'paymentUrl' => ['web' => $url],
        ]);

        $result = app(LinePayGateway::class)->initiate($attempt);

        // ⛔ 不得把客人導過去。
        $this->assertFalse($result->isRedirect());
        $this->assertSame(PaymentStatus::ReconciliationRequired, $attempt->fresh()->status);
        // ⛔ 也不得留下一筆看起來可以 confirm 的成功紀錄。
        $this->assertNull($attempt->fresh()->provider_reference);
    }

    public function test_the_sandbox_payment_page_is_accepted(): void
    {
        $attempt = $this->claimed();

        $this->fakeRequest([
            'transactionId' => '2026081700000001',
            'paymentUrl' => ['web' => 'https://sandbox-web-pay.line.me/web/payment/wait?t=abc'],
        ]);

        $this->assertTrue(app(LinePayGateway::class)->initiate($attempt)->isRedirect());
    }
}
