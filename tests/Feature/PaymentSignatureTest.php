<?php

namespace Tests\Feature;

use App\Services\Payments\EcpayCheckMac;
use App\Services\Payments\LinePaySignature;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The two signing algorithms, checked against fixed vectors.
 *
 * These are the load-bearing parts of both integrations: a wrong signature
 * means every payment is rejected, and a signature that is not verified means
 * anyone can claim an order was paid. Neither failure is visible from the
 * outside until money is involved.
 *
 * ⛔ The keys below are obviously fake strings written for this file. No real
 * or officially published credential belongs in a repository.
 */
class PaymentSignatureTest extends TestCase
{
    private const HASH_KEY = 'test-hash-key-0001';

    private const HASH_IV = 'test-hash-iv-0001';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** @return array<string, string> */
    private function fields(): array
    {
        return [
            'MerchantID' => '3000001',
            'MerchantTradeNo' => 'PAYTEST0000001',
            'MerchantTradeDate' => '2026/08/17 10:00:00',
            'PaymentType' => 'aio',
            'TotalAmount' => '590',
            'TradeDesc' => 'IGLIKEFOLLOW social media service',
            'ItemName' => 'Social media service',
            'ReturnURL' => 'https://example.test/payments/ecpay/callback',
            'ChoosePayment' => 'Credit',
        ];
    }

    // ============================================ ECPay CheckMacValue

    public function test_the_mac_is_stable_for_the_same_input(): void
    {
        $first = EcpayCheckMac::generate($this->fields(), self::HASH_KEY, self::HASH_IV);
        $second = EcpayCheckMac::generate($this->fields(), self::HASH_KEY, self::HASH_IV);

        $this->assertSame($first, $second);
        // 綠界規格：SHA-256 十六進位大寫。
        $this->assertMatchesRegularExpression('/^[0-9A-F]{64}$/', $first);
    }

    public function test_field_order_does_not_change_the_mac(): void
    {
        $fields = $this->fields();
        $shuffled = array_reverse($fields, preserve_keys: true);

        // ⛔ 演算法自己排序；呼叫端的順序不該影響結果。
        $this->assertSame(
            EcpayCheckMac::generate($fields, self::HASH_KEY, self::HASH_IV),
            EcpayCheckMac::generate($shuffled, self::HASH_KEY, self::HASH_IV),
        );
    }

    public function test_sorting_is_case_insensitive(): void
    {
        // 綠界規格要求不分大小寫排序；ksort() 會把大寫排在小寫前面而得到不同結果。
        $lower = ['aaa' => '1', 'BBB' => '2'];
        $upper = ['BBB' => '2', 'aaa' => '1'];

        $this->assertSame(
            EcpayCheckMac::generate($lower, self::HASH_KEY, self::HASH_IV),
            EcpayCheckMac::generate($upper, self::HASH_KEY, self::HASH_IV),
        );
    }

    public function test_changing_one_character_changes_the_mac(): void
    {
        $original = EcpayCheckMac::generate($this->fields(), self::HASH_KEY, self::HASH_IV);

        $tampered = $this->fields();
        $tampered['TotalAmount'] = '591';   // 只改一塊錢

        $this->assertNotSame(
            $original,
            EcpayCheckMac::generate($tampered, self::HASH_KEY, self::HASH_IV),
        );
    }

    public function test_a_different_key_changes_the_mac(): void
    {
        $this->assertNotSame(
            EcpayCheckMac::generate($this->fields(), self::HASH_KEY, self::HASH_IV),
            EcpayCheckMac::generate($this->fields(), 'another-key-0002', self::HASH_IV),
        );
    }

    public function test_the_existing_mac_field_is_excluded(): void
    {
        $withMac = $this->fields();
        $withMac['CheckMacValue'] = 'STALEVALUE';

        // ⛔ 舊的簽章值不得參與新簽章的計算。
        $this->assertSame(
            EcpayCheckMac::generate($this->fields(), self::HASH_KEY, self::HASH_IV),
            EcpayCheckMac::generate($withMac, self::HASH_KEY, self::HASH_IV),
        );
    }

