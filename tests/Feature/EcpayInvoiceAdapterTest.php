<?php

namespace Tests\Feature;

use App\Actions\Invoices\IssueInvoice;
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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresLiveIntegrations;
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
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    private const MERCHANT = '2000132';

    private const HASH_KEY = 'ejCk326UnaZWKisg';

    private const HASH_IV = 'q9jcZX8Ib9LM8wYk';

    private const ISSUE = 'https://einvoice.ecpay.com.tw/B2CInvoice/Issue';

    private const QUERY = 'https://einvoice.ecpay.com.tw/B2CInvoice/GetIssue';

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

    /**
     * An encrypted ECPay reply, built the way they build it.
     *
     * ⛔ `encrypt()` receives the array directly. The SDK does its own
     * json_encode inside; encoding first here would build a fixture that is
     * wrong in exactly the same way the client used to be, and the pair would
     * agree with each other while agreeing with nothing ECPay sends.
     */
    private function reply(array $inner, mixed $transCode = 1, ?string $merchantId = null): array
    {
        return [
            'MerchantID' => $merchantId ?? self::MERCHANT,
            'RpHeader' => ['Timestamp' => 1755400000],
            'TransCode' => $transCode,
            'TransMsg' => '',
            'Data' => $this->aes()->encrypt($inner),
        ];
    }

    /** 官方 Issue 成功欄位。 */
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

    /**
     * 官方 GetIssue 成功欄位——與 Issue 完全不同的 schema。
     *
     * ⛔ 不可重用 successInner()：真實查詢回的是 `IIS_*`，用 Issue fixture 測
     * 查詢等於沒有測到查詢。
     */
    private function queryInner(array $overrides = []): array
    {
        return array_merge([
            'RtnCode' => 1,
            'RtnMsg' => '查詢成功',
            'IIS_Mer_ID' => self::MERCHANT,
            'IIS_Number' => 'AB12345678',
            'IIS_Relate_Number' => 'IGLTEST01',
            'IIS_Create_Date' => '2026-08-17 10:30:00',
            'IIS_Random_Number' => '1234',
            'IIS_Issue_Status' => '1',
            'IIS_Invalid_Status' => '0',
        ], $overrides);
    }

    private function fakeIssue(array $body, int $status = 200): void
    {
        Http::fake([self::ISSUE => Http::response($body, $status)]);
    }

    /** 解出某次 request 的 Data；⛔ 直接得到 array，不做二次 json_decode。 */
    private function sentData(Request $request): array
    {
        return $this->aes()->decrypt($request->data()['Data']);
    }

    // ==================================== 1. 官方 AES 固定向量

    /**
     * 綠界官方文件 <https://developers.ecpay.com.tw/7958/> 的固定向量。
     *
     * key／IV 就是官方測試值，明文與密文亦然。我在 M3B-B2 初版寫「官方沒有
     * 可重現的固定向量」是錯的——官方頁面同時提供明文、key、IV 與密文，這裡
     * 已用它們反證。
     */
    private const OFFICIAL_PLAIN = ['Name' => 'Test', 'ID' => 'A123456789'];

    private const OFFICIAL_CIPHER = 'uvI4yrErM37XNQkXGAgRgJAgHn2t72jahaMZzYhWL1HmvH4WV18VJDP2i9pTbC+tby5nxVExLLFyAkbjbS2Dvg==';

    public function test_the_official_plaintext_encrypts_to_the_official_cipher(): void
    {
        // ⛔ 陣列直接進 encrypt()：SDK 內部自行 json_encode → urlencode → AES。
        $this->assertSame(self::OFFICIAL_CIPHER, $this->aes()->encrypt(self::OFFICIAL_PLAIN));
    }

    public function test_the_official_cipher_decrypts_to_the_official_array(): void
    {
        $decrypted = $this->aes()->decrypt(self::OFFICIAL_CIPHER);

        // ⛔ 直接就是 array，不需要也不可以再 json_decode。
        $this->assertIsArray($decrypted);
        $this->assertSame(self::OFFICIAL_PLAIN, $decrypted);
    }

    public function test_pre_encoding_the_payload_does_not_match_the_official_cipher(): void
    {
        /*
         * 這是初版的做法：先 json_encode 再交給 encrypt()。
         *
         * ⛔ 它不會報錯，只會產生另一段密文——綠界解出來會是一個 JSON 字串而
         * 不是物件。初版的 fixture 兩邊用同一個錯誤流程，所以 69 個測試全綠，
         * 卻沒有任何一個證明過 wire 相容。這個測試就是為了讓那個錯誤無法再
         * 悄悄通過。
         */
        $doubled = $this->aes()->encrypt(json_encode(self::OFFICIAL_PLAIN, JSON_UNESCAPED_UNICODE));

        $this->assertNotSame(self::OFFICIAL_CIPHER, $doubled);
    }

    public function test_the_cipher_is_deterministic_for_the_same_input(): void
    {
        // ⛔ AES-128-CBC with a fixed IV：同一份輸入必須得到同一份密文，
        // 否則測試無法固定向量，也代表 IV 不是我們以為的那個。
        $this->assertSame(
            $this->aes()->encrypt(self::OFFICIAL_PLAIN),
            $this->aes()->encrypt(self::OFFICIAL_PLAIN),
        );
    }

    public function test_a_wrong_key_cannot_decrypt(): void
    {
        $wrong = new AesService('0000000000000000', self::HASH_IV);

        $decrypted = null;

        try {
            $decrypted = $wrong->decrypt(self::OFFICIAL_CIPHER);
        } catch (\Throwable) {
            $decrypted = null;
        }

        $this->assertNotSame(self::OFFICIAL_PLAIN, $decrypted);
    }

    public function test_a_wrong_iv_cannot_decrypt(): void
    {
        $wrong = new AesService(self::HASH_KEY, '0000000000000000');

        $decrypted = null;

        try {
            $decrypted = $wrong->decrypt(self::OFFICIAL_CIPHER);
        } catch (\Throwable) {
            $decrypted = null;
        }

        $this->assertNotSame(self::OFFICIAL_PLAIN, $decrypted);
    }

    public function test_a_tampered_cipher_does_not_yield_the_original(): void
    {
        $tampered = substr(self::OFFICIAL_CIPHER, 0, -4).'AAAA';

        $decrypted = null;

        try {
            $decrypted = $this->aes()->decrypt($tampered);
        } catch (\Throwable) {
            $decrypted = null;
        }

        $this->assertNotSame(self::OFFICIAL_PLAIN, $decrypted);
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
        // ⛔ M4C:關閉發票＝Owner 在後台停用那一列,不是改已 deprecated 的 env 旗標。
        DB::table('integration_settings')->update(['is_enabled' => false]);

        $this->assertTrue($this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k')->isFailed());
        Http::assertNothingSent();
    }

    /**
     * ⛔ 本機／測試環境永遠不得開立真實發票。
     *
     * M4C 之後 production 是允許的環境（那正是 Owner 營運的地方），剩下的
     * 環境邊界是這一條：本機不得送出。發票是一份真實的稅務文件，一張在開發
     * 機器上開出來的發票，是有人要去作廢的。
     */
    public function test_a_local_machine_sends_nothing(): void
    {
        Http::fake();
        $this->runningAsLiveSite('local');

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

    /**
     * ⛔ 每一個都不是白名單裡的那一個字串,所以都必須被拒絕。
     *
     * M4C 之後合法值換成正式端點,所以「stage 主機」現在是被拒絕的一方——
     * 而它值得留著:一份還指著 stage 的設定在正式站上,代表發票會開到測試
     * 環境去,而後台看起來一切正常。
     */
    public static function rejectedEndpointProvider(): array
    {
        return [
            'blank' => [''],
            'stage host' => ['https://einvoice-stage.ecpay.com.tw/B2CInvoice/Issue'],
            'lookalike host' => ['https://einvoice.ecpay.com.tw.evil.example.com/B2CInvoice/Issue'],
            'arbitrary host' => ['https://evil.example.com/B2CInvoice/Issue'],
            'non https' => ['http://einvoice.ecpay.com.tw/B2CInvoice/Issue'],
            // ⛔ 同一台主機、不同 operation：作廢發票絕不能因為主機對就送出去。
            'same host invalid path' => ['https://einvoice.ecpay.com.tw/B2CInvoice/Invalid'],
            'same host query path' => ['https://einvoice.ecpay.com.tw/B2CInvoice/GetIssue'],
            'same host root' => ['https://einvoice.ecpay.com.tw/'],
            'trailing slash' => ['https://einvoice.ecpay.com.tw/B2CInvoice/Issue/'],
            'query string' => ['https://einvoice.ecpay.com.tw/B2CInvoice/Issue?debug=1'],
            'fragment' => ['https://einvoice.ecpay.com.tw/B2CInvoice/Issue#f'],
            'userinfo' => ['https://user@einvoice.ecpay.com.tw/B2CInvoice/Issue'],
            'explicit port' => ['https://einvoice.ecpay.com.tw:8443/B2CInvoice/Issue'],
            'uppercased path' => ['https://einvoice.ecpay.com.tw/B2CInvoice/ISSUE'],
        ];
    }

    /** ⛔ Issue 端點必須與白名單完全一致；同主機不同 path 也拒絕。 */
    #[DataProvider('rejectedEndpointProvider')]
    public function test_a_non_allowlisted_endpoint_sends_nothing(string $endpoint): void
    {
        Http::fake();
        config()->set('integrations.endpoints.ecpay_invoice.production', $endpoint);

        $this->assertTrue($this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k')->isFailed());
        Http::assertNothingSent();
    }

    public static function rejectedQueryEndpointProvider(): array
    {
        return [
            'blank' => [''],
            'stage host' => ['https://einvoice-stage.ecpay.com.tw/B2CInvoice/GetIssue'],
            'lookalike host' => ['https://einvoice.ecpay.com.tw.evil.example.com/B2CInvoice/GetIssue'],
            'arbitrary host' => ['https://evil.example.com/B2CInvoice/GetIssue'],
            'non https' => ['http://einvoice.ecpay.com.tw/B2CInvoice/GetIssue'],
            // ⛔ 查詢端點被換成 Issue 就會變成「重開一張」，這是最危險的一種錯配。
            'same host issue path' => ['https://einvoice.ecpay.com.tw/B2CInvoice/Issue'],
            'same host invalid path' => ['https://einvoice.ecpay.com.tw/B2CInvoice/Invalid'],
            'trailing slash' => ['https://einvoice.ecpay.com.tw/B2CInvoice/GetIssue/'],
            'query string' => ['https://einvoice.ecpay.com.tw/B2CInvoice/GetIssue?debug=1'],
            'fragment' => ['https://einvoice.ecpay.com.tw/B2CInvoice/GetIssue#f'],
            'userinfo' => ['https://user@einvoice.ecpay.com.tw/B2CInvoice/GetIssue'],
            'explicit port' => ['https://einvoice.ecpay.com.tw:8443/B2CInvoice/GetIssue'],
        ];
    }

    /**
     * ⛔ 查詢端點不合法時：Issue 仍送 1 次，查詢 0 次，結果維持不明。
     *
     * 不可因為「查不到」就當成沒開；也不可因為查詢端點壞掉就重開一張。
     */
    #[DataProvider('rejectedQueryEndpointProvider')]
    public function test_a_non_allowlisted_query_endpoint_is_never_called(string $endpoint): void
    {
        config()->set('integrations.endpoints.ecpay_invoice_query.production', $endpoint);

        Http::fake([
            self::ISSUE => Http::response($this->reply($this->successInner(), transCode: 99)),
            self::QUERY => Http::response($this->reply($this->queryInner())),
        ]);

        $result = $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        $this->assertTrue($result->isAmbiguous());
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->url() === self::QUERY);
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

    /**
     * ⛔ 官方 wire contract：解密後直接就是 array。
     *
     * 這個測試在初版會失敗——初版解出來的是一段 JSON 字串，`is_array()` 為
     * false。這正是它存在的理由：double encode 不會報錯，只會安靜地送出綠界
     * 讀不懂的格式。
     */
    public function test_the_encrypted_data_decrypts_directly_to_an_array(): void
    {
        $this->fakeIssue($this->reply($this->successInner()));

        $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        Http::assertSent(function ($request) {
            $decrypted = $this->aes()->decrypt($request->data()['Data']);

            // ⛔ 不做 json_decode：需要再解一次就代表格式是錯的。
            return is_array($decrypted)
                && $decrypted['SalesAmount'] === 590
                && $decrypted['Print'] === '0'
                && $decrypted['MerchantID'] === self::MERCHANT;
        });
    }

    public function test_the_encrypted_data_is_not_a_json_string(): void
    {
        $this->fakeIssue($this->reply($this->successInner()));

        $this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k');

        Http::assertSent(function ($request) {
            // 初版送的是 JSON 字串；⛔ 這裡明確反證那個形狀不會再出現。
            return ! is_string($this->aes()->decrypt($request->data()['Data']));
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
            /*
             * ⛔ 格式對不上官方的日期不算成功。
             *
             * 初版只要求「非空字串」，解析失敗就悄悄改用本地 now()。那會寫下
             * 一個與國稅局紀錄不同的開立時間，而且沒有任何人會在當下發現。
             */
            'unparseable date' => [['InvoiceDate' => 'not a date']],
            'impossible date' => [['InvoiceDate' => '2026-13-45 99:99:99']],
            'date only' => [['InvoiceDate' => '2026-08-17']],
            'wrong separator' => [['InvoiceDate' => '2026.08.17 10:30:00']],
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

    /**
     * 查詢用的 RelateNumber 必須就是這張發票的那一個。
     *
     * ⛔ 官方 GetIssue 成功欄位是 `IIS_*`，與 Issue 的 `InvoiceNo` 系列完全
     * 不同；初版兩者共用同一個 parser 與同一份 fixture，所以真實查詢回應永遠
     * 讀不懂——唯一能解開「不明」的路徑其實從來沒有通過。
     */
    private function positiveQueryFor(Invoice $invoice): array
    {
        return $this->reply($this->queryInner([
            'IIS_Relate_Number' => EcpayInvoiceGateway::relateNumberFor($invoice),
        ]));
    }

    public function test_a_positive_query_converges_to_issued(): void
    {
        $invoice = $this->invoiceFor($this->paidOrder());
        $expected = EcpayInvoiceGateway::relateNumberFor($invoice);

        Http::fake([
            self::ISSUE => fn () => throw new ConnectionException('timeout'),
            // 查詢證明發票其實已經開出來了。
            self::QUERY => Http::response($this->positiveQueryFor($invoice)),
        ]);

        $result = $this->gateway()->issue($invoice, 'k');

        $this->assertTrue($result->isIssued());
        $this->assertSame('AB12345678', $result->invoiceNumber);
        $this->assertSame('1234', $result->randomCode);
        // ⛔ 用同一個 RelateNumber 收斂。
        $this->assertSame($expected, $result->providerReference);
        // 收斂後採用的是對方的開立時間，不是我們的 now()。
        $this->assertSame('2026-08-17 10:30:00', $result->issuedAt->format('Y-m-d H:i:s'));
    }

    public function test_an_issue_shaped_query_reply_is_not_proof(): void
    {
        /*
         * ⛔ 這正是初版的 fixture：拿 Issue 的成功欄位當查詢證據。
         *
         * 真實綠界不會這樣回，所以它不能被接受為「已開立」的證明；否則我們
         * 是在用一個自己造出來的形狀說服自己發票存在。
         */
        Http::fake([
            self::ISSUE => fn () => throw new ConnectionException('timeout'),
            self::QUERY => Http::response($this->reply($this->successInner())),
        ]);

        $this->assertTrue($this->gateway()->issue($this->invoiceFor($this->paidOrder()), 'k')->isAmbiguous());
    }

    public function test_the_query_uses_the_same_relate_number(): void
    {
        $invoice = $this->invoiceFor($this->paidOrder());
        $expected = EcpayInvoiceGateway::relateNumberFor($invoice);

        Http::fake([
            self::ISSUE => fn () => throw new ConnectionException('timeout'),
            self::QUERY => Http::response($this->positiveQueryFor($invoice)),
        ]);

        $this->gateway()->issue($invoice, 'k');

        Http::assertSent(function ($request) use ($expected) {
            if ($request->url() !== self::QUERY) {
                return true;
            }

            // ⛔ 直接是 array。
            $inner = $this->sentData($request);

            return $inner['RelateNumber'] === $expected
                && $inner['MerchantID'] === self::MERCHANT;
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

    /**
     * 每一種「差一點就算證明」的查詢回應。
     *
     * ⛔ 全部維持不明。收斂的條件是「這張、我們的、已開立、未作廢」都成立；
     * 少一項就不是證明，而人工看一眼的成本遠低於重開一張發票。
     */
    public static function insufficientQueryProofProvider(): array
    {
        return [
            'wrong merchant' => [['IIS_Mer_ID' => '9999999']],
            'missing merchant' => [['IIS_Mer_ID' => null]],
            'wrong relate number' => [['IIS_Relate_Number' => 'SOMEONEELSE01']],
            'missing relate number' => [['IIS_Relate_Number' => null]],
            'not issued yet' => [['IIS_Issue_Status' => '0']],
            'loose issue status bool' => [['IIS_Issue_Status' => true]],
            'loose issue status int' => [['IIS_Issue_Status' => 1]],
            'unknown issue status' => [['IIS_Issue_Status' => '9']],
            'voided' => [['IIS_Invalid_Status' => '1']],
            'loose invalid status int' => [['IIS_Invalid_Status' => 0]],
            'unknown invalid status' => [['IIS_Invalid_Status' => '9']],
            'missing number' => [['IIS_Number' => null]],
            'array number' => [['IIS_Number' => ['AB1']]],
            'empty number' => [['IIS_Number' => '']],
            'missing random' => [['IIS_Random_Number' => null]],
            'array random' => [['IIS_Random_Number' => ['1234']]],
            'missing date' => [['IIS_Create_Date' => null]],
            'unparseable date' => [['IIS_Create_Date' => 'not a date']],
            'impossible date' => [['IIS_Create_Date' => '2026-13-45 99:99:99']],
            'array date' => [['IIS_Create_Date' => ['2026-08-17 10:30:00']]],
            'failed rtn code' => [['RtnCode' => 0]],
            'string rtn code' => [['RtnCode' => '1']],
        ];
    }

    #[DataProvider('insufficientQueryProofProvider')]
    public function test_an_incomplete_query_proof_stays_ambiguous(array $override): void
    {
        $invoice = $this->invoiceFor($this->paidOrder());

        $inner = array_merge(
            $this->queryInner(['IIS_Relate_Number' => EcpayInvoiceGateway::relateNumberFor($invoice)]),
            $override,
        );

        Http::fake([
            self::ISSUE => fn () => throw new ConnectionException('timeout'),
            self::QUERY => Http::response($this->reply($inner)),
        ]);

        $result = $this->gateway()->issue($invoice, 'k');

        $this->assertTrue($result->isAmbiguous());
        $this->assertNull($result->invoiceNumber);

        // ⛔ 不明之後不得再送 Issue，也不得重複查詢。
        $sent = ['issue' => 0, 'query' => 0];
        foreach (Http::recorded() as [$request]) {
            $sent[$request->url() === self::ISSUE ? 'issue' : 'query']++;
        }
        $this->assertLessThanOrEqual(1, $sent['issue']);
        $this->assertLessThanOrEqual(1, $sent['query']);
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

    /**
     * 走完整條落盤路徑，再逐一檢查每一個可能留下痕跡的地方。
     *
     * ⛔ 只 serialize DTO 是不夠的：DTO 乾淨不代表 invoice 欄位、attempt 欄位、
     * audit 或 log 檔案乾淨。真正的問題是「provider 的自由文字有沒有在某處被
     * 存下來」，那必須看實際寫進去的每一列。
     */
    public function test_no_provider_text_survives_the_full_persistence_path(): void
    {
        $leak = 'MerchantID=2000132 HashKey='.self::HASH_KEY
            .' secret-buyer@example.test 0912345678 12345678 CIPHER/AbC+dEf=';

        Http::fake([
            self::ISSUE => Http::response($this->reply(['RtnCode' => 9999, 'RtnMsg' => $leak])),
            self::QUERY => Http::response(['MerchantID' => self::MERCHANT, 'TransCode' => 0], 200),
        ]);

        $logFile = storage_path('logs/laravel.log');
        $logSizeBefore = is_file($logFile) ? filesize($logFile) : 0;

        $order = $this->paidOrder(['customer_email' => 'secret-buyer@example.test']);
        $invoice = $this->invoiceFor($order);

        app(IssueInvoice::class)->handle($invoice);

        $invoice = $invoice->fresh();

        // 這條路徑真的走完了：⭐ D-179 之後不明結果收斂成 failed（不再是待對帳）。
        $this->assertSame(InvoiceStatus::Failed, $invoice->status);

        $surfaces = [
            'invoice row' => json_encode(DB::table('invoices')->get(), JSON_UNESCAPED_UNICODE),
            'attempt rows' => json_encode(DB::table('invoice_attempts')->get(), JSON_UNESCAPED_UNICODE),
            'order row' => json_encode(DB::table('orders')->get(), JSON_UNESCAPED_UNICODE),
        ];

        /*
         * ⛔ 開票路徑不寫 audit（audit 只記後台 credential 操作）。
         *
         * 這裡如實斷言「這條路徑沒有產生任何 audit row」，而不是為了讓檢查看
         * 起來完整就去造一筆不存在的紀錄——那會用假證據通過驗收。表本身仍被
         * 掃過一次，日後若有人在這條路徑加上 audit，marker 檢查會立刻涵蓋它。
         */
        $this->assertSame(0, DB::table('admin_audit_logs')->count(), '開票路徑不應寫入 audit');

        $surfaces['audit rows'] = json_encode(
            DB::table('admin_audit_logs')->get(),
            JSON_UNESCAPED_UNICODE
        );

        // 新增的 log 內容（若有）。
        if (is_file($logFile) && filesize($logFile) > $logSizeBefore) {
            $handle = fopen($logFile, 'r');
            fseek($handle, $logSizeBefore);
            $surfaces['log tail'] = (string) fread($handle, filesize($logFile) - $logSizeBefore);
            fclose($handle);
        }

        foreach ([
            self::HASH_KEY, 'secret-buyer@example.test', '0912345678',
            'MerchantID=', 'HashKey=', 'CIPHER/AbC+dEf=', $leak,
        ] as $marker) {
            foreach ($surfaces as $where => $content) {
                $this->assertStringNotContainsString($marker, (string) $content, "{$where} 外洩：{$marker}");
            }
        }

        // 存下來的理由必須是本地 allowlist 的值。
        $this->assertContains(
            $invoice->failure_code,
            array_column(InvoiceFailureReason::cases(), 'value')
        );
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
