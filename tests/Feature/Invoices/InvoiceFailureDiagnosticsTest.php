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
    public function test_a_merchant_mismatch_records_identity_without_the_value(): void
    {
        Http::fake([
            self::ISSUE => Http::response([
                'MerchantID' => '9999999',
                'TransCode' => 1,
                'Data' => 'x',
            ], 200),
            self::QUERY => Http::response($this->queryNotFound()),
        ]);

        $result = $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        $this->assertStringStartsWith('ISSUE_IDENTITY', (string) $result->code());
        $this->assertStringNotContainsString('9999999', (string) $result->code());
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

    /** 成功碼但缺欄位：固定 shape code。 */
    public function test_a_success_code_with_missing_fields_records_a_shape_code(): void
    {
        Http::fake([
            self::ISSUE => Http::response($this->reply([
                'RtnCode' => 1,
                'InvoiceNo' => 'AB12345678',
                // ⛔ 缺 RandomNumber 與 InvoiceDate。
            ])),
            self::QUERY => Http::response($this->queryNotFound()),
        ]);

        $result = $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        $this->assertStringStartsWith('ISSUE_SHAPE', (string) $result->code());
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
            'wrong merchant' => [['IIS_Mer_ID' => '9999999'], 'QUERY_IDENTITY'],
            'wrong relate number' => [['IIS_Relate_Number' => 'OTHER01'], 'QUERY_IDENTITY'],
            'not issued' => [['IIS_Issue_Status' => '0'], 'QUERY_STATUS'],
            'voided' => [['IIS_Invalid_Status' => '1'], 'QUERY_STATUS'],
            'missing number' => [['IIS_Number' => ''], 'QUERY_SHAPE'],
            'bad date' => [['IIS_Create_Date' => 'not-a-date'], 'QUERY_SHAPE'],
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
     * ⭐ 事故反證 1：Issue 成功 → `issued` 真的落盤。
     *
     * ⛔ 這個測試在修正前必定失敗。綠界 live 回應把 `RtnCode`／`TransCode`
     * 以**字串** `"1"` 送回（官方文件標為 Int），而舊版用嚴格 `!== 1` 比較，
     * 於是一個真正開立成功的回應被判成失敗——Owner 連續兩張 LINE Pay 訂單
     * 都出現「綠界端實際已開立、本站顯示開立失敗」。
     *
     * ⛔ 不只驗 DTO：必須走到 `invoices` 與 `invoice_attempts` 的實際落盤，
     * 因為事故正是發生在「解析成功 → 寫回」這整條路徑上。
     */
    public function test_a_live_shaped_string_success_persists_as_issued(): void
    {
        Http::fake([self::ISSUE => Http::response($this->reply([
            // ⭐ 貼近官方 live response：成功碼是字串。
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

    /** 全數字隨機碼以整數抵達時，⛔ 同樣不得因此被判成缺欄位。 */
    public function test_a_numeric_random_number_still_persists_as_issued(): void
    {
        Http::fake([self::ISSUE => Http::response($this->reply([
            'RtnCode' => '1',
            'InvoiceNo' => 'AB12345678',
            'InvoiceDate' => '2026-08-17 10:30:00',
            // ⭐ 官方型別是 String(4)，但全數字值可能以 int 抵達。
            'RandomNumber' => 1234,
        ], transCode: '1'))]);

        $invoice = $this->invoiceFor($this->paidOrder());
        app(IssueInvoice::class)->handle($invoice);

        $invoice = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame('1234', $invoice->random_code);
    }

    /**
     * ⭐ 事故反證 2：Issue 未收斂但 GetIssue 正面證明 → `issued` 落盤。
     *
     * 這是 Owner 那張「綠界端已開立、手動入口仍收不回來」的發票走的路徑；
     * GetIssue 的狀態欄位同樣可能以整數抵達。
     */
    public function test_a_positive_query_with_live_shaped_types_persists_as_issued(): void
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
                'IIS_Random_Number' => 1234,
                // ⭐ 官方型別是字串，但可能以整數抵達。
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
    public function test_the_identifier_normalizer_accepts_int_but_rejects_unsafe_types(): void
    {
        $this->assertSame('1234', EcpayScalar::identifier(1234));
        $this->assertSame('AB12345678', EcpayScalar::identifier('AB12345678'));
        $this->assertSame('0123', EcpayScalar::identifier('0123'), '⛔ 字串前導 0 必須保留。');

        foreach ([true, false, 1.0, 1.5, ['x'], null, '', '   '] as $unsafe) {
            $this->assertNull(EcpayScalar::identifier($unsafe));
        }
    }

    /** status：官方字串與等價整數都接受，⛔ bool／float 拒絕。 */
    public function test_the_status_normalizer_accepts_both_official_and_integer_forms(): void
    {
        $this->assertTrue(EcpayScalar::statusEquals('1', '1'));
        $this->assertTrue(EcpayScalar::statusEquals(1, '1'));
        $this->assertTrue(EcpayScalar::statusEquals('0', '0'));
        $this->assertTrue(EcpayScalar::statusEquals(0, '0'));

        $this->assertFalse(EcpayScalar::statusEquals(true, '1'));
        $this->assertFalse(EcpayScalar::statusEquals(1.0, '1'));
        $this->assertFalse(EcpayScalar::statusEquals('9', '1'));
        $this->assertFalse(EcpayScalar::statusEquals(null, '0'));
    }
}
