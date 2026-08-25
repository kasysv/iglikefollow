<?php

namespace Tests\Feature;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use App\Services\Payments\LinePayClient;
use App\Services\Payments\LinePaySignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * The bytes we sign must be the bytes we send.
 *
 * ⭐ 這是本輪的核心。Owner 在 staging 按下 LINE Pay 後，連付款頁都沒看到就被
 * 擋下——故障在建立付款 `POST /v4/payments/request` 這一步。
 *
 * ⛔ 根因：舊版 `LinePaySignature::headers()` 收 array，在裡面用
 * `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE` 編碼一次算出簽章；
 * `LinePayClient` 卻把**同一個 array** 交給 `->asJson()->post()`，由
 * Laravel／Guzzle 用 PHP 預設旗標**再編碼一次**。兩份 bytes 不同：
 *
 *     簽章： {"returnUrl":"https://staging.iglikefollow.com/…","name":"行銷服務費"}
 *     實送： {"returnUrl":"https:\/\/staging.iglikefollow.com\/…","name":"行銷…"}
 *
 * body 只要含 redirect URL（一定含 `https://`）或中文就必然不同，於是簽章
 * 永遠對不上，LINE Pay 一律拒絕。
 *
 * ⛔ 這個檔案的每個測試都從 fake HTTP **捕捉實際送出的 raw body**，再用捕捉到
 * 的 nonce 與測試 secret 重算 HMAC，跟實際送出的 `X-LINE-Authorization`
 * 逐字元比對。⛔ 不比對「我們以為送了什麼」——那正是舊版看起來沒問題的原因。
 *
 * ⛔ 全程 fake HTTP，0 真實 LINE Pay request。
 */
class LinePayWireSignatureTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    private const BASE = 'https://api-pay.line.me';

    private const SECRET = 'test-channel-secret-0001';

    private const CHANNEL = 'channel-0001';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->runningAsLiveSite();

        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::LinePay, IntegrationEnvironment::Production)
            ->create(['identifier' => self::CHANNEL]);

        $setting->credentials = ['ChannelSecret' => self::SECRET];
        $setting->save();

        DB::table('integration_settings')->where('id', $setting->id)->update(['is_enabled' => true]);
    }

    // ==================================== helpers

    /**
     * A body shaped like the real one: HTTPS redirect URLs plus Unicode.
     *
     * ⛔ 兩者缺一不可。只有 Unicode 而沒有 `https://`，舊版的 slash 逃逸問題
     * 就測不出來；只有 URL 而沒有中文，Unicode 逃逸問題就測不出來。真實的
     * request body 兩者都有。
     *
     * @return array<string, mixed>
     */
    private function realisticBody(): array
    {
        return [
            'amount' => 590,
            'currency' => 'TWD',
            'orderId' => 'IGNF000000000000001',
            'packages' => [[
                'id' => 'pkg-1',
                'amount' => 590,
                'name' => '行銷服務費',
                'products' => [[
                    'name' => '行銷服務費',
                    'quantity' => 1,
                    'price' => 590,
                ]],
            ]],
            'redirectUrls' => [
                'confirmUrl' => 'https://staging.iglikefollow.com/payments/linepay/confirm',
                'cancelUrl' => 'https://staging.iglikefollow.com/payments/linepay/cancel',
            ],
        ];
    }

    private function fakeSuccess(): void
    {
        Http::fake([
            self::BASE.'/*' => Http::response([
                'returnCode' => '0000',
                'returnMessage' => 'Success.',
                'info' => [
                    'transactionId' => '2024010112345678901',
                    'paymentUrl' => ['web' => 'https://sandbox-web-pay.line.me/web/payment/x'],
                    'orderId' => 'IGNF000000000000001',
                    'payInfo' => [['method' => 'CREDIT_CARD', 'amount' => 590]],
                ],
            ], 200),
        ]);
    }

    /** 從 fake 錄到的那一次呼叫取出實際的 raw body 與 headers。 */
    private function captured(): Request
    {
        $recorded = Http::recorded();

        $this->assertCount(1, $recorded, '應恰好送出一次 request。');

        return $recorded[0][0];
    }

    /**
     * ⭐ 本檔案的核心斷言。
     *
     * 用**實際送出的 raw body** 與**實際送出的 nonce**，以測試 secret 重算
     * HMAC，必須與實際送出的 `X-LINE-Authorization` 完全相等。
     */
    private function assertSignatureMatchesWireBody(Request $request, string $apiPath): void
    {
        $rawBody = $request->body();
        $nonce = $request->header('X-LINE-Authorization-Nonce')[0] ?? '';
        $actual = $request->header('X-LINE-Authorization')[0] ?? '';

        $this->assertNotSame('', $rawBody, 'body 不得為空。');
        $this->assertNotSame('', $nonce, 'nonce header 必須存在。');

        $expected = base64_encode(hash_hmac(
            'sha256',
            self::SECRET.$apiPath.$rawBody.$nonce,
            self::SECRET,
            true,
        ));

        $this->assertSame(
            $expected,
            $actual,
            '⛔ 簽章必須涵蓋實際送出的 raw body bytes——這正是舊版失敗的地方。'
        );
    }

    // ==================================== 1. request 階段

    /**
     * ⭐ 反證 1：含 `https://` 與 Unicode 的 request body，簽章＝wire bytes。
     *
     * ⛔ 這個測試在修正前必定失敗：簽章算的是未逃逸的 bytes，實際送出的是
     * Guzzle 重新編碼、slash 與 Unicode 都被逃逸過的 bytes。
     */
    public function test_the_request_signature_covers_the_exact_wire_body(): void
    {
        $this->fakeSuccess();

        app(LinePayClient::class)->requestPayment($this->realisticBody());

        $request = $this->captured();

        $this->assertSignatureMatchesWireBody($request, '/v4/payments/request');

        // ⛔ 實際送出的 bytes 真的含未逃逸的 https:// 與中文。
        $this->assertStringContainsString('https://staging.iglikefollow.com', $request->body());
        $this->assertStringContainsString('行銷服務費', $request->body());
    }

    /** 送出的 raw body 可解回原本的 array，⛔ 不是雙重編碼的 JSON 字串。 */
    public function test_the_request_body_decodes_back_to_the_original_array(): void
    {
        $this->fakeSuccess();

        $body = $this->realisticBody();
        app(LinePayClient::class)->requestPayment($body);

        $decoded = json_decode($this->captured()->body(), true);

        $this->assertSame($body, $decoded, '⛔ wire body 必須是原本的結構，不得雙重編碼。');
    }

    public function test_the_request_carries_the_channel_id_and_json_content_type(): void
    {
        $this->fakeSuccess();

        app(LinePayClient::class)->requestPayment($this->realisticBody());

        $request = $this->captured();

        $this->assertSame(self::CHANNEL, $request->header('X-LINE-ChannelId')[0] ?? null);
        $this->assertStringContainsString('application/json', $request->header('Content-Type')[0] ?? '');
        // ⛔ secret 永遠不得出現在任何 header 或 body 裡。
        $this->assertStringNotContainsString(self::SECRET, json_encode($request->headers()).$request->body());
    }

    // ==================================== 2. confirm 階段

    /** ⭐ 反證 2：confirm 同樣必須「簽章＝wire bytes」。 */
    public function test_the_confirm_signature_covers_the_exact_wire_body(): void
    {
        $this->fakeSuccess();

        $transactionId = '2024010112345678901';

        app(LinePayClient::class)->confirmPayment($transactionId, [
            'amount' => 590,
            'currency' => 'TWD',
        ]);

        $this->assertSignatureMatchesWireBody(
            $this->captured(),
            "/v4/payments/{$transactionId}/confirm",
        );
    }

    /**
     * confirm 的 URI 含 transactionId，簽章必須涵蓋那個 URI。
     *
     * ⛔ 否則同一份簽章可以被轉送到另一筆交易的 confirm。
     */
    public function test_the_confirm_signature_is_bound_to_its_transaction_id(): void
    {
        $this->fakeSuccess();

        app(LinePayClient::class)->confirmPayment('2024010112345678901', ['amount' => 590, 'currency' => 'TWD']);

        $request = $this->captured();
        $rawBody = $request->body();
        $nonce = $request->header('X-LINE-Authorization-Nonce')[0];

        // 用「另一個 transactionId」重算，必須不相等。
        $wrong = base64_encode(hash_hmac(
            'sha256',
            self::SECRET.'/v4/payments/9999999999999999999/confirm'.$rawBody.$nonce,
            self::SECRET,
            true,
        ));

        $this->assertNotSame($wrong, $request->header('X-LINE-Authorization')[0]);
    }

    // ==================================== 3. nonce

    /** ⭐ 反證 4：實際送出的 nonce 是 UUID v4，且每次呼叫都不同。 */
    public function test_the_wire_nonce_is_a_uuid_v4_and_never_repeats(): void
    {
        $this->fakeSuccess();

        $client = app(LinePayClient::class);
        $nonces = [];

        for ($i = 0; $i < 5; $i++) {
            $client->requestPayment($this->realisticBody());
        }

        foreach (Http::recorded() as [$request]) {
            $nonce = $request->header('X-LINE-Authorization-Nonce')[0] ?? '';

            $this->assertMatchesRegularExpression(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                $nonce,
                '⛔ 官方要求 UUID v1／v4 或 timestamp；舊版的 32 位 hex 不合格。'
            );

            $nonces[] = $nonce;
        }

        $this->assertCount(5, array_unique($nonces), '⛔ nonce 重複等於允許重放。');
    }

    // ==================================== 4. 編碼失敗 fail closed

    /**
     * ⭐ 反證 5：JSON 編碼失敗時 0 HTTP request，且不洩漏輸入內容。
     *
     * ⛔ 用空字串或部分 body 硬送，等於送出一份與簽章不符、內容也不完整的
     * 請求；`neverSent()` 也必須為真，呼叫端才知道重試是安全的。
     */
    public function test_an_unencodable_body_sends_nothing_and_fails_closed(): void
    {
        Http::fake();

        // ⛔ 無效 UTF-8：json_encode 會失敗。
        $result = app(LinePayClient::class)->requestPayment([
            'amount' => 590,
            'secret-marker' => "\xB1\x31",
        ]);

        Http::assertNothingSent();

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->neverSent(), '⛔ 沒送出就必須說沒送出，否則重試會被誤判為不安全。');
        $this->assertNull($result->transactionId);
    }

    // ==================================== 5. 安全診斷

    /**
     * 診斷只記 phase、四位 returnCode 與本地 reason token。
     *
     * ⛔ 惡意 `returnMessage` 即使塞入 secret 或 email，也不得出現在 log。
     */
    public function test_a_rejection_logs_only_allowlisted_fields(): void
    {
        $poison = 'secret='.self::SECRET.' buyer@example.test 0912345678';

        Http::fake([
            self::BASE.'/*' => Http::response([
                'returnCode' => '1106',
                'returnMessage' => $poison,
                'info' => ['orderId' => 'IGNF000000000000001'],
            ], 200),
        ]);

        $captured = [];

        Log::listen(function ($message) use (&$captured): void {
            $captured[] = $message;
        });

        $result = app(LinePayClient::class)->requestPayment($this->realisticBody());

        $this->assertFalse($result->isSuccess());
        $this->assertCount(1, $captured);

        $context = $captured[0]->context;

        // ⛔ 恰好三個欄位，一個都不多。
        $this->assertSame(['phase', 'return_code', 'reason'], array_keys($context));
        $this->assertSame('request', $context['phase']);
        $this->assertSame('1106', $context['return_code']);
        $this->assertSame('provider_rejected', $context['reason']);

        // ⛔ 整筆 log（含 message 與 context）不得含任何敏感字串。
        $serialised = $captured[0]->message.json_encode($context);

        foreach ([self::SECRET, 'buyer@example.test', '0912345678', $poison, 'IGNF000000000000001'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialised);
        }
    }

    /** 非四位數的 returnCode 只記 `unrecognized`，⛔ 不回顯原值。 */
    public function test_a_non_numeric_return_code_is_never_echoed(): void
    {
        Http::fake([
            self::BASE.'/*' => Http::response([
                'returnCode' => '<script>alert(1)</script>secret-'.self::SECRET,
                'returnMessage' => 'x',
            ], 200),
        ]);

        $captured = [];
        Log::listen(function ($message) use (&$captured): void {
            $captured[] = $message;
        });

        app(LinePayClient::class)->requestPayment($this->realisticBody());

        $context = $captured[0]->context;

        $this->assertSame('unrecognized', $context['return_code']);
        $this->assertStringNotContainsString('script', json_encode($context));
        $this->assertStringNotContainsString(self::SECRET, json_encode($context));
    }

    /** confirm 階段的診斷要標成 confirm，⛔ 但不得帶出 transactionId。 */
    public function test_a_confirm_rejection_is_labelled_confirm_without_the_transaction_id(): void
    {
        Http::fake([
            self::BASE.'/*' => Http::response(['returnCode' => '1124', 'returnMessage' => 'x'], 200),
        ]);

        $captured = [];
        Log::listen(function ($message) use (&$captured): void {
            $captured[] = $message;
        });

        app(LinePayClient::class)->confirmPayment('2024010112345678901', [
            'amount' => 590,
            'currency' => 'TWD',
        ]);

        $context = $captured[0]->context;

        $this->assertSame('confirm', $context['phase']);
        $this->assertSame('1124', $context['return_code']);
        // ⛔ URI 裡有 transactionId，但診斷不得帶出來。
        $this->assertStringNotContainsString('2024010112345678901', json_encode($context));
    }

    /** 非 200 與無法解析的 body 各有自己的本地 reason token。 */
    public function test_transport_level_failures_carry_local_reason_tokens(): void
    {
        Http::fake([self::BASE.'/*' => Http::response('not json at all', 500)]);

        $captured = [];
        Log::listen(function ($message) use (&$captured): void {
            $captured[] = $message;
        });

        $result = app(LinePayClient::class)->requestPayment($this->realisticBody());

        $this->assertFalse($result->isSuccess());
        $this->assertSame('http_status_not_200', $captured[0]->context['reason']);
        $this->assertSame('unrecognized', $captured[0]->context['return_code']);
    }

    /** 成功時不寫診斷：⛔ log 只用來記錄故障。 */
    public function test_a_successful_call_logs_nothing(): void
    {
        $this->fakeSuccess();

        $captured = [];
        Log::listen(function ($message) use (&$captured): void {
            $captured[] = $message;
        });

        $result = app(LinePayClient::class)->requestPayment($this->realisticBody());

        $this->assertTrue($result->isSuccess());
        $this->assertSame([], $captured);
    }

    // ==================================== 6. 成功路徑不變

    /** ⭐ 反證 6：`0000` 仍保存完整 19 位 transactionId 與官方付款 URL。 */
    public function test_a_successful_request_keeps_the_full_transaction_id(): void
    {
        $this->fakeSuccess();

        $result = app(LinePayClient::class)->requestPayment($this->realisticBody());

        $this->assertTrue($result->isSuccess());
        $this->assertSame('2024010112345678901', $result->transactionId);
        $this->assertSame(19, strlen((string) $result->transactionId));
        $this->assertStringStartsWith('https://', (string) $result->paymentUrl);
    }

    /** 簽章工具本身：同一份 raw bytes 兩次算出相同結果。 */
    public function test_signing_the_same_raw_bytes_is_stable(): void
    {
        $raw = '{"a":"https://x.test/y"}';

        $this->assertSame(
            LinePaySignature::sign(self::SECRET, '/v4/payments/request', $raw, 'n-1'),
            LinePaySignature::sign(self::SECRET, '/v4/payments/request', $raw, 'n-1'),
        );
    }
}
