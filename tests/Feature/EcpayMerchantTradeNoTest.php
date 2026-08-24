<?php

namespace Tests\Feature;

use App\Enums\IntegrationProvider;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Payments\EcpayCheckMac;
use App\Services\Payments\EcpayPaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * ECPay `MerchantTradeNo`: pure alphanumeric, at most 20 characters.
 *
 * staging 實測:舊的 `PAY-XXXXXXXXXXXX` 被綠界以
 * `10200031 MerchantTradeNo Must be Number or English Letter` 拒絕——連字號
 * 違反 AioCheckOut V5 的 String(20) 純英數規格。被拒代表該次**根本沒有建立**
 * 綠界交易。R1 依 Owner 指定收斂為:
 *
 *   1. reference = `IGNF`+15 位隨機數字,固定 19 字(⛔ 不做滿 20,保留
 *      1 字空間);15 位固定長度、保留前導 0、逐位 `random_int()` 密碼學
 *      安全,⛔ 不用可猜流水號/時間戳/截斷自增 ID;
 *   2. `ItemName` 固定為 `行銷服務費`,不帶帳號/網址/方案/個資;
 *   3. handoff 在簽章與輸出 form 之前仍驗 `\A[A-Za-z0-9]{1,20}\z`——
 *      legacy/人工資料 fail closed,走既有 `giveUp()`,⛔ 0 綠界 form。
 *
 * ⛔ 全程 fake HTTP＋`preventStrayRequests()`;綠界的 handoff 本來就是
 * browser form POST,伺服器端 0 請求。
 */
class EcpayMerchantTradeNoTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    /** 官方規格,與 gateway 內的驗證同一條 regex。 */
    private const OFFICIAL_PATTERN = '/\A[A-Za-z0-9]{1,20}\z/';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** Owner 已開啟綠界付款的正式站;handoff 需要完整 credential。 */
    private function armEcpay(): void
    {
        $this->runningAsLiveSite();
        $this->enableChannel(IntegrationProvider::EcpayPayment, '3000001');
    }

    private function attempt(string $reference): PaymentAttempt
    {
        return PaymentAttempt::factory()->create([
            'order_id' => Order::factory()->create(['total_amount' => 590])->id,
            'provider' => 'ecpay',
            'reference' => $reference,
            'amount' => 590,
            'status' => PaymentStatus::Pending,
        ]);
    }

    // ==================================== 1. 新 reference 的形狀

    /**
     * 大量樣本:每一個都精確是 `IGNF`+15 位數字、固定 19 字。
     */
    public function test_every_new_reference_is_pure_alphanumeric_within_20_chars(): void
    {
        $seen = [];
        $leadingZero = 0;

        for ($i = 0; $i < 500; $i++) {
            $reference = PaymentAttempt::newReference();

            // ⛔ Owner 指定的精確格式;無連字號、無第 20 字。
            $this->assertMatchesRegularExpression('/\AIGNF[0-9]{15}\z/', $reference, $reference);
            $this->assertSame(19, strlen($reference));
            $this->assertMatchesRegularExpression(self::OFFICIAL_PATTERN, $reference);
            $this->assertStringNotContainsString('-', $reference);

            if ($reference[4] === '0') {
                $leadingZero++;
            }

            $seen[$reference] = true;
        }

        // 熵的基本檢查:500 個樣本不該有碰撞(空間 10^15)。
        $this->assertCount(500, $seen);

        /*
         * ⛔ 前導 0 必須被保留:500 個樣本中第一位數為 0 的約佔 1/10,
         * 一個都沒有代表格式在某處被當成數值處理掉了。
         */
        $this->assertGreaterThan(0, $leadingZero, '前導 0 全部消失:reference 被當成數值處理了');
    }

    // ==================================== 2. handoff 送出的 MerchantTradeNo

    /** 合法 reference:form 的 `MerchantTradeNo` 原樣等於 reference 且符合規格。 */
    public function test_the_handoff_sends_the_reference_verbatim_and_spec_compliant(): void
    {
        $this->armEcpay();
        $attempt = $this->attempt(PaymentAttempt::newReference());

        $initiation = app(EcpayPaymentGateway::class)->initiate($attempt);

        $this->assertTrue($initiation->isFormPost());
        $this->assertSame($attempt->reference, $initiation->fields['MerchantTradeNo']);
        $this->assertMatchesRegularExpression('/\AIGNF[0-9]{15}\z/', $initiation->fields['MerchantTradeNo']);
        $this->assertSame(19, strlen($initiation->fields['MerchantTradeNo']));
    }

    /**
     * Owner 指定的商品名稱:`ItemName` 精確為 `行銷服務費`。
     *
     * ⛔ ItemName 與 TradeDesc 都不含客人的帳號、網址、方案或任何個資——
     * 這兩個欄位會出現在綠界端。
     */
    public function test_the_item_name_is_exactly_the_owner_specified_string(): void
    {
        $this->armEcpay();
        $attempt = $this->attempt(PaymentAttempt::newReference());

        $fields = app(EcpayPaymentGateway::class)->initiate($attempt)->fields;

        $this->assertSame('行銷服務費', $fields['ItemName']);
        // TradeDesc 維持既有固定安全字串,不擴張文案。
        $this->assertSame('IGLIKEFOLLOW social media service', $fields['TradeDesc']);

        foreach (['ItemName', 'TradeDesc'] as $key) {
            $this->assertStringNotContainsString('@', $fields[$key]);
            $this->assertStringNotContainsString('http', $fields[$key]);
        }
    }

    /**
     * ⛔ CheckMacValue 必須涵蓋 `MerchantTradeNo`。
     *
     * 驗兩件事:輸出的簽章可以用同一組欄位重算出來;而只改 MerchantTradeNo
     * 一個字元,簽章就對不上——證明它真的在簽章覆蓋範圍內,不是掛在外面。
     */
    public function test_the_checkmac_covers_the_merchant_trade_no(): void
    {
        $this->armEcpay();
        $attempt = $this->attempt(PaymentAttempt::newReference());

        $fields = app(EcpayPaymentGateway::class)->initiate($attempt)->fields;

        $mac = $fields['CheckMacValue'];
        $unsigned = $fields;
        unset($unsigned['CheckMacValue']);

        // trait 寫入的測試金鑰(明顯假值,非真實 credential)。
        $this->assertSame($mac, EcpayCheckMac::generate($unsigned, 'test-value-for-HashKey', 'test-value-for-HashIV'));

        $tampered = $unsigned;
        $tampered['MerchantTradeNo'] = substr_replace($fields['MerchantTradeNo'], 'X', -1);

        $this->assertNotSame($mac, EcpayCheckMac::generate($tampered, 'test-value-for-HashKey', 'test-value-for-HashIV'));
    }

    // ==================================== 3. legacy／不合法 reference fail closed

    /** @return array<string, array{string}> */
    public static function illegalReferenceProvider(): array
    {
        return [
            'legacy hyphen (staging 實際被拒的形狀)' => ['PAY-LEGACY000001'],
            'lowercase legacy hyphen' => ['PAY-abcdef123456'],
            'space' => ['PAY 123456789012'],
            'underscore' => ['PAY_123456789012'],
            'over 20 chars' => ['PAY0123456789012345678'],
            'empty' => [''],
            'unicode' => ['PAY一二三456789012'],
        ];
    }

    /**
     * ⛔ 不合法的 reference:0 綠界 form、簽章不產生,沿既有安全 action 把
     * attempt 收斂成 failed 並釋放 claim——客人可以立即用新 checkout 重試,
     * 新的 attempt 會拿到合法的新 reference。
     */
    #[DataProvider('illegalReferenceProvider')]
    public function test_an_illegal_reference_never_reaches_a_signed_form(string $reference): void
    {
        $this->armEcpay();
        $attempt = $this->attempt($reference);

        $initiation = app(EcpayPaymentGateway::class)->initiate($attempt);

        // ⛔ 沒有 form、沒有 endpoint、沒有任何已簽章欄位。
        $this->assertTrue($initiation->isFailed());
        $this->assertNull($initiation->endpoint);
        $this->assertSame([], $initiation->fields);

        // 既有安全收斂:failed＋completed,claim 已釋放,不卡單。
        $fresh = $attempt->fresh();
        $this->assertSame(PaymentStatus::Failed, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    /** gateway 的驗證與官方規格同一條線:合法值全收、非法值全拒。 */
    public function test_the_validator_matches_the_official_spec_exactly(): void
    {
        foreach (['A', 'abc123XYZ', str_repeat('A', 20), 'IGNF012345678901234', PaymentAttempt::newReference()] as $legal) {
            $this->assertTrue(EcpayPaymentGateway::isValidMerchantTradeNo($legal), $legal);
        }

        foreach (['PAY-LEGACY000001', str_repeat('A', 21), '', ' PAY123', "PAY123\n"] as $illegal) {
            $this->assertFalse(EcpayPaymentGateway::isValidMerchantTradeNo($illegal), $illegal);
        }
    }

    // ==================================== 4. 新格式 callback 精確 lookup

    /**
     * 新格式 reference 的 server-to-server callback:精確找到 attempt、
     * 驗簽通過、收斂為已付款——整條既有驗證鏈(MerchantID → 查找 → 簽章
     * → 金額 → RtnCode)原樣走完。
     */
    public function test_a_callback_with_the_new_reference_format_converges_the_attempt(): void
    {
        $this->armEcpay();
        $attempt = $this->attempt(PaymentAttempt::newReference());

        $payload = [
            'MerchantID' => '3000001',
            'MerchantTradeNo' => $attempt->reference,
            'RtnCode' => '1',
            'RtnMsg' => 'Succeeded',
            'TradeNo' => 'ECPAY-TXN-0001',
            'TradeAmt' => '590',
        ];
        $payload['CheckMacValue'] = EcpayCheckMac::generate($payload, 'test-value-for-HashKey', 'test-value-for-HashIV');

        $this->postJson('/payments/ecpay/callback', $payload)
            ->assertOk()
            ->assertSee('1|OK');

        $fresh = $attempt->fresh();
        $this->assertSame(PaymentStatus::Succeeded, $fresh->status);
        $this->assertSame('ECPAY-TXN-0001', $fresh->provider_reference);
    }
}