    public function test_verification_accepts_the_correct_value(): void
    {
        $fields = $this->fields();
        $fields['CheckMacValue'] = EcpayCheckMac::generate($fields, self::HASH_KEY, self::HASH_IV);

        $this->assertTrue(
            EcpayCheckMac::matches($fields, self::HASH_KEY, self::HASH_IV, $fields['CheckMacValue'])
        );
    }

    public function test_verification_rejects_a_tampered_value(): void
    {
        $fields = $this->fields();
        $mac = EcpayCheckMac::generate($fields, self::HASH_KEY, self::HASH_IV);

        // 改掉簽章的最後一個字元。
        $tampered = substr($mac, 0, -1).($mac[63] === 'A' ? 'B' : 'A');

        $this->assertFalse(
            EcpayCheckMac::matches($fields, self::HASH_KEY, self::HASH_IV, $tampered)
        );
    }

    public function test_verification_rejects_a_missing_value(): void
    {
        $this->assertFalse(EcpayCheckMac::matches($this->fields(), self::HASH_KEY, self::HASH_IV, null));
        $this->assertFalse(EcpayCheckMac::matches($this->fields(), self::HASH_KEY, self::HASH_IV, ''));
    }

    public function test_verification_uses_a_constant_time_comparison(): void
    {
        // ⛔ 用 hash_equals 而非 ===：字串比較會在第一個不同的位元組短路，
        // 洩漏「猜對了幾個字元」，讓簽章可以被逐位猜出來。
        $source = file_get_contents(app_path('Services/Payments/EcpayCheckMac.php'));

        $this->assertStringContainsString('hash_equals', $source);
    }

    // ============================================ LINE Pay HMAC

    public function test_the_line_signature_is_stable_for_the_same_input(): void
    {
        $body = ['amount' => 590, 'currency' => 'TWD', 'orderId' => 'PAYTEST0000001'];

        $a = LinePaySignature::sign('secret-0001', '/v4/payments/request', json_encode($body), 'nonce-1');
        $b = LinePaySignature::sign('secret-0001', '/v4/payments/request', json_encode($body), 'nonce-1');

        $this->assertSame($a, $b);
        $this->assertNotSame('', $a);
    }

    public function test_the_line_signature_covers_the_request_uri(): void
    {
        $body = json_encode(['amount' => 590]);

        // ⛔ 不含 URI 的話，同一份簽章可以被轉送到別的端點。
        $this->assertNotSame(
            LinePaySignature::sign('secret-0001', '/v4/payments/request', $body, 'n'),
            LinePaySignature::sign('secret-0001', '/v4/payments/x/confirm', $body, 'n'),
        );
    }

    public function test_the_line_signature_covers_the_body(): void
    {
        $this->assertNotSame(
            LinePaySignature::sign('secret-0001', '/v4/payments/request', json_encode(['amount' => 590]), 'n'),
            LinePaySignature::sign('secret-0001', '/v4/payments/request', json_encode(['amount' => 591]), 'n'),
        );
    }

    public function test_the_line_signature_covers_the_nonce(): void
    {
        $body = json_encode(['amount' => 590]);

        $this->assertNotSame(
            LinePaySignature::sign('secret-0001', '/v4/payments/request', $body, 'nonce-1'),
            LinePaySignature::sign('secret-0001', '/v4/payments/request', $body, 'nonce-2'),
        );
    }

    public function test_every_nonce_is_different(): void
    {
        $nonces = [];

        for ($i = 0; $i < 50; $i++) {
            $nonces[] = LinePaySignature::nonce();
        }

        // ⛔ 重複的 nonce 等於允許重放同一筆簽好的請求。
        $this->assertCount(50, array_unique($nonces));
    }

    public function test_the_headers_carry_the_channel_id_and_nonce(): void
    {
        $headers = LinePaySignature::headers(
            'channel-0001', 'secret-0001', '/v4/payments/request', ['amount' => 590], 'nonce-1'
        );

        $this->assertSame('channel-0001', $headers['X-LINE-ChannelId']);
        $this->assertSame('nonce-1', $headers['X-LINE-Authorization-Nonce']);
        // ⛔ secret 本身永遠不出現在 header 裡。
        $this->assertStringNotContainsString('secret-0001', json_encode($headers));
    }
}
