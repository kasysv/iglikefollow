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

    /**
     * ⭐ The worked example from ECPay's own documentation.
     *
     * Every other test here compares this implementation against itself, which
     * proves it is consistent but not that it is *correct* — a wrong encoding
     * step would be wrong identically on both sides and still pass. This is the
     * only case with an expected digest that does not come from our own code.
     *
     * ⛔ The key and IV below are the ones printed in the public specification
     * as a teaching example. They belong to nobody, authorise nothing, and are
     * here so the algorithm can be re-verified without asking anyone for a real
     * credential. No live merchant value is in this repository.
     *
     * Source: <https://developers.ecpay.com.tw/?p=2902>
     */
    public function test_the_mac_matches_the_published_worked_example(): void
    {
        $fields = [
            'MerchantID' => '2000132',
            'MerchantTradeNo' => '20130589723',
            'MerchantTradeDate' => '2013/03/12 15:30:23',
            'PaymentType' => 'aio',
            'TotalAmount' => '1000',
            'TradeDesc' => '促銷方案',
            'ItemName' => 'Apple iphone 5',
            'ReturnURL' => 'http://www.ecpay.com.tw/receive.php',
            'ChoosePayment' => 'ALL',
        ];

        $this->assertSame(
            '6A6C4ABCBD95416E6CB980FD31BC6098A85513B6C15E225A466FD4E03F0841B5',
            EcpayCheckMac::generate($fields, '5294y06JbISpM5x9', 'v77hoKGq4kWxNNIS'),
        );
    }

    /**
     * ⭐ The current worked example on ECPay's specification page.
     *
     * Kept alongside the older one rather than replacing it: two independent
     * vectors, computed from different amounts, item names and dates, make it
     * far harder for a wrong encoding step to satisfy both by coincidence.
     *
     * ⛔ The key and IV are the public teaching values printed in the
     * specification. They belong to nobody and authorise nothing.
     *
     * Source: <https://developers.ecpay.com.tw/2902/>
     */
    public function test_the_mac_matches_the_current_published_example(): void
    {
        $fields = [
            'TradeDesc' => '促銷方案',
            'PaymentType' => 'aio',
            'MerchantTradeDate' => '2023/03/12 15:30:23',
            'MerchantTradeNo' => 'ecpay20230312153023',
            'MerchantID' => '3002607',
            'ReturnURL' => 'https://www.ecpay.com.tw/receive.php',
            'ItemName' => 'Apple iphone 15',
            'TotalAmount' => '30000',
            'ChoosePayment' => 'ALL',
            'EncryptType' => '1',
        ];

        $this->assertSame(
            '6C51C9E6888DE861FD62FB1DD17029FC742634498FD813DC43D4243B5685B840',
            EcpayCheckMac::generate($fields, 'pwFHCqoQZGmho4w6', 'EkRm7iFT261dpevs'),
        );
    }

    /**
     * The .NET-compatibility substitutions, pinned individually.
     *
     * ⛔ These are the step implementations usually get wrong, and getting one
     * wrong produces a system where *some* orders can pay and others cannot —
     * the hardest kind of fault to notice.
     */
    public function test_the_encoding_substitutions_are_applied(): void
    {
        // ⛔ 直接檢查被雜湊的那個字串，而不是比較兩個雜湊：
        // 兩邊都錯的話，比較雜湊會一起錯得一模一樣而通過。
        $encode = new \ReflectionMethod(EcpayCheckMac::class, 'dotNetUrlEncode');

        $encoded = $encode->invoke(null, 'a b-c_d.e!f*g(h)i');

        // 空白 → +
        $this->assertStringContainsString('+', $encoded);
        $this->assertStringNotContainsString('%20', $encoded);

        // 這些字元必須維持原樣，⛔ 不得 percent-encode。
        foreach (['-', '_', '.', '!', '*', '(', ')'] as $char) {
            $this->assertStringContainsString($char, $encoded, "{$char} 不應被 percent-encode");
        }

        // 其餘字元仍要編碼，且整體轉小寫。
        $this->assertSame(strtolower($encoded), $encoded, '編碼結果必須全小寫');
    }

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
