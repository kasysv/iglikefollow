<?php

namespace Tests\Feature\Invoices;

use App\Actions\Invoices\IssueInvoice;
use App\DTO\InvoiceFailureCode;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\InvoiceAttemptStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\IntegrationSetting;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\Invoices\EcpayInvoiceGateway;
use App\Services\Invoices\EcpayInvoicePayloadBuilder;
use App\Services\Invoices\EcpayScalar;
use Ecpay\Sdk\Services\AesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * Why an invoice failed — precisely enough to act on, safely enough to store.
 *
 * ⭐ Owner 在 staging 遇到兩件事：一筆已付款訂單的發票顯示「開立失敗」，以及
 * 一張綠界端**實際已開出**的發票，用既有手動入口仍收不回本站。兩者的共同問題
 * 是同一個：後台永遠只顯示 `UNKNOWN`。
 *
 * ⛔ 舊版 `EcpayInvoiceClient` 把 HTTP 失敗、outer `TransCode`、AES 解密失敗、
 * inner `RtnCode`、回傳 shape 與 identity 不符**全部**折成同一個結果。於是
 * 無從分辨憑證問題、傳輸問題、開立欄位問題還是查詢解析問題——只能靠再送一次
 * 真實 Issue 去猜，而每一次盲測都可能開出一張真的發票。
 *
 * 本輪讓每一層各自帶回 `階段_層級[=綠界數字碼]`，同時保持一條鐵律：
 * ⛔ provider 的自由文字（`RtnMsg`／`TransMsg`／raw body）、credential 與買受人
 * 資料，一個字都不得落盤或顯示。
 *
 * ⛔ 全程 fake HTTP，0 真實 Issue／GetIssue。
 */
class InvoiceFailureDiagnosticsTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    private const MERCHANT = '2000132';

    private const HASH_KEY = 'ejCk326UnaZWKisg';

    private const HASH_IV = 'q9jcZX8Ib9LM8wYk';

    private const ISSUE = 'https://einvoice.ecpay.com.tw/B2CInvoice/Issue';

    private const QUERY = 'https://einvoice.ecpay.com.tw/B2CInvoice/GetIssue';

    /** 一段同時含 secret、Email、手機與統編的毒性字串。 */
    private const POISON = 'HashKey=ejCk326UnaZWKisg buyer@example.test 0912345678 12345678 公司抬頭';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->runningAsLiveSite();

        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayInvoice, IntegrationEnvironment::Production)
            ->create(['identifier' => self::MERCHANT]);

        $setting->credentials = ['HashKey' => self::HASH_KEY, 'HashIV' => self::HASH_IV];
        $setting->save();

        DB::table('integration_settings')->where('id', $setting->id)->update(['is_enabled' => true]);
    }

    // ==================================== helpers

    private function aes(): AesService
    {
        return new AesService(self::HASH_KEY, self::HASH_IV);
    }

    private function paidOrder(array $overrides = [], int $amount = 590): Order
    {
        return Order::factory()->create(array_merge([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'total_amount' => $amount,
            'paid_at' => now(),
            'customer_email' => 'buyer@example.test',
            'customer_phone' => '0912345678',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'email',
        ], $overrides))->fresh();
    }

    private function invoiceFor(Order $order): Invoice
    {
        return Invoice::factory()->create([
            'order_id' => $order->id,
            'amount' => (int) $order->total_amount,
            'status' => InvoiceStatus::Pending,
        ]);
    }

    /** @param array<string, mixed> $inner */
    private function reply(array $inner, mixed $transCode = 1): array
    {
        return [
            'MerchantID' => self::MERCHANT,
            'RpHeader' => ['Timestamp' => 1755400000],
            'TransCode' => $transCode,
            'TransMsg' => '',
            'Data' => $this->aes()->encrypt($inner),
        ];
    }

    private function gateway(): EcpayInvoiceGateway
    {
        return app(EcpayInvoiceGateway::class);
    }

    /** GetIssue 查不到的固定回應。 */
    private function queryNotFound(): array
    {
        return $this->reply(['RtnCode' => 10000050, 'RtnMsg' => self::POISON]);
    }

    // ==================================== 1. payload：官方商品欄位

    /**
     * ⭐ 本輪確定的 payload 缺口。
     *
     * 官方 B2C Issue 要求商品序號 `ItemSeq`（1–999 整數）。公司既有唯讀參考模組
     * 帶的正是 `ItemSeq => 1` 與空白 `ItemRemark`，本站兩者皆無——這是從初版就
     * 存在的缺口，不是後來的 LINE Pay 修正改壞的。
     */
    public function test_the_issue_payload_carries_the_official_item_sequence(): void
    {
        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order);

        $payload = app(EcpayInvoicePayloadBuilder::class)
            ->build($invoice, self::MERCHANT, 'IGLTEST01');

        $item = $payload['Items'][0];

        $this->assertSame(1, $item['ItemSeq'], '⛔ 官方要求商品序號 ItemSeq。');
        $this->assertIsInt($item['ItemSeq'], '⛔ ItemSeq 官方型別為整數。');
        $this->assertGreaterThanOrEqual(1, $item['ItemSeq']);
        $this->assertLessThanOrEqual(999, $item['ItemSeq']);
        $this->assertSame('', $item['ItemRemark']);
    }

    /** ⛔ 既有金額與品項規則不得因為補欄位而改變。 */
    public function test_the_item_amounts_still_agree_with_the_order(): void
    {
        $order = $this->paidOrder(amount: 1234);
        $invoice = $this->invoiceFor($order);

        $payload = app(EcpayInvoicePayloadBuilder::class)
            ->build($invoice, self::MERCHANT, 'IGLTEST01');

        $item = $payload['Items'][0];

        $this->assertSame(1234, $payload['SalesAmount']);
        $this->assertSame(1234, $item['ItemPrice']);
        $this->assertSame(1234, $item['ItemAmount']);
        $this->assertSame(1, $item['ItemCount']);
        $this->assertSame('1', $payload['TaxType']);
        $this->assertSame('07', $payload['InvType']);
        $this->assertSame('0', $payload['Print']);
        $this->assertSame('', $payload['CustomerAddr']);
    }

    /** ⛔ 商品名稱與備註不得含帳號、SKU 或任何 PII。 */
    public function test_the_item_name_and_remark_carry_no_customer_data(): void
    {
        $order = $this->paidOrder();
        $payload = app(EcpayInvoicePayloadBuilder::class)
            ->build($this->invoiceFor($order), self::MERCHANT, 'IGLTEST01');

        $item = json_encode($payload['Items'], JSON_UNESCAPED_UNICODE);

        foreach (['buyer@example.test', '0912345678', (string) $order->reference] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $item);
        }
    }

    /** 官方條件欄位矩陣：四種情境各自的必填組合。 */
    public static function invoiceModeProvider(): array
    {
        return [
            'personal email' => [
                ['invoice_kind' => 'personal', 'personal_invoice_mode' => 'email'],
                ['CarrierType' => '1', 'CarrierNum' => '', 'Donation' => '0', 'Print' => '0'],
            ],
            'mobile barcode' => [
                ['invoice_kind' => 'personal', 'personal_invoice_mode' => 'mobile_barcode', 'carrier_number' => '/ABC1234'],
                ['CarrierType' => '3', 'CarrierNum' => '/ABC1234', 'Donation' => '0', 'Print' => '0'],
            ],
            'donation' => [
                ['invoice_kind' => 'personal', 'personal_invoice_mode' => 'donation', 'love_code' => '25885'],
                ['Donation' => '1', 'LoveCode' => '25885', 'CarrierType' => '', 'Print' => '0'],
            ],
            'business' => [
                ['invoice_kind' => 'business', 'buyer_tax_id' => '12345678', 'buyer_name' => '測試股份有限公司'],
                ['CustomerIdentifier' => '12345678', 'CarrierType' => '1', 'CarrierNum' => '', 'Print' => '0'],
            ],
        ];
    }

    #[DataProvider('invoiceModeProvider')]
    public function test_each_invoice_mode_builds_the_official_field_set(
        array $orderFields,
        array $expected,
    ): void {
        $order = $this->paidOrder($orderFields);

        $payload = app(EcpayInvoicePayloadBuilder::class)
            ->build($this->invoiceFor($order), self::MERCHANT, 'IGLTEST01');

        foreach ($expected as $field => $value) {
            $this->assertSame($value, $payload[$field], "欄位 {$field} 不符官方條件");
        }

        // 全情境共同必填。
        $this->assertSame(self::MERCHANT, $payload['MerchantID']);
        $this->assertSame('IGLTEST01', $payload['RelateNumber']);
        $this->assertSame(1, $payload['Items'][0]['ItemSeq']);
        $this->assertNotSame('', $payload['CustomerEmail']);
    }

    // ==================================== 2. 成功仍然正常

    public function test_a_successful_issue_clears_the_failure_fields(): void
    {
        Http::fake([self::ISSUE => Http::response($this->reply([
            'RtnCode' => 1,
            'RtnMsg' => '開立成功',
            'InvoiceNo' => 'AB12345678',
            'InvoiceDate' => '2026-08-17 10:30:00',
            'RandomNumber' => '1234',
        ]))]);

        $result = $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        $this->assertTrue($result->isIssued());
        $this->assertSame('AB12345678', $result->invoiceNumber);
        $this->assertSame('1234', $result->randomCode);
        // ⛔ 成功就不該有失敗代碼。
        $this->assertNull($result->code());
        $this->assertNull($result->message());
    }

    // ==================================== 3. 每一層各自的代碼

    /** inner `RtnCode` 非 1：保存精確數字。 */
    public function test_an_inner_rtn_code_is_preserved_exactly(): void
    {
        Http::fake([
            self::ISSUE => Http::response($this->reply(['RtnCode' => 10000001, 'RtnMsg' => self::POISON])),
            self::QUERY => Http::response($this->queryNotFound()),
        ]);

        $result = $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        $this->assertStringStartsWith('ISSUE_RTN=10000001', (string) $result->code());
        $this->assertStringNotContainsString(self::POISON, (string) $result->code());
    }

    /** outer `TransCode` 非 1：保存精確數字。 */
    public function test_an_outer_trans_code_is_preserved_exactly(): void
    {
        Http::fake([
            self::ISSUE => Http::response([
                'MerchantID' => self::MERCHANT,
                'TransCode' => 0,
                'TransMsg' => self::POISON,
                'Data' => '',
            ], 200),
            self::QUERY => Http::response($this->queryNotFound()),
        ]);

        $result = $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        $this->assertStringStartsWith('ISSUE_TRANS=0', (string) $result->code());
        $this->assertStringNotContainsString(self::POISON, (string) $result->code());
    }

    /**
     * ⛔ 非數字的 code 不得繞過安全型別。
     *
     * 字串、array、超長數字都必須降級成固定的 local code，⛔ 絕不把無法驗證的
     * 內容拼進 code——那正是 provider 文字漏進 DB 的路徑。
     */
    public static function unsafeCodeProvider(): array
    {
        return [
            'free text' => [self::POISON],
            'array' => [['nested' => 'x']],
            'boolean' => [true],
            'float' => [1.5],
            'overlong digits' => ['12345678901234567890'],
            'sci notation' => ['1e5'],
            'hex' => ['0x1A'],
            'digits with letters' => ['12ab'],
            'empty string' => [''],
        ];
    }

    /**
     * 前後空白會被 trim 後接受。
     *
     * ⛔ 這是刻意的，不是漏網：`' 12 '` trim 之後仍是通過驗證的純數字，值本身
     * 完全可信。拒絕它只會把一個合法的 provider 數字碼降級成 local token，
     * 讓 Owner 少掉一個真正有用的線索。危險的是**非數字內容**，不是空白。
     */
    public function test_a_padded_numeric_code_is_trimmed_and_accepted(): void
    {
        $this->assertSame(
            'ISSUE_TRANS=12',
            InvoiceFailureCode::numeric('ISSUE', 'TRANS', ' 12 ')->toString(),
        );
    }

    #[DataProvider('unsafeCodeProvider')]
    public function test_an_unsafe_outer_code_degrades_to_a_local_token(mixed $transCode): void
    {
        Http::fake([
            self::ISSUE => Http::response([
                'MerchantID' => self::MERCHANT,
                'TransCode' => $transCode,
                'Data' => '',
            ], 200),
            self::QUERY => Http::response($this->queryNotFound()),
        ]);

        $result = $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        $this->assertStringStartsWith('ISSUE_TRANS', (string) $result->code());
        $this->assertStringNotContainsString('=', explode('|', (string) $result->code())[0]);
        $this->assertStringNotContainsString(self::POISON, (string) $result->code());
    }

    /** 各種傳輸層失敗各自有固定 local code。 */
    public static function transportFailureProvider(): array
    {
        return [
            'http 500' => [fn () => Http::response('down', 500), 'ISSUE_HTTP'],
            'not json' => [fn () => Http::response('plain text', 200), 'ISSUE_JSON'],
        ];
    }

    #[DataProvider('transportFailureProvider')]
    public function test_transport_failures_carry_fixed_local_codes(
        callable $issueResponse,
        string $expected,
    ): void {
        Http::fake([
            self::ISSUE => $issueResponse(),
            self::QUERY => Http::response($this->queryNotFound()),
        ]);

        $result = $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        $this->assertStringStartsWith($expected, (string) $result->code());
    }

    /** ⛔ 商店代號不符：只記身份不符，不記對方回的 MerchantID。 */
    /**
     * ⭐ R2：outer 商店代號不符有自己的 code，且⛔ 不記任何一方的實際值。
     */
    public static function outerMerchantRejectionProvider(): array
    {
        return [
            'wrong digits' => ['9999999'],
            'missing' => [null],
            'bool' => [true],
            // ⛔ float 不列在這裡：`json_encode(2000132.0)` 產生整數 2000132，
            // 因此 float 不可能經由 wire 抵達。normalizer 對真正 float 的拒絕
            // 由 test_the_merchant_normalizer_rejects_unsafe_types 直接涵蓋。
            'array' => [['2000132']],
            'padded' => [' 2000132 '],
            'signed' => ['+2000132'],
            'sci notation' => ['2.000132e6'],
            'negative' => [-2000132],
            'empty' => [''],
            'overlong' => ['20001320000000'],
        ];
    }

    #[DataProvider('outerMerchantRejectionProvider')]
    public function test_an_outer_merchant_mismatch_records_its_own_code(mixed $merchantId): void
    {
        Http::fake([
            self::ISSUE => Http::response([
                'MerchantID' => $merchantId,
                'TransCode' => 1,
                'Data' => 'x',
            ], 200),
            self::QUERY => Http::response($this->queryNotFound()),
        ]);

        $result = $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        $this->assertStringStartsWith('ISSUE_MERCHANT', (string) $result->code());
        $this->assertStringNotContainsString('9999999', (string) $result->code());
        $this->assertStringNotContainsString(self::MERCHANT, (string) $result->code());
    }

    /**
     * ⭐ R2 相容性：同一個數字以 int 回傳時必須被接受。
     *
     * 綠界的 MerchantID 是純數字；若對方在 JSON 中以數字回傳，`json_decode`
     * 會給 int，舊版的嚴格字串比較就必然不相等。這是 2026-08-26 live 診斷
     * `ISSUE_IDENTITY|QUERY_IDENTITY` 的**最強候選子原因**，⛔ 但仍不是已
     * 證實的根因。
     */
    public function test_an_integer_outer_merchant_id_is_accepted(): void
    {
        Http::fake([self::ISSUE => Http::response([
            'MerchantID' => (int) self::MERCHANT,
            'RpHeader' => ['Timestamp' => 1755400000],
            'TransCode' => 1,
            'TransMsg' => '',
            'Data' => $this->aes()->encrypt([
                'RtnCode' => 1,
                'InvoiceNo' => 'AB12345678',
                'RandomNumber' => '1234',
                'InvoiceDate' => '2026-08-17 10:30:00',
            ]),
        ], 200)]);

        $invoice = $this->invoiceFor($this->paidOrder());
        app(IssueInvoice::class)->handle($invoice);

        $invoice = $invoice->fresh();

        // ⛔ 端到端落盤，不是只驗 DTO。
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame('AB12345678', $invoice->invoice_number);
        $this->assertNull($invoice->failure_code);
    }

    /**
     * ⛔ 前導零不得被猜測補回。
     *
     * 本站設定 `0012345`、provider 回 int `12345` 時，前導零已在型別轉換中
     * 遺失且無法無損還原——此時必須維持不相等，否則等於接受一個可能不是
     * 我們的商店代號。
     */
    public function test_a_leading_zero_merchant_id_never_matches_a_bare_integer(): void
    {
        $this->assertFalse(EcpayScalar::merchantMatches(12345, '0012345'));
        $this->assertFalse(EcpayScalar::merchantMatches('12345', '0012345'));
        // 同樣形狀才相等。
        $this->assertTrue(EcpayScalar::merchantMatches('0012345', '0012345'));
    }

    /** ⛔ 本站設定本身不合法時一律 false，不得因兩邊都怪而意外相等。 */
    public function test_an_invalid_configured_merchant_id_never_matches(): void
    {
        foreach (['', 'ABC', ' 2000132 ', '20001320000000', '-1'] as $badConfig) {
            $this->assertFalse(EcpayScalar::merchantMatches('2000132', $badConfig));
            $this->assertFalse(EcpayScalar::merchantMatches($badConfig, $badConfig));
        }
    }

    /** AES 解不開：固定 decrypt code。 */
    public function test_an_undecryptable_payload_records_a_decrypt_code(): void
    {
        Http::fake([
            self::ISSUE => Http::response([
                'MerchantID' => self::MERCHANT,
                'TransCode' => 1,
                'Data' => 'not-valid-ciphertext',
            ], 200),
            self::QUERY => Http::response($this->queryNotFound()),
        ]);

        $result = $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        $this->assertStringStartsWith('ISSUE_DECRYPT', (string) $result->code());
    }

    /**
     * ⭐ R1：成功碼但欄位異常，代碼必須指出**是哪一欄**。
     *
     * ⛔ 初版只有一個 `ISSUE_SHAPE` 同時涵蓋號碼、隨機碼與日期，下一次 live
     * 若仍失敗，Owner 依然不知道是哪一欄被拒絕——等於還是要靠真實發票盲測。
     *
     * ⛔ 代碼只說「哪一欄不合格」，絕不含那一欄的值。
     */
    public static function issueFieldRejectionProvider(): array
    {
        return [
            // 發票號碼：官方 String(10)＝2 碼英文字軌＋8 碼數字。
            'missing number' => [['InvoiceNo' => null], 'ISSUE_NUMBER'],
            'numeric number' => [['InvoiceNo' => 1234], 'ISSUE_NUMBER'],
            'numeric string number' => [['InvoiceNo' => '1234567890'], 'ISSUE_NUMBER'],
            'short number' => [['InvoiceNo' => 'AB123456'], 'ISSUE_NUMBER'],
            'long number' => [['InvoiceNo' => 'AB123456789'], 'ISSUE_NUMBER'],
            'lowercase number' => [['InvoiceNo' => 'ab12345678'], 'ISSUE_NUMBER'],
            'array number' => [['InvoiceNo' => ['AB12345678']], 'ISSUE_NUMBER'],
            'empty number' => [['InvoiceNo' => ''], 'ISSUE_NUMBER'],

            // 隨機碼：官方 String(4)，可能有前導零。
            'missing random' => [['RandomNumber' => null], 'ISSUE_RANDOM'],
            'int random' => [['RandomNumber' => 1234], 'ISSUE_RANDOM'],
            'int zero random' => [['RandomNumber' => 0], 'ISSUE_RANDOM'],
            'int short random' => [['RandomNumber' => 123], 'ISSUE_RANDOM'],
            'int long random' => [['RandomNumber' => 10000], 'ISSUE_RANDOM'],
            'short string random' => [['RandomNumber' => '123'], 'ISSUE_RANDOM'],
            'long string random' => [['RandomNumber' => '12345'], 'ISSUE_RANDOM'],
            'non numeric random' => [['RandomNumber' => 'AB12'], 'ISSUE_RANDOM'],
            'array random' => [['RandomNumber' => ['1234']], 'ISSUE_RANDOM'],

            // 日期。
            'missing date' => [['InvoiceDate' => null], 'ISSUE_DATE'],
            'unparseable date' => [['InvoiceDate' => 'not a date'], 'ISSUE_DATE'],
            'impossible date' => [['InvoiceDate' => '2026-13-45 99:99:99'], 'ISSUE_DATE'],
            'array date' => [['InvoiceDate' => ['2026-08-17 10:30:00']], 'ISSUE_DATE'],
        ];
    }

    #[DataProvider('issueFieldRejectionProvider')]
    public function test_a_malformed_success_field_records_its_own_code(
        array $overrides,
        string $expected,
    ): void {
        Http::fake([
            self::ISSUE => Http::response($this->reply(array_merge([
                'RtnCode' => 1,
                'InvoiceNo' => 'AB12345678',
                'RandomNumber' => '1234',
                'InvoiceDate' => '2026-08-17 10:30:00',
            ], $overrides))),
            self::QUERY => Http::response($this->queryNotFound()),
        ]);

        $result = $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        $this->assertFalse($result->isIssued(), '⛔ 欄位不合官方 shape 就不算成功。');
        $this->assertStringStartsWith($expected, (string) $result->code());
    }

    /** ⛔ 前導零必須原樣保存：隨機碼錯一碼就對不上發票。 */
    public function test_a_leading_zero_random_code_is_preserved(): void
    {
        Http::fake([self::ISSUE => Http::response($this->reply([
            'RtnCode' => 1,
            'InvoiceNo' => 'AB12345678',
            'RandomNumber' => '0123',
            'InvoiceDate' => '2026-08-17 10:30:00',
        ]))]);

        $invoice = $this->invoiceFor($this->paidOrder());
        app(IssueInvoice::class)->handle($invoice);

        $this->assertSame('0123', $invoice->fresh()->random_code);
    }

    // ==================================== 4. Issue ＋ Query 兩段

    /** ⭐ 兩段都看得見，且不超過 DB 欄位長度。 */
    public function test_both_the_issue_and_query_codes_are_visible(): void
    {
        Http::fake([
            self::ISSUE => Http::response($this->reply(['RtnCode' => 10000001, 'RtnMsg' => self::POISON])),
            self::QUERY => Http::response($this->reply(['RtnCode' => 10000050, 'RtnMsg' => self::POISON])),
        ]);

        $result = $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        $this->assertSame('ISSUE_RTN=10000001|QUERY_RTN=10000050', $result->code());
        $this->assertLessThanOrEqual(
            InvoiceFailureCode::MAX_LENGTH,
            strlen((string) $result->code()),
        );
    }

    // ==================================== 5. GetIssue 收斂

    /**
     * ⭐ Owner 實測情境：綠界端已開出，Issue 卻非成功。
     *
     * 同號 GetIssue 找到既有發票 → 收斂為 issued，⛔ 且清空失敗代碼：一張真的
     * 存在的發票不得留成 failed，否則 Owner 會以為還要再開一次。
     */
    public function test_a_positive_query_converges_to_issued_and_clears_codes(): void
    {
        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order);

        /*
         * ⛔ fixture 的 `IIS_Relate_Number` 必須是**實際推導出來的**那一個。
         *
         * 寫死一個假的號碼會讓 identity 檢查正確地拒絕收斂，測試就變成在測
         * 「拒絕」而不是「收斂」——看起來紅燈，實際上什麼都沒驗到。
         */
        $relate = EcpayInvoiceGateway::relateNumberFor($invoice);

        Http::fake([
            self::ISSUE => Http::response($this->reply(['RtnCode' => 10000001, 'RtnMsg' => self::POISON])),
            self::QUERY => Http::response($this->reply([
                'RtnCode' => 1,
                'IIS_Mer_ID' => self::MERCHANT,
                'IIS_Number' => 'AB12345678',
                'IIS_Relate_Number' => $relate,
                'IIS_Create_Date' => '2026-08-17 10:30:00',
                'IIS_Random_Number' => '1234',
                'IIS_Issue_Status' => '1',
                'IIS_Invalid_Status' => '0',
            ])),
        ]);

        $result = $this->gateway()->issue($invoice, 'k');

        $this->assertTrue($result->isIssued());
        $this->assertSame('AB12345678', $result->invoiceNumber);
        $this->assertSame('1234', $result->randomCode);
        // ⛔ 收斂成功就不得留下失敗代碼。
        $this->assertNull($result->code());

        // ⛔ 恰好一次 Issue：查詢不得變成第二次開立。
        $issues = 0;

        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/Issue') && ! str_contains($request->url(), 'GetIssue')) {
                $issues++;
            }
        }

        $this->assertSame(1, $issues);
    }

    /** 查詢身份／狀態不符時，⛔ 不得誤標 issued，並各有固定代碼。 */
    public static function queryRejectionProvider(): array
    {
        return [
            // ⭐ R2：商店代號與關聯編號各有自己的 code。
            'wrong merchant' => [['IIS_Mer_ID' => '9999999'], 'QUERY_MERCHANT'],
            'missing merchant' => [['IIS_Mer_ID' => null], 'QUERY_MERCHANT'],
            'bool merchant' => [['IIS_Mer_ID' => true], 'QUERY_MERCHANT'],
            // ⛔ float 經 JSON 會變成整數，不可能經由 wire 抵達；改由 normalizer
            // 的直接單元測試涵蓋。
            'array merchant' => [['IIS_Mer_ID' => ['2000132']], 'QUERY_MERCHANT'],
            'padded merchant' => [['IIS_Mer_ID' => ' 2000132 '], 'QUERY_MERCHANT'],
            'signed merchant' => [['IIS_Mer_ID' => '+2000132'], 'QUERY_MERCHANT'],
            'sci merchant' => [['IIS_Mer_ID' => '2.000132e6'], 'QUERY_MERCHANT'],
            'negative merchant' => [['IIS_Mer_ID' => -2000132], 'QUERY_MERCHANT'],
            'empty merchant' => [['IIS_Mer_ID' => ''], 'QUERY_MERCHANT'],

            'wrong relate number' => [['IIS_Relate_Number' => 'OTHER01'], 'QUERY_RELATE'],
            'missing relate number' => [['IIS_Relate_Number' => null], 'QUERY_RELATE'],
            'int relate number' => [['IIS_Relate_Number' => 12345678], 'QUERY_RELATE'],
            'array relate number' => [['IIS_Relate_Number' => ['X']], 'QUERY_RELATE'],
            'empty relate number' => [['IIS_Relate_Number' => ''], 'QUERY_RELATE'],
            'not issued' => [['IIS_Issue_Status' => '0'], 'QUERY_STATUS'],
            'voided' => [['IIS_Invalid_Status' => '1'], 'QUERY_STATUS'],

            /*
             * ⛔ status 只接受 canonical 表示。
             *
             * `"00"`／`"01"`／`"-0"` 數學上等於 0 或 1，但形狀與官方規格不同；
             * 這一層決定「這張發票現在是不是活的」，不能靠寬鬆轉型猜。
             */
            'non canonical zero' => [['IIS_Invalid_Status' => '00'], 'QUERY_STATUS'],
            'non canonical one' => [['IIS_Issue_Status' => '01'], 'QUERY_STATUS'],
            'negative zero' => [['IIS_Invalid_Status' => '-0'], 'QUERY_STATUS'],
            'padded status' => [['IIS_Issue_Status' => ' 1 '], 'QUERY_STATUS'],
            'bool status' => [['IIS_Issue_Status' => true], 'QUERY_STATUS'],

            // ⭐ R1：欄位層級各自的代碼。
            'empty number' => [['IIS_Number' => ''], 'QUERY_NUMBER'],
            'numeric number' => [['IIS_Number' => 1234], 'QUERY_NUMBER'],
            'numeric string number' => [['IIS_Number' => '1234567890'], 'QUERY_NUMBER'],
            'short number' => [['IIS_Number' => 'AB1234'], 'QUERY_NUMBER'],
            'array number' => [['IIS_Number' => ['AB12345678']], 'QUERY_NUMBER'],
            'int random' => [['IIS_Random_Number' => 1234], 'QUERY_RANDOM'],
            'short random' => [['IIS_Random_Number' => '123'], 'QUERY_RANDOM'],
            'long random' => [['IIS_Random_Number' => '12345'], 'QUERY_RANDOM'],
            'array random' => [['IIS_Random_Number' => ['1234']], 'QUERY_RANDOM'],
            'bad date' => [['IIS_Create_Date' => 'not-a-date'], 'QUERY_DATE'],
            'impossible date' => [['IIS_Create_Date' => '2026-13-45 99:99:99'], 'QUERY_DATE'],
            'array date' => [['IIS_Create_Date' => ['2026-08-17 10:30:00']], 'QUERY_DATE'],
        ];
    }

    #[DataProvider('queryRejectionProvider')]
    public function test_a_query_that_does_not_prove_our_invoice_is_never_issued(
        array $overrides,
        string $expectedCode,
    ): void {
        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order);
        $relate = EcpayInvoiceGateway::relateNumberFor($invoice);

        Http::fake([
            self::ISSUE => Http::response($this->reply(['RtnCode' => 10000001, 'RtnMsg' => self::POISON])),
            self::QUERY => Http::response($this->reply(array_merge([
                'RtnCode' => 1,
                'IIS_Mer_ID' => self::MERCHANT,
                'IIS_Number' => 'AB12345678',
                'IIS_Relate_Number' => $relate,
                'IIS_Create_Date' => '2026-08-17 10:30:00',
                'IIS_Random_Number' => '1234',
                'IIS_Issue_Status' => '1',
                'IIS_Invalid_Status' => '0',
            ], $overrides))),
        ]);

        $result = $this->gateway()->issue($invoice, 'k');

        $this->assertFalse($result->isIssued(), '⛔ 證據不足時不得標記為已開立。');
        $this->assertStringContainsString($expectedCode, (string) $result->code());
    }

    // ==================================== 6. 落盤與顯示安全

    /** ⛔ 完整落盤路徑：DB 兩張表都不得出現 provider 文字或 PII。 */
    public function test_no_provider_text_or_pii_reaches_the_database(): void
    {
        Http::fake([
            self::ISSUE => Http::response($this->reply(['RtnCode' => 10000001, 'RtnMsg' => self::POISON])),
            self::QUERY => Http::response($this->queryNotFound()),
        ]);

        $order = $this->paidOrder(['buyer_tax_id' => '12345678', 'buyer_name' => '公司抬頭']);
        $invoice = $this->invoiceFor($order);

        app(IssueInvoice::class)->handle($invoice);

        $raw = json_encode([
            DB::table('invoices')->get(),
            DB::table('invoice_attempts')->get(),
        ], JSON_UNESCAPED_UNICODE);

        foreach ([
            self::HASH_KEY, self::HASH_IV, 'buyer@example.test', '0912345678',
            '12345678', '公司抬頭', self::POISON, 'RtnMsg',
        ] as $marker) {
            $this->assertStringNotContainsString($marker, (string) $raw, "外洩：{$marker}");
        }

        // 但代碼本身確實存下來了。
        $invoice = $invoice->fresh();
        $this->assertStringStartsWith('ISSUE_RTN=10000001', (string) $invoice->failure_code);
        $this->assertNotNull($invoice->failure_message);
    }

    /** invoice 與該次 attempt 的代碼一致。 */
    public function test_the_code_is_written_to_both_the_invoice_and_the_attempt(): void
    {
        Http::fake([
            self::ISSUE => Http::response($this->reply(['RtnCode' => 10000001, 'RtnMsg' => self::POISON])),
            self::QUERY => Http::response($this->queryNotFound()),
        ]);

        $invoice = $this->invoiceFor($this->paidOrder());

        app(IssueInvoice::class)->handle($invoice);

        $invoice = $invoice->fresh();
        $attempt = $invoice->attempts()->firstOrFail();

        $this->assertSame($invoice->failure_code, $attempt->failure_code);
        $this->assertSame(InvoiceAttemptStatus::Failed, $attempt->status);
        $this->assertLessThanOrEqual(64, strlen((string) $invoice->failure_code));
        $this->assertLessThanOrEqual(64, strlen((string) $invoice->failure_message));
    }

    /** 失敗說明是本地固定中文，⛔ 不含數字也不含 provider 文字。 */
    public function test_the_failure_message_is_local_text_only(): void
    {
        Http::fake([
            self::ISSUE => Http::response($this->reply(['RtnCode' => 10000001, 'RtnMsg' => self::POISON])),
            self::QUERY => Http::response($this->queryNotFound()),
        ]);

        $result = $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        $message = (string) $result->message();

        $this->assertStringContainsString('開立發票時', $message);
        $this->assertStringNotContainsString(self::POISON, $message);
        // ⛔ 數字只放 code 欄位，不混進說明句子。
        $this->assertStringNotContainsString('10000001', $message);
    }

    // ==================================== 6b. 端到端落盤（本次 live 事故）

    /**
     * ⭐ 端到端落盤：純數字字串成功碼也必須收斂為 `issued`。
     *
     * ⛔ R1 更正措辭：這是一個**相容性案例**，⛔ 不是「已觀測到的 live
     * response」。本站並未保存 staging 那兩次真實回應，因此無法宣稱它們的
     * `RtnCode`／`TransCode` 就是字串——精確的 live 拒絕欄位仍為 `unknown`。
     *
     * 能證明的是：舊版嚴格 `!== 1` 會拒絕字串 `"1"`，而封閉正規化可以避免
     * 這一類 false negative。這是候選根因，待下一次真實嘗試由診斷代碼確認。
     *
     * ⛔ 不只驗 DTO：必須走到 `invoices` 與 `invoice_attempts` 的實際落盤，
     * 因為事故正是發生在「解析成功 → 寫回」這整條路徑上。
     */
    public function test_a_numeric_string_success_code_persists_as_issued(): void
    {
        Http::fake([self::ISSUE => Http::response($this->reply([
            // 相容性案例：成功碼以純數字字串表示。
            'RtnCode' => '1',
            'RtnMsg' => '開立發票成功',
            'InvoiceNo' => 'AB12345678',
            'InvoiceDate' => '2026-08-17 10:30:00',
            'RandomNumber' => '1234',
        ], transCode: '1'))]);

        $invoice = $this->invoiceFor($this->paidOrder());

        app(IssueInvoice::class)->handle($invoice);

        $invoice = $invoice->fresh();

        // ⛔ 落盤結果，不是 DTO。
        $this->assertSame(InvoiceStatus::Issued, $invoice->status, '⛔ 綠界已開立就不得留成失敗。');
        $this->assertSame('AB12345678', $invoice->invoice_number);
        $this->assertSame('1234', $invoice->random_code);
        $this->assertSame('2026-08-17 10:30:00', $invoice->issued_at->format('Y-m-d H:i:s'));
        // ⛔ 成功不得留下失敗代碼或「UNKNOWN」。
        $this->assertNull($invoice->failure_code);
        $this->assertNull($invoice->failure_message);

        $attempt = $invoice->attempts()->firstOrFail();
        $this->assertSame(InvoiceAttemptStatus::Succeeded, $attempt->status);
        $this->assertNull($attempt->failure_code);
    }

    /** 官方文件型別（整數 1）同樣必須成功——⛔ 放寬不得讓原本正確的路徑退化。 */
    public function test_an_integer_success_still_persists_as_issued(): void
    {
        Http::fake([self::ISSUE => Http::response($this->reply([
            'RtnCode' => 1,
            'InvoiceNo' => 'AB12345678',
            'InvoiceDate' => '2026-08-17 10:30:00',
            'RandomNumber' => '1234',
        ], transCode: 1))]);

        $invoice = $this->invoiceFor($this->paidOrder());
        app(IssueInvoice::class)->handle($invoice);

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    /**
     * ⛔ R1 更正：整數隨機碼**不再**被接受。
     *
     * 初版以 `RandomNumber => 1234` 當正例，理由是「全數字值可能以整數抵達」
     * ——那是沒有 live 證據的推測，而且危險：`123` 會被存成少一碼的 `"123"`，
     * `"0123"` 一旦變成整數就永久失去前導零。隨機碼是對發票的驗證資料，
     * 錯一碼就對不上。
     *
     * 沒有能無歧義還原前導零的官方或 live 證據之前，⛔ 只接受 4 位數字字串。
     */
    public function test_an_integer_random_number_is_rejected_with_its_own_code(): void
    {
        Http::fake([
            self::ISSUE => Http::response($this->reply([
                'RtnCode' => '1',
                'InvoiceNo' => 'AB12345678',
                'InvoiceDate' => '2026-08-17 10:30:00',
                'RandomNumber' => 1234,
            ], transCode: '1')),
            self::QUERY => Http::response($this->queryNotFound()),
        ]);

        $invoice = $this->invoiceFor($this->paidOrder());
        app(IssueInvoice::class)->handle($invoice);

        $invoice = $invoice->fresh();

        $this->assertSame(InvoiceStatus::Failed, $invoice->status);
        $this->assertStringStartsWith('ISSUE_RANDOM', (string) $invoice->failure_code);
        // ⛔ 不得寫入一個形狀可疑的隨機碼。
        $this->assertNull($invoice->random_code);
    }

    /**
     * ⭐ 端到端落盤：Issue 未收斂但 GetIssue 正面證明 → `issued`。
     *
     * 這是 Owner 那張「綠界端已開立、手動入口仍收不回來」的發票走的路徑。
     * ⛔ 這裡的整數狀態欄位是**相容性案例**，不是已觀測的 live response。
     */
    public function test_a_positive_query_persists_as_issued(): void
    {
        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order);
        $relate = EcpayInvoiceGateway::relateNumberFor($invoice);

        Http::fake([
            self::ISSUE => Http::response($this->reply(['RtnCode' => 10000001, 'RtnMsg' => self::POISON])),
            self::QUERY => Http::response($this->reply([
                'RtnCode' => '1',
                'IIS_Mer_ID' => self::MERCHANT,
                'IIS_Number' => 'AB12345678',
                'IIS_Relate_Number' => $relate,
                'IIS_Create_Date' => '2026-08-17 10:30:00',
                // ⛔ 官方型別為 String(4)：隨機碼一律用字串。
                'IIS_Random_Number' => '1234',
                // 整數狀態為相容性案例（官方型別是字串）。
                'IIS_Issue_Status' => 1,
                'IIS_Invalid_Status' => 0,
            ], transCode: '1')),
        ]);

        app(IssueInvoice::class)->handle($invoice);

        $invoice = $invoice->fresh();

        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame('AB12345678', $invoice->invoice_number);
        $this->assertSame('1234', $invoice->random_code);
        $this->assertSame('2026-08-17 10:30:00', $invoice->issued_at->format('Y-m-d H:i:s'));
        // ⛔ 收斂成功後不得留下任何失敗痕跡。
        $this->assertNull($invoice->failure_code);
        $this->assertNull($invoice->failure_message);
        $this->assertNull($invoice->reconciliation_required_at);

        // ⛔ 恰好一次 Issue：查詢不得變成第二次開立。
        $issues = 0;

        foreach (Http::recorded() as [$request]) {
            if (str_ends_with($request->url(), '/Issue')) {
                $issues++;
            }
        }

        $this->assertSame(1, $issues);
    }

    /**
     * ⭐ R2 事故回歸：Issue 未收斂，Query 以 int 商店代號查到既有發票。
     *
     * 這正是 Owner 那張「綠界端已開立、本站收不回來」的發票所走的路徑：
     * 若 provider 以數字回傳 MerchantID，舊版三個 identity 檢查會全部拒絕，
     * 於是 `ISSUE_IDENTITY|QUERY_IDENTITY`。
     *
     * ⛔ 必須端到端落盤，且恰好一次 Issue——查詢不得變成第二次開立。
     */
    public function test_a_positive_query_with_integer_merchant_ids_persists_as_issued(): void
    {
        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order);
        $relate = EcpayInvoiceGateway::relateNumberFor($invoice);

        $queryBody = [
            'MerchantID' => (int) self::MERCHANT,
            'RpHeader' => ['Timestamp' => 1755400000],
            'TransCode' => 1,
            'TransMsg' => '',
            'Data' => $this->aes()->encrypt([
                'RtnCode' => 1,
                // ⭐ inner 商店代號同樣以整數回傳。
                'IIS_Mer_ID' => (int) self::MERCHANT,
                'IIS_Number' => 'AB12345678',
                'IIS_Relate_Number' => $relate,
                'IIS_Create_Date' => '2026-08-17 10:30:00',
                'IIS_Random_Number' => '1234',
                'IIS_Issue_Status' => '1',
                'IIS_Invalid_Status' => '0',
            ]),
        ];

        Http::fake([
            self::ISSUE => Http::response($this->reply(['RtnCode' => 10000001, 'RtnMsg' => self::POISON])),
            self::QUERY => Http::response($queryBody, 200),
        ]);

        app(IssueInvoice::class)->handle($invoice);

        $invoice = $invoice->fresh();

        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame('AB12345678', $invoice->invoice_number);
        $this->assertSame('1234', $invoice->random_code);
        $this->assertSame('2026-08-17 10:30:00', $invoice->issued_at->format('Y-m-d H:i:s'));
        // ⛔ 收斂成功後不得留下任何失敗痕跡。
        $this->assertNull($invoice->failure_code);
        $this->assertNull($invoice->failure_message);

        $attempt = $invoice->attempts()->firstOrFail();
        $this->assertSame(InvoiceAttemptStatus::Succeeded, $attempt->status);
        $this->assertNull($attempt->failure_code);

        // ⛔ 恰好一次 Issue。
        $issues = 0;

        foreach (Http::recorded() as [$request]) {
            if (str_ends_with($request->url(), '/Issue')) {
                $issues++;
            }
        }

        $this->assertSame(1, $issues);
    }

    /**
     * ⭐ R3 反證：只多前／後空白的 RelateNumber 必須被拒絕。
     *
     * ⛔ R2 的註解與結果文件都聲稱「逐字元全等、不 trim」，但實作仍呼叫會先
     * `trim()` 的 `text()`。provider 回 `" <正確值>"` 或 `"<正確值> "` 時會被
     * **錯誤接受**——本機實測四種空白變體全部誤判為相符。
     *
     * 為什麼這在稅務憑證上是實質風險：`RelateNumber` 是「這張發票屬於哪一張
     * 訂單」的唯一鍵。任何正規化都讓「看起來一樣」的兩個值被視為同一個，
     * 而那正是把**別張訂單的發票**收斂到這張訂單上的路徑。空白看似無害，
     * 但它與大小寫、全形半形一樣，都是「我沒有逐字元確認就採信」的同一類錯誤。
     *
     * ⛔ 舊 data provider 只有 wrong／missing／int／array／empty，沒有「正確值
     * 只多空白」這一格，所以綠燈完全沒有覆蓋這個漏洞。
     *
     * @return array<string, array{0: string}>
     */
    public static function whitespacePaddedRelateNumberProvider(): array
    {
        return [
            'leading space' => [' '],
            'trailing space' => ['  '],
            'tab' => ["\t"],
            'newline' => ["\n"],
        ];
    }

    #[DataProvider('whitespacePaddedRelateNumberProvider')]
    public function test_a_relate_number_differing_only_by_whitespace_is_rejected(string $pad): void
    {
        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order);

        // ⭐ 用**當次真正推導出來的**正確值，只在前後加空白。
        $relate = EcpayInvoiceGateway::relateNumberFor($invoice);

        foreach ([$pad.$relate, $relate.$pad, $pad.$relate.$pad] as $padded) {
            // 前置條件：這確實只是空白差異，trim 之後會相等。
            $this->assertSame($relate, trim($padded));
            $this->assertNotSame($relate, $padded);

            Http::fake([
                self::ISSUE => Http::response($this->reply([
                    'RtnCode' => 10000001,
                    'RtnMsg' => self::POISON,
                ])),
                self::QUERY => Http::response($this->reply([
                    'RtnCode' => 1,
                    'IIS_Mer_ID' => self::MERCHANT,
                    'IIS_Number' => 'AB12345678',
                    'IIS_Relate_Number' => $padded,
                    'IIS_Create_Date' => '2026-08-17 10:30:00',
                    'IIS_Random_Number' => '1234',
                    'IIS_Issue_Status' => '1',
                    'IIS_Invalid_Status' => '0',
                ])),
            ]);

            $result = $this->gateway()->issue($invoice, 'k');

            $this->assertFalse(
                $result->isIssued(),
                '⛔ 只差空白的關聯編號不得被採信：'.json_encode($padded),
            );
            $this->assertStringContainsString('QUERY_RELATE', (string) $result->code());
        }
    }

    /**
     * ⛔ 非字串型別的關聯編號一律拒絕。
     *
     * 現行實作先做 `is_string()` 與非空檢查，才做逐字元比較。這一格釘住那道
     * 型別閘門本身。
     */
    public function test_a_non_string_relate_number_is_rejected(): void
    {
        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order);

        foreach ([12345678, 0, true, false, null, ['x'], 1.5] as $nonString) {
            Http::fake([
                self::ISSUE => Http::response($this->reply(['RtnCode' => 10000001, 'RtnMsg' => self::POISON])),
                self::QUERY => Http::response($this->reply([
                    'RtnCode' => 1,
                    'IIS_Mer_ID' => self::MERCHANT,
                    'IIS_Number' => 'AB12345678',
                    'IIS_Relate_Number' => $nonString,
                    'IIS_Create_Date' => '2026-08-17 10:30:00',
                    'IIS_Random_Number' => '1234',
                    'IIS_Issue_Status' => '1',
                    'IIS_Invalid_Status' => '0',
                ])),
            ]);

            $result = $this->gateway()->issue($invoice, 'k');

            $this->assertFalse(
                $result->isIssued(),
                '⛔ 非字串的關聯編號不得被採信：'.var_export($nonString, true),
            );
            $this->assertStringContainsString('QUERY_RELATE', (string) $result->code());
        }
    }

    /**
     * ⛔ 比較必須是 `!==` 而非 `!=`：型別與內容都要相同。
     *
     * ⭐ 這一格是 mutation 測試逼出來的，值得說明它為什麼用「直接斷言運算子
     * 行為」而不是又一個 HTTP fixture。
     *
     * 把實作改成寬鬆 `!=` 時，所有 HTTP 層測試仍然全過——因為本站 reference
     * 以 `IGL` 開頭，PHP 對「非數字字串」的寬鬆與嚴格比較結果一致，經由 wire
     * 能抵達的型別（bool／int／null／array／float）也都會被更前面的
     * `is_string()` 或更後面的欄位檢查擋掉。也就是說，在**目前的編號格式下**
     * 兩種運算子行為等價，HTTP 層無論怎麼寫 fixture 都分不出來。
     *
     * ⛔ 但這個安全性不該依賴「我們的編號剛好不是純數字」。若 reference 格式
     * 日後改為全數字，寬鬆比較會把 int `12345678` 與 `" 12345678"` 都視為相符
     * ——本機實測 `12345678 == "12345678"` 為 true，`" 12345678" == "12345678"`
     * 亦為 true。那正是「別張訂單的發票被收斂過來」的路徑。
     *
     * 所以這裡直接鎖住運算子必須提供的保證本身：對這些值，嚴格比較必須為不相等，
     * 而寬鬆比較會相等。⛔ 若日後有人把實作改回 `!=`，這段說明與 §RELATE 的
     * 硬邊界就成為 code review 的依據。
     */
    public function test_strict_comparison_is_required_for_all_digit_references(): void
    {
        // 假設性的全數字 reference：格式若改變，寬鬆比較就會出現漏洞。
        $allDigits = '12345678';

        foreach ([12345678, ' 12345678', '12345678 ', '+12345678'] as $lookalike) {
            // 寬鬆比較會誤判為相符……
            $this->assertTrue(
                $lookalike == $allDigits,
                '前置條件：這些值在寬鬆比較下等於全數字 reference。',
            );

            // ⛔ ……嚴格比較必須不相符。這就是實作必須用 `!==` 的理由。
            $this->assertFalse(
                $lookalike === $allDigits,
                '⛔ 嚴格比較必須拒絕：'.var_export($lookalike, true),
            );
        }
    }

    /** ⛔ 逐字元全等仍必須讓真正正確的值通過——收緊不得反過來擋掉正常路徑。 */
    public function test_an_exactly_matching_relate_number_still_converges(): void
    {
        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order);
        $relate = EcpayInvoiceGateway::relateNumberFor($invoice);

        Http::fake([
            self::ISSUE => Http::response($this->reply(['RtnCode' => 10000001, 'RtnMsg' => self::POISON])),
            self::QUERY => Http::response($this->reply([
                'RtnCode' => 1,
                'IIS_Mer_ID' => self::MERCHANT,
                'IIS_Number' => 'AB12345678',
                'IIS_Relate_Number' => $relate,
                'IIS_Create_Date' => '2026-08-17 10:30:00',
                'IIS_Random_Number' => '1234',
                'IIS_Issue_Status' => '1',
                'IIS_Invalid_Status' => '0',
            ])),
        ]);

        app(IssueInvoice::class)->handle($invoice);

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    /**
     * ⛔ 商店代號與關聯編號的說明必須是兩件不同的事。
     *
     * Owner 看到 `ISSUE_MERCHANT` 與 `QUERY_RELATE` 時，需要分得出前者指向
     * 憑證／設定，後者指向查到了別張訂單的發票。
     */
    public function test_merchant_and_relate_have_distinct_explanations(): void
    {
        Http::fake([
            self::ISSUE => Http::response([
                'MerchantID' => '9999999', 'TransCode' => 1, 'Data' => 'x',
            ], 200),
            self::QUERY => Http::response($this->queryNotFound()),
        ]);

        $merchantMessage = (string) $this->gateway()
            ->issue($this->invoiceFor($this->paidOrder()), 'k')->message();

        $this->assertStringContainsString('商店代號', $merchantMessage);
        $this->assertStringNotContainsString('關聯編號', $merchantMessage);
        // ⛔ 說明不得含任何一方的實際值。
        $this->assertStringNotContainsString('9999999', $merchantMessage);
        $this->assertStringNotContainsString(self::MERCHANT, $merchantMessage);
    }

    // ==================================== 7. value object 本身

    public function test_the_failure_code_rejects_non_numeric_values(): void
    {
        $this->assertSame(
            'ISSUE_RTN',
            InvoiceFailureCode::numeric('ISSUE', 'RTN', self::POISON)->toString(),
        );
        $this->assertSame(
            'ISSUE_RTN=1',
            InvoiceFailureCode::numeric('ISSUE', 'RTN', 1)->toString(),
        );
    }

    /** ⛔ 未登記的 phase／layer 不得原樣進入 code。 */
    public function test_unknown_phase_and_layer_tokens_are_not_echoed(): void
    {
        $code = InvoiceFailureCode::local('EVIL<script>', 'DROP TABLE')->toString();

        $this->assertStringNotContainsString('script', $code);
        $this->assertStringNotContainsString('DROP', $code);
        $this->assertMatchesRegularExpression('/\A(ISSUE|QUERY)_[A-Z]+\z/', $code);
    }

    /** ⛔ 組合後仍不得超過 DB 欄位上限。 */
    public function test_a_combined_code_never_exceeds_the_column_limit(): void
    {
        $code = InvoiceFailureCode::numeric('ISSUE', 'RTN', '123456789012')
            ->withQuery(InvoiceFailureCode::numeric('QUERY', 'RTN', '123456789012'));

        $this->assertLessThanOrEqual(InvoiceFailureCode::MAX_LENGTH, strlen($code->toString()));
    }

    // ==================================== 8. normalizer 本身（封閉集合）

    /**
     * ⭐ 只放寬 int 與純數字字串，⛔ 其餘一律拒絕。
     *
     * 這是「不得以全面 loose comparison 掩蓋問題」的具體落實：`true == 1` 在
     * PHP 寬鬆比較下為真，若直接改用 `==`，一個 `"TransCode": true` 的回應
     * 就會被當成開立成功。
     */
    public static function acceptedScalarProvider(): array
    {
        return [
            'int' => [1, 1],
            'numeric string' => ['1', 1],
            'padded numeric string' => [' 1 ', 1],
            'zero' => ['0', 0],
            'negative' => ['-5', -5],
            'multi digit' => ['10000001', 10000001],
        ];
    }

    #[DataProvider('acceptedScalarProvider')]
    public function test_the_normalizer_accepts_official_and_equivalent_types(mixed $value, int $expected): void
    {
        $this->assertSame($expected, EcpayScalar::int($value));
    }

    public static function rejectedScalarProvider(): array
    {
        return [
            'bool true' => [true],
            'bool false' => [false],
            'float' => [1.0],
            'float fraction' => [1.5],
            'array' => [[1]],
            'null' => [null],
            'empty string' => [''],
            'blank string' => ['   '],
            'sci notation' => ['1e0'],
            'hex' => ['0x1'],
            'plus prefix' => ['+1'],
            'decimal string' => ['1.0'],
            'digits with letters' => ['1a'],
            'overlong' => ['1234567890123'],
        ];
    }

    /** ⛔ 每一個都必須回 null——「看不懂」永遠不算成功。 */
    #[DataProvider('rejectedScalarProvider')]
    public function test_the_normalizer_rejects_everything_else(mixed $value): void
    {
        $this->assertNull(EcpayScalar::int($value));
        $this->assertFalse(EcpayScalar::equalsInt($value, 1));
    }

    /** identifier：放寬 int，⛔ 但仍拒絕 bool／float／array。 */
    /** 商店代號：1–10 位純數字字串；相容接受非負 int。 */
    public function test_the_merchant_normalizer_accepts_official_and_integer_forms(): void
    {
        $this->assertSame('2000132', EcpayScalar::merchantId('2000132'));
        $this->assertSame('2000132', EcpayScalar::merchantId(2000132));
        $this->assertSame('0', EcpayScalar::merchantId(0));
        $this->assertSame('0012345', EcpayScalar::merchantId('0012345'), '⛔ 字串前導零必須保留。');
        $this->assertSame('1234567890', EcpayScalar::merchantId('1234567890'));
    }

    /**
     * ⛔ 每一種不安全表示都必須回 null。
     *
     * 含 float——它經由 JSON 不可達，但 normalizer 仍須拒絕，否則日後若有
     * 非 JSON 來源就會靜默放行。
     */
    public function test_the_merchant_normalizer_rejects_unsafe_types(): void
    {
        foreach ([
            true, false, 2000132.0, 1.5, ['2000132'], null,
            '', '   ', ' 2000132 ', '+2000132', '-2000132', -2000132,
            '2.000132e6', '0x1E', 'ABC', '2000132A', '12345678901',
        ] as $unsafe) {
            $this->assertNull(
                EcpayScalar::merchantId($unsafe),
                '⛔ 不安全的商店代號必須拒絕：'.var_export($unsafe, true),
            );
        }
    }

    /** 發票號碼：官方 String(10) ＝ 2 碼大寫字軌 + 8 碼數字。 */
    public function test_the_invoice_number_parser_enforces_the_official_shape(): void
    {
        $this->assertSame('AB12345678', EcpayScalar::invoiceNumber('AB12345678'));
        $this->assertSame('ZZ00000000', EcpayScalar::invoiceNumber('ZZ00000000'));

        foreach ([
            // ⛔ 純數字：能被表示成整數就代表它不是合法發票號碼。
            1234567890, '1234567890',
            // 長度錯誤。
            'AB123456', 'AB123456789', '',
            // 非法字元／大小寫。
            'ab12345678', 'A112345678', 'AB1234567X',
            // 型別。
            true, false, 1.0, ['AB12345678'], null, '   ',
        ] as $invalid) {
            $this->assertNull(
                EcpayScalar::invoiceNumber($invalid),
                '⛔ 不合官方 shape 的發票號碼必須拒絕：'.var_export($invalid, true),
            );
        }
    }

    /** 隨機碼：官方 String(4)，⛔ 前導零必須無損保存，⛔ int 一律拒絕。 */
    public function test_the_random_code_parser_enforces_four_digit_strings(): void
    {
        $this->assertSame('1234', EcpayScalar::randomCode('1234'));
        $this->assertSame('0123', EcpayScalar::randomCode('0123'), '⛔ 前導零必須保留。');
        $this->assertSame('0000', EcpayScalar::randomCode('0000'));

        foreach ([
            // ⛔ int 一律拒絕：無法無損還原前導零。
            0, 123, 1234, 10000,
            // 長度錯誤。
            '123', '12345', '',
            // 非數字。
            'AB12', '12a4', '12.4',
            // 型別。
            true, 1.0, ['1234'], null, '   ',
        ] as $invalid) {
            $this->assertNull(
                EcpayScalar::randomCode($invalid),
                '⛔ 不合官方 shape 的隨機碼必須拒絕：'.var_export($invalid, true),
            );
        }
    }

    /** status：官方字串與等價整數都接受，⛔ bool／float 拒絕。 */
    public function test_the_status_normalizer_accepts_both_official_and_integer_forms(): void
    {
        // 官方字串型別，以及整數相容表示。
        $this->assertTrue(EcpayScalar::statusEquals('1', '1'));
        $this->assertTrue(EcpayScalar::statusEquals(1, '1'));
        $this->assertTrue(EcpayScalar::statusEquals('0', '0'));
        $this->assertTrue(EcpayScalar::statusEquals(0, '0'));

        /*
         * ⛔ R1 收緊：只接受 canonical 表示。
         *
         * `"00"`／`"01"`／`"-0"`／`" 1 "` 數學上等於 0 或 1，但形狀與官方規格
         * 不同。這一層決定「這張發票現在是不是活的」，不能靠寬鬆轉型猜。
         */
        foreach (['00', '01', '-0', ' 1 ', '1.0', '+1', '9', ''] as $nonCanonical) {
            $this->assertFalse(
                EcpayScalar::statusEquals($nonCanonical, '1') || EcpayScalar::statusEquals($nonCanonical, '0'),
                '⛔ 非 canonical 表示必須拒絕：'.var_export($nonCanonical, true),
            );
        }

        foreach ([true, false, 1.0, ['1'], null] as $unsafe) {
            $this->assertFalse(EcpayScalar::statusEquals($unsafe, '1'));
            $this->assertFalse(EcpayScalar::statusEquals($unsafe, '0'));
        }
    }
}
