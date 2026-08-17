<?php

namespace Tests\Feature;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\InvoiceFailureReason;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\IntegrationSetting;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\Invoices\EcpayInvoiceGateway;
use App\Services\Invoices\EcpayInvoicePayloadBuilder;
use Ecpay\Sdk\Services\AesService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issuing a real tax document through ECPay's stage environment.
 *
 * ⛔ Every response here is a fixture and nothing reaches the network. That
 * means these tests say what *we* do with an answer, not that the live sandbox
 * works — which needs credentials and is recorded as NOT VERIFIED.
 *
 * The rule shaping most of it: issuing an invoice twice is far worse than
 * issuing it late. A duplicate is a genuine document at the tax authority that
 * someone has to void, so every unclear outcome stops and waits.
 *
 * ⛔ The keys below are invented for this file. No company credential, and no
 * real customer data, appears anywhere in this repository.
 */
class EcpayInvoiceAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const MERCHANT = '2000132';

    private const HASH_KEY = 'ejCk326UnaZWKisg';

    private const HASH_IV = 'q9jcZX8Ib9LM8wYk';

    private const ISSUE = 'https://einvoice-stage.ecpay.com.tw/B2CInvoice/Issue';

    private const QUERY = 'https://einvoice-stage.ecpay.com.tw/B2CInvoice/GetIssue';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('integrations.invoice.sandbox_enabled', true);

        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayInvoice, IntegrationEnvironment::Sandbox)
            ->create(['identifier' => self::MERCHANT]);

        $setting->credentials = ['HashKey' => self::HASH_KEY, 'HashIV' => self::HASH_IV];
        $setting->save();

        DB::table('integration_settings')->where('id', $setting->id)->update(['is_enabled' => true]);
    }

    private function aes(): AesService
    {
        return new AesService(self::HASH_KEY, self::HASH_IV);
    }

    /** @param array<string, mixed> $overrides */
    private function paidOrder(array $overrides = [], int $amount = 590): Order
    {
        return Order::factory()->create(array_merge([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'total_amount' => $amount,
            'paid_at' => now(),
            'customer_email' => 'invoice-test@example.test',
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

    private function gateway(): EcpayInvoiceGateway
    {
        return app(EcpayInvoiceGateway::class);
    }

    /** An encrypted ECPay reply, built the way they would build it. */
    private function reply(array $inner, mixed $transCode = 1, ?string $merchantId = null): array
    {
        return [
            'MerchantID' => $merchantId ?? self::MERCHANT,
            'RpHeader' => ['Timestamp' => 1755400000],
            'TransCode' => $transCode,
            'TransMsg' => '',
            'Data' => $this->aes()->encrypt(json_encode($inner, JSON_UNESCAPED_UNICODE)),
        ];
    }

    private function successInner(array $overrides = []): array
    {
        return array_merge([
            'RtnCode' => 1,
            'RtnMsg' => '開立發票成功',
            'InvoiceNo' => 'AB12345678',
            'InvoiceDate' => '2026-08-17 10:30:00',
            'RandomNumber' => '1234',
        ], $overrides);
    }

    private function fakeIssue(array $body, int $status = 200): void
    {
        Http::fake([self::ISSUE => Http::response($body, $status)]);
    }

    // ==================================== 1. 官方 AES 固定向量

    public function test_the_aes_service_round_trips(): void
    {
        $plain = json_encode(['MerchantID' => self::MERCHANT, 'RelateNumber' => 'IGLTEST01']);

        $cipher = $this->aes()->encrypt($plain);

        $this->assertNotSame($plain, $cipher);
        $this->assertSame($plain, $this->aes()->decrypt($cipher));
    }

    public function test_the_cipher_is_deterministic_for_the_same_input(): void
    {
        // ⛔ AES-128-CBC with a fixed IV：同一份輸入必須得到同一份密文，
        // 否則測試無法固定向量，也代表 IV 不是我們以為的那個。
        $plain = 'IGLIKEFOLLOW-B2-VECTOR';

        $this->assertSame($this->aes()->encrypt($plain), $this->aes()->encrypt($plain));
    }

    public function test_a_wrong_key_cannot_decrypt(): void
    {
        $cipher = $this->aes()->encrypt('secret payload');

        $wrong = new AesService('0000000000000000', self::HASH_IV);

        // 解出來的內容不可能等於原文。
        $decrypted = null;

        try {
            $decrypted = $wrong->decrypt($cipher);
        } catch (\Throwable) {
            $decrypted = null;
        }

        $this->assertNotSame('secret payload', $decrypted);
    }

    public function test_a_tampered_cipher_does_not_yield_the_original(): void
    {
        $cipher = $this->aes()->encrypt('secret payload');
        $tampered = substr($cipher, 0, -4).'AAAA';

        $decrypted = null;

        try {
            $decrypted = $this->aes()->decrypt($tampered);
        } catch (\Throwable) {
            $decrypted = null;
        }

        $this->assertNotSame('secret payload', $decrypted);
    }

    // ==================================== 2. 四種 checkout mapping

    private function build(Order $order): array
    {
        return (new EcpayInvoicePayloadBuilder)
            ->build($this->invoiceFor($order), self::MERCHANT, 'IGLTEST01');
    }

    public function test_personal_email_carrier_mapping(): void
    {
        $payload = $this->build($this->paidOrder());

        $this->assertSame('', $payload['CustomerIdentifier']);
        $this->assertSame('', $payload['CustomerName']);
        $this->assertSame('0', $payload['Print']);
        $this->assertSame('0', $payload['Donation']);
        $this->assertSame('', $payload['LoveCode']);
        $this->assertSame('1', $payload['CarrierType']);
        $this->assertSame('', $payload['CarrierNum']);
    }

    public function test_personal_mobile_barcode_mapping(): void
    {
        $payload = $this->build($this->paidOrder([
            'personal_invoice_mode' => 'mobile_barcode',
            'carrier_number' => '/ABC1234',
        ]));

        $this->assertSame('3', $payload['CarrierType']);
        $this->assertSame('/ABC1234', $payload['CarrierNum']);
        $this->assertSame('0', $payload['Donation']);
        $this->assertSame('0', $payload['Print']);
    }

    public function test_personal_donation_mapping(): void
    {
        $payload = $this->build($this->paidOrder([
            'personal_invoice_mode' => 'donation',
            'love_code' => '5678',
        ]));

        $this->assertSame('1', $payload['Donation']);
        $this->assertSame('5678', $payload['LoveCode']);
        $this->assertSame('', $payload['CarrierType']);
        $this->assertSame('0', $payload['Print']);
    }

    /**
     * ⛔ 公司發票也是無紙化。
     *
     * CMS 舊版對統編發票用 `Print=1` 並填入地址。依 D-019，本站不提供紙本、
     * 不郵寄，也**沒有收過地址**——可列印的發票等於承諾一件做不到的事。
     */
    public function test_business_mapping_is_still_paperless(): void
    {
        $payload = $this->build($this->paidOrder([
            'invoice_kind' => 'business',
            'personal_invoice_mode' => null,
            'buyer_tax_id' => '12345678',
            'buyer_name' => '測試股份有限公司',
        ]));

        $this->assertSame('12345678', $payload['CustomerIdentifier']);
        $this->assertSame('測試股份有限公司', $payload['CustomerName']);
        $this->assertSame('0', $payload['Print']);
        $this->assertSame('1', $payload['CarrierType']);
        $this->assertSame('', $payload['CarrierNum']);
    }

    public function test_no_address_is_ever_sent(): void
    {
        foreach ([
            $this->paidOrder(),
            $this->paidOrder(['personal_invoice_mode' => 'mobile_barcode', 'carrier_number' => '/ABC1234']),
            $this->paidOrder(['personal_invoice_mode' => 'donation', 'love_code' => '5678']),
            $this->paidOrder([
                'invoice_kind' => 'business', 'personal_invoice_mode' => null,
                'buyer_tax_id' => '12345678', 'buyer_name' => '測試公司',
            ]),
        ] as $order) {
            $payload = $this->build($order);

            // ⛔ 不收、不造、不傳地址。
            $this->assertSame('', $payload['CustomerAddr']);
            // 手機固定空白：checkout 已必填 Email。
            $this->assertSame('', $payload['CustomerPhone']);
        }
    }

    public function test_the_item_never_carries_customer_data(): void
    {
        $order = $this->paidOrder();
        $payload = $this->build($order);

        $item = $payload['Items'][0];

        $this->assertSame('行銷廣告費用', $item['ItemName']);
        $this->assertSame('式', $item['ItemWord']);
        $this->assertSame(1, $item['ItemCount']);

        // ⛔ 品名不得帶社群帳號、SKU 或任何可識別的內容。
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        foreach (['instagram', 'example_account', 'ig-followers'] as $marker) {
            $this->assertStringNotContainsStringIgnoringCase($marker, $encoded);
        }
    }

    // ==================================== 3. 金額一致

    public function test_the_amounts_all_agree(): void
    {
        $payload = $this->build($this->paidOrder(amount: 1234));

        $this->assertSame(1234, $payload['SalesAmount']);
        $this->assertSame(1234, $payload['Items'][0]['ItemPrice']);
        $this->assertSame(1234, $payload['Items'][0]['ItemAmount']);
    }

    /**
     * ⛔ 0 與負數的發票根本存不進資料庫（M3B-A 的 constraint 已擋下），
     * 所以這裡驗證的是「那道防線仍然有效」，而不是 adapter 會處理它們——
     * adapter 永遠不會拿到這種列。
     */
    public static function unpayableAmountProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-100],
        ];
    }

    #[DataProvider('unpayableAmountProvider')]
    public function test_an_invalid_amount_cannot_even_be_stored(int $amount): void
    {
        $order = $this->paidOrder(amount: 590);
        $invoice = $this->invoiceFor($order);

        $this->expectException(QueryException::class);

        DB::table('invoices')->where('id', $invoice->id)->update(['amount' => $amount]);
    }

    public function test_an_amount_mismatch_never_reaches_the_provider(): void
    {
        Http::fake();

        $order = $this->paidOrder(amount: 590);
        $invoice = $this->invoiceFor($order);
        // 發票 1234、訂單 590：兩者都是合法金額，但彼此不符。
        DB::table('invoices')->where('id', $invoice->id)->update(['amount' => 1234]);

        $this->assertTrue($this->gateway()->issue($invoice->fresh(), 'k')->isFailed());
        Http::assertNothingSent();
    }

    public function test_a_non_twd_invoice_cannot_even_be_stored(): void
    {
        $invoice = $this->invoiceFor($this->paidOrder());

        // ⛔ DB constraint 先擋下；adapter 不會拿到非 TWD 的發票。
        $this->expectException(QueryException::class);

        DB::table('invoices')->where('id', $invoice->id)->update(['currency' => 'USD']);
    }

    public static function incompletePayloadProvider(): array
    {
        return [
            'business without tax id' => [[
                'invoice_kind' => 'business', 'personal_invoice_mode' => null,
                'buyer_tax_id' => null, 'buyer_name' => '測試公司',
            ]],
            'business without name' => [[
                'invoice_kind' => 'business', 'personal_invoice_mode' => null,
                'buyer_tax_id' => '12345678', 'buyer_name' => null,
            ]],
            'unknown personal mode' => [['personal_invoice_mode' => 'carrier-pigeon']],
            'bad barcode' => [['personal_invoice_mode' => 'mobile_barcode', 'carrier_number' => 'NOPE']],
            'bad love code' => [['personal_invoice_mode' => 'donation', 'love_code' => 'abc']],
        ];
    }

    /** ⛔ 資料不完整就不送出：對方那邊什麼都不會發生。 */
    #[DataProvider('incompletePayloadProvider')]
    public function test_an_incomplete_invoice_fails_before_any_http(array $overrides): void
    {
        Http::fake();

        $invoice = $this->invoiceFor($this->paidOrder($overrides));

        $this->assertTrue($this->gateway()->issue($invoice, 'k')->isFailed());
        Http::assertNothingSent();
    }

    // ==================================== 4. RelateNumber 穩定且唯一

    public function test_the_relate_number_is_stable(): void
    {
        $invoice = $this->invoiceFor($this->paidOrder());

        $first = EcpayInvoiceGateway::relateNumberFor($invoice);

        // ⛔ 重算必須得到同一個值：換號等於讓同一張訂單開出第二張發票。
        $this->assertSame($first, EcpayInvoiceGateway::relateNumberFor($invoice->fresh()));
    }

    public function test_the_relate_number_is_alphanumeric_and_short(): void
    {
        $number = EcpayInvoiceGateway::relateNumberFor($this->invoiceFor($this->paidOrder()));

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $number);
        $this->assertLessThanOrEqual(30, strlen($number));
        $this->assertNotSame('', $number);
    }

    public function test_different_orders_get_different_relate_numbers(): void
    {
        $a = EcpayInvoiceGateway::relateNumberFor($this->invoiceFor($this->paidOrder()));
        $b = EcpayInvoiceGateway::relateNumberFor($this->invoiceFor($this->paidOrder()));

        $this->assertNotSame($a, $b);
    }

    public function test_the_relate_number_is_sent_and_stored(): void
    {
        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order);
        $expected = EcpayInvoiceGateway::relateNumberFor($invoice);

        $this->fakeIssue($this->reply($this->successInner()));

        $result = $this->gateway()->issue($invoice, 'k');

        $this->assertTrue($result->isIssued());
        // ⛔ provider reference 就是 RelateNumber：重送與查詢都靠它對回來。
        $this->assertSame($expected, $result->providerReference);
    }

    // ==================================== 5. 安全邊界

    public function test_a_disabled_flag_sends_nothing(): void
    {
        Http::fake();
        config()->set('integrations.invoice.sandbox_enabled', false);

        $this->assertTrue($this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k')->isFailed());
        Http::assertNothingSent();
    }

    public function test_production_sends_nothing(): void
    {
        Http::fake();
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertTrue($this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k')->isFailed());
        Http::assertNothingSent();
    }

    public function test_a_disabled_setting_sends_nothing(): void
    {
        Http::fake();
        DB::table('integration_settings')->update(['is_enabled' => false]);

        $this->assertTrue($this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k')->isFailed());
        Http::assertNothingSent();
    }

    public static function rejectedEndpointProvider(): array
    {
        return [
            'blank' => [''],
            'production host' => ['https://einvoice.ecpay.com.tw/B2CInvoice/Issue'],
            'lookalike host' => ['https://einvoice-stage.ecpay.com.tw.evil.example.com/B2CInvoice/Issue'],
            'arbitrary host' => ['https://evil.example.com/B2CInvoice/Issue'],
            'non https' => ['http://einvoice-stage.ecpay.com.tw/B2CInvoice/Issue'],
        ];
    }

    /** ⛔ 端點只能是版本控制中的 stage 主機。 */
    #[DataProvider('rejectedEndpointProvider')]
    public function test_a_non_allowlisted_endpoint_sends_nothing(string $endpoint): void
    {
        Http::fake();
        config()->set('integrations.endpoints.ecpay_invoice.sandbox', $endpoint);

        $this->assertTrue($this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k')->isFailed());
        Http::assertNothingSent();
    }

    // ==================================== 6. request envelope

    public function test_the_request_envelope_is_correct(): void
    {
        $this->fakeIssue($this->reply($this->successInner()));

        $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['MerchantID'] === self::MERCHANT
                && $body['RqHeader']['Revision'] === '3.0.0'
                && is_int($body['RqHeader']['Timestamp'])
                && is_string($body['Data'])
                && $body['Data'] !== '';
        });
    }

    public function test_the_request_body_carries_no_plaintext_personal_data(): void
    {
        $this->fakeIssue($this->reply($this->successInner()));

        $order = $this->paidOrder([
            'invoice_kind' => 'business', 'personal_invoice_mode' => null,
            'buyer_tax_id' => '12345678', 'buyer_name' => '祕密股份有限公司',
            'customer_email' => 'secret-buyer@example.test',
        ]);

        $this->gateway()->issue($this->invoiceFor($order), 'k');

        Http::assertSent(function ($request) {
            $raw = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            // ⛔ 個資只存在於加密後的 Data 中，envelope 不得有明文。
            foreach (['secret-buyer@example.test', '12345678', '祕密股份有限公司', self::HASH_KEY] as $marker) {
                if (str_contains($raw, $marker)) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_the_encrypted_data_actually_contains_the_payload(): void
    {
        $this->fakeIssue($this->reply($this->successInner()));

        $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        Http::assertSent(function ($request) {
            $decrypted = json_decode($this->aes()->decrypt($request->data()['Data']), true);

            return is_array($decrypted)
                && $decrypted['SalesAmount'] === 590
                && $decrypted['Print'] === '0';
        });
    }

    // ==================================== 7. strict success

    public function test_a_valid_success_is_recorded(): void
    {
        $this->fakeIssue($this->reply($this->successInner()));

        $result = $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        $this->assertTrue($result->isIssued());
        $this->assertSame('AB12345678', $result->invoiceNumber);
        $this->assertSame('1234', $result->randomCode);
        // provider 自己的開立時間，以台北時間解析。
        $this->assertNotNull($result->issuedAt);
        $this->assertSame('2026-08-17 10:30:00', $result->issuedAt->format('Y-m-d H:i:s'));
    }

    public function test_the_slash_date_format_is_accepted(): void
    {
        $this->fakeIssue($this->reply($this->successInner(['InvoiceDate' => '2026/08/17 10:30:00'])));

        $result = $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        $this->assertTrue($result->isIssued());
        $this->assertSame('2026-08-17 10:30:00', $result->issuedAt->format('Y-m-d H:i:s'));
    }

    /**
     * ⛔ `1.0` 不在清單中：JSON 編碼會把它變成整數 `1`，
     * 所以它**不可能**以 float 的形式從線路上抵達。放進來只是在測一個
     * 現實中不存在的情況。
     */
    public static function looseSuccessCodeProvider(): array
    {
        return [
            'string one' => ['1'],
            'bool true' => [true],
            'array' => [[1]],
            'null' => [null],
        ];
    }

    /** ⛔ 只有整數 1 算成功：字串 "1"、true、1.0 都不是。 */
    #[DataProvider('looseSuccessCodeProvider')]
    public function test_a_loose_inner_code_is_not_success(mixed $code): void
    {
        Http::fake([
            self::ISSUE => Http::response($this->reply($this->successInner(['RtnCode' => $code]))),
            self::QUERY => Http::response(['MerchantID' => self::MERCHANT, 'TransCode' => 0], 200),
        ]);

        $this->assertFalse($this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k')->isIssued());
    }

    #[DataProvider('looseSuccessCodeProvider')]
    public function test_a_loose_outer_trans_code_is_not_success(mixed $code): void
    {
        Http::fake([
            self::ISSUE => Http::response($this->reply($this->successInner(), transCode: $code)),
            self::QUERY => Http::response(['MerchantID' => self::MERCHANT, 'TransCode' => 0], 200),
        ]);

        $this->assertFalse($this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k')->isIssued());
    }

    public static function malformedSuccessFieldProvider(): array
    {
        return [
            'array invoice number' => [['InvoiceNo' => ['AB1']]],
            'bool invoice number' => [['InvoiceNo' => true]],
            'float invoice number' => [['InvoiceNo' => 1.5]],
            'empty invoice number' => [['InvoiceNo' => '']],
            'array random number' => [['RandomNumber' => ['1234']]],
            'empty random number' => [['RandomNumber' => '']],
            'array date' => [['InvoiceDate' => ['2026-08-17']]],
            'empty date' => [['InvoiceDate' => '']],
        ];
    }

    /** ⛔ 成功碼卻缺欄位或型別不對：不得記為已開立。 */
    #[DataProvider('malformedSuccessFieldProvider')]
    public function test_a_malformed_success_field_is_not_issued(array $override): void
    {
        Http::fake([
            self::ISSUE => Http::response($this->reply($this->successInner($override))),
            self::QUERY => Http::response(['MerchantID' => self::MERCHANT, 'TransCode' => 0], 200),
        ]);

        $result = $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        $this->assertFalse($result->isIssued());
        // ⛔ 讀不懂 ≠ 沒開出：進人工對帳。
        $this->assertTrue($result->isAmbiguous());
    }

    public function test_a_reply_from_another_merchant_is_not_trusted(): void
    {
        Http::fake([
            self::ISSUE => Http::response($this->reply($this->successInner(), merchantId: '9999999')),
            self::QUERY => Http::response(['MerchantID' => self::MERCHANT, 'TransCode' => 0], 200),
        ]);

        $this->assertFalse($this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k')->isIssued());
    }

    // ==================================== 8. 結果不明與唯讀查詢

    public static function uncertainIssueProvider(): array
    {
        return [
            'timeout' => ['timeout'],
            'http 500' => ['http500'],
            'malformed json' => ['malformed'],
            'bad cipher' => ['badcipher'],
            'unknown code' => ['unknown'],
        ];
    }

    #[DataProvider('uncertainIssueProvider')]
    public function test_an_unclear_issue_makes_exactly_one_query(string $kind): void
    {
        Http::fake([
            self::ISSUE => match ($kind) {
                'timeout' => fn () => throw new ConnectionException('timeout'),
                'http500' => Http::response($this->reply($this->successInner()), 500),
                'malformed' => Http::response('not json at all', 200),
                'badcipher' => Http::response([
                    'MerchantID' => self::MERCHANT, 'TransCode' => 1, 'Data' => 'not-a-valid-cipher',
                ], 200),
                'unknown' => Http::response($this->reply(['RtnCode' => 9999, 'RtnMsg' => 'unknown'])),
            },
            // 查詢查不到。
            self::QUERY => Http::response(['MerchantID' => self::MERCHANT, 'TransCode' => 0], 200),
        ]);

        $result = $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        // ⛔ 查不到不等於可以重開。
        $this->assertTrue($result->isAmbiguous());

        /*
         * ⛔ Issue 至多送出一次。
         *
         * 用「≤ 1」而不是「= 1」：連線直接丟例外時 Http::recorded() 不會留下
         * 紀錄，所以逾時情境是 0。真正要防的是「送出第二次」——那才會開出
         * 第二張發票。
         */
        $issues = 0;
        foreach (Http::recorded() as [$request]) {
            if ($request->url() === self::ISSUE) {
                $issues++;
            }
        }
        $this->assertLessThanOrEqual(1, $issues, 'Issue 不得送出第二次');
    }

    public function test_a_positive_query_converges_to_issued(): void
    {
        Http::fake([
            self::ISSUE => fn () => throw new ConnectionException('timeout'),
            // 查詢證明發票其實已經開出來了。
            self::QUERY => Http::response($this->reply($this->successInner())),
        ]);

        $order = $this->paidOrder();
        $invoice = $this->invoiceFor($order);
        $expected = EcpayInvoiceGateway::relateNumberFor($invoice);

        $result = $this->gateway()->issue($invoice, 'k');

        $this->assertTrue($result->isIssued());
        $this->assertSame('AB12345678', $result->invoiceNumber);
        // ⛔ 用同一個 RelateNumber 收斂。
        $this->assertSame($expected, $result->providerReference);
    }

    public function test_the_query_uses_the_same_relate_number(): void
    {
        Http::fake([
            self::ISSUE => fn () => throw new ConnectionException('timeout'),
            self::QUERY => Http::response($this->reply($this->successInner())),
        ]);

        $invoice = $this->invoiceFor($this->paidOrder());
        $expected = EcpayInvoiceGateway::relateNumberFor($invoice);

        $this->gateway()->issue($invoice, 'k');

        Http::assertSent(function ($request) use ($expected) {
            if ($request->url() !== self::QUERY) {
                return true;
            }

            $inner = json_decode($this->aes()->decrypt($request->data()['Data']), true);

            return $inner['RelateNumber'] === $expected;
        });
    }

    public static function unhelpfulQueryProvider(): array
    {
        return [
            'not found' => [['MerchantID' => self::MERCHANT, 'TransCode' => 0]],
            'malformed' => [['nonsense' => true]],
        ];
    }

    #[DataProvider('unhelpfulQueryProvider')]
    public function test_a_query_without_proof_stays_ambiguous(array $queryBody): void
    {
        Http::fake([
            self::ISSUE => fn () => throw new ConnectionException('timeout'),
            self::QUERY => Http::response($queryBody, 200),
        ]);

        $this->assertTrue($this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k')->isAmbiguous());
    }

    public function test_a_query_timeout_stays_ambiguous(): void
    {
        Http::fake([
            self::ISSUE => fn () => throw new ConnectionException('issue timeout'),
            self::QUERY => fn () => throw new ConnectionException('query timeout'),
        ]);

        $this->assertTrue($this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k')->isAmbiguous());
    }

    // ==================================== 9. raw message 不落盤

    public function test_no_provider_message_or_secret_is_stored(): void
    {
        Http::fake([
            self::ISSUE => Http::response($this->reply([
                'RtnCode' => 9999,
                // 對方的自由文字，帶著我們送過去的東西。
                'RtnMsg' => 'MerchantID=2000132 HashKey='.self::HASH_KEY
                    .' secret-buyer@example.test 0912345678 12345678',
            ])),
            self::QUERY => Http::response(['MerchantID' => self::MERCHANT, 'TransCode' => 0], 200),
        ]);

        $order = $this->paidOrder(['customer_email' => 'secret-buyer@example.test']);
        $result = $this->gateway()->issue($this->invoiceFor($order), 'k');

        $encoded = json_encode([
            'code' => $result->code(),
            'message' => $result->message(),
        ], JSON_UNESCAPED_UNICODE);

        foreach ([
            self::HASH_KEY, 'secret-buyer@example.test', '0912345678', '12345678', 'MerchantID=',
        ] as $marker) {
            $this->assertStringNotContainsString($marker, $encoded, "外洩：{$marker}");
        }
    }

    public function test_no_ciphertext_is_stored(): void
    {
        Http::fake([
            self::ISSUE => Http::response($this->reply(['RtnCode' => 9999, 'RtnMsg' => 'x'])),
            self::QUERY => Http::response(['MerchantID' => self::MERCHANT, 'TransCode' => 0], 200),
        ]);

        $result = $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        // ⛔ 密文同樣不得保存：它是機密加上一段延遲。
        $this->assertStringNotContainsString('=', (string) $result->code());
        $this->assertNotNull($result->reason);
    }

    public function test_the_failure_reason_is_always_allowlisted(): void
    {
        Http::fake([
            self::ISSUE => Http::response($this->reply(['RtnCode' => 9999, 'RtnMsg' => 'anything'])),
            self::QUERY => Http::response(['MerchantID' => self::MERCHANT, 'TransCode' => 0], 200),
        ]);

        $result = $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        $this->assertContains(
            $result->code(),
            array_column(InvoiceFailureReason::cases(), 'value')
        );
    }

    // ==================================== 10. 日期解析

    public function test_an_unparseable_date_is_null(): void
    {
        $this->assertNull(EcpayInvoiceGateway::parseInvoiceDate('not a date'));
        $this->assertNull(EcpayInvoiceGateway::parseInvoiceDate('2026-13-45 99:99:99'));
        $this->assertNull(EcpayInvoiceGateway::parseInvoiceDate(null));
    }

    public function test_the_date_is_read_in_taipei_time(): void
    {
        $parsed = EcpayInvoiceGateway::parseInvoiceDate('2026-08-17 10:30:00');

        $this->assertSame('Asia/Taipei', $parsed->timezone->getName());
    }
}
