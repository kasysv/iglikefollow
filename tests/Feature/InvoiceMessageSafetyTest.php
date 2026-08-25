<?php

namespace Tests\Feature;

use App\Actions\Invoices\CreateInvoiceForPaidOrder;
use App\Actions\Invoices\IssueInvoice;
use App\Contracts\InvoiceGateway;
use App\DTO\InvoiceFailureCode;
use App\DTO\InvoiceIssueResult;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\InvoiceFailureReason;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\IntegrationSetting;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\Invoices\FakeInvoiceGateway;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use ReflectionClass;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * Provider text must not be able to reach storage or a screen.
 *
 * The earlier implementation accepted a free-form message and called stripping
 * braces "sanitizing". That removes structure, not secrets: a message reading
 * `MerchantID=SECRET123 buyer@example.com` contains no braces at all and went
 * into the database unchanged, then onto the admin page. Guessing at redaction
 * with email and key patterns would fail the same way one provider phrasing
 * later.
 *
 * So the defence is not filtering — it is that there is no parameter to filter.
 * Every test below pushes a marker at the boundary and then looks for it in
 * each place it could surface.
 */
class InvoiceMessageSafetyTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    /** 這些字串代表憑證與個資；⛔ 任何一個出現在落盤或畫面上都是外洩。 */
    private const MARKERS = [
        'SECRET123',
        'buyer@example.com',
        '0912345678',
        '12345678',
        'HashKey=LEAKME',
        'LEAKME',
    ];

    private const POISON = 'MerchantID=SECRET123 buyer@example.com 0912345678 12345678 HashKey=LEAKME';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->runningAsLiveSite();

        // B2：發票 sandbox 總開關預設關閉，測試需明確開啟。
    }

    private function paidOrder(): Order
    {
        return Order::factory()->create([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'total_amount' => 590,
            'paid_at' => now(),
        ])->fresh();
    }

    private function pendingInvoice(): Invoice
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayInvoice, IntegrationEnvironment::Production)
            ->configured()->create();

        DB::table('integration_settings')->where('id', $setting->id)->update(['is_enabled' => true]);

        return app(CreateInvoiceForPaidOrder::class)->handle($this->paidOrder());
    }

    /** A gateway that returns whatever the test tells it to. */
    private function gatewayReturning(InvoiceIssueResult $result): InvoiceGateway
    {
        return new class($result) implements InvoiceGateway
        {
            public function __construct(private InvoiceIssueResult $result) {}

            public function issue(Invoice $invoice, string $idempotencyKey): InvoiceIssueResult
            {
                return $this->result;
            }
        };
    }

    private function assertNoMarkersIn(string $haystack, string $where): void
    {
        foreach (self::MARKERS as $marker) {
            $this->assertStringNotContainsString($marker, $haystack, "{$where} 出現敏感字串：{$marker}");
        }
    }

    // ============================================ 1. DTO 邊界

    /**
     * ⛔ 沒有「訊息」參數，只有「原因」參數。
     *
     * `failed()`／`ambiguous()` 各自只收一個 reason；字串仍可傳入是為了讓
     * adapter 遞交自己的 token，但那個 token 只會被拿去查表，⛔ 查不到就是
     * Unknown，永遠不會被保留（下一個測試證明）。真正的重點是：**沒有任何
     * 參數會被原樣存下來**。
     */
    public function test_the_dto_accepts_a_reason_only_and_never_a_message(): void
    {
        foreach (['failed', 'ambiguous'] as $method) {
            $params = (new ReflectionClass(InvoiceIssueResult::class))
                ->getMethod($method)->getParameters();

            $this->assertCount(2, $params, "{$method}() 應該只有 reason 與 code 兩個參數");
            $this->assertSame('reason', $params[0]->getName(), "{$method}() 的第一個參數必須是 reason");

            /*
             * ⭐ 第二個參數是 `InvoiceFailureCode` 這個封閉型別，⛔ 不是字串。
             *
             * 這比「只有一個參數」更強：型別本身就讓 provider 的自由文字**無法**
             * 被傳進來。`InvoiceFailureCode` 只能由固定 token 與通過整數驗證的
             * 數字組成，沒有任何建構路徑接受任意字串。
             */
            $this->assertSame('code', $params[1]->getName(), "{$method}() 的第二個參數必須是 code");
            $this->assertSame(
                InvoiceFailureCode::class,
                (string) $params[1]->getType()?->getName(),
                "{$method}() 的 code 參數必須是封閉型別，不得是字串"
            );
        }

        // ⛔ 舊的 message 欄位已不存在，沒有地方可以放 provider 的文字。
        $this->assertFalse(
            (new ReflectionClass(InvoiceIssueResult::class))->hasProperty('message'),
            'DTO 仍有可存任意訊息的 message 屬性'
        );
    }

    public function test_an_arbitrary_failure_token_degrades_to_unknown(): void
    {
        $result = InvoiceIssueResult::failed(self::POISON);

        // ⛔ 不是被過濾，是根本沒有被保留。
        $this->assertSame(InvoiceFailureReason::Unknown, $result->reason);
        $this->assertNoMarkersIn((string) $result->code(), 'DTO code');
        $this->assertNoMarkersIn((string) $result->message(), 'DTO message');
    }

    public function test_an_arbitrary_ambiguous_token_degrades_to_unknown(): void
    {
        $result = InvoiceIssueResult::ambiguous(self::POISON);

        $this->assertSame(InvoiceFailureReason::Unknown, $result->reason);
        $this->assertNoMarkersIn((string) $result->code(), 'DTO code');
        $this->assertNoMarkersIn((string) $result->message(), 'DTO message');
    }

    public function test_an_unclassifiable_failure_is_treated_as_ambiguous(): void
    {
        // ⛔ 「無法歸類的拒絕」不等於「確定沒開出」：不能當成 failed 就此結案。
        $this->assertTrue(InvoiceIssueResult::failed('SOMETHING_NEW')->isAmbiguous());
    }

    public function test_every_allowlisted_message_is_marker_free(): void
    {
        foreach (InvoiceFailureReason::cases() as $reason) {
            $this->assertNoMarkersIn($reason->message(), "enum {$reason->value}");
            $this->assertNoMarkersIn($reason->value, "enum {$reason->value} code");
        }
    }

    // ============================================ 2. 落盤

    public function test_a_poisoned_failure_never_reaches_the_database(): void
    {
        $invoice = $this->pendingInvoice();

        (new IssueInvoice($this->gatewayReturning(
            InvoiceIssueResult::failed(self::POISON)
        )))->handle($invoice);

        $raw = json_encode([
            DB::table('invoices')->get(),
            DB::table('invoice_attempts')->get(),
            DB::table('admin_audit_logs')->get(),
        ], JSON_UNESCAPED_UNICODE);

        $this->assertNoMarkersIn($raw, '資料庫');
    }

    public function test_a_poisoned_ambiguous_result_never_reaches_the_database(): void
    {
        $invoice = $this->pendingInvoice();

        (new IssueInvoice($this->gatewayReturning(
            InvoiceIssueResult::ambiguous(self::POISON)
        )))->handle($invoice);

        $raw = json_encode([
            DB::table('invoices')->get(),
            DB::table('invoice_attempts')->get(),
        ], JSON_UNESCAPED_UNICODE);

        $this->assertNoMarkersIn($raw, '資料庫');
        // ⭐ D-179：不明結果收斂為 failed；毒性字串仍然一個字都不得落盤。
        $this->assertSame(InvoiceStatus::Failed, $invoice->fresh()->status);
    }

    public function test_a_stored_failure_code_is_always_allowlisted(): void
    {
        $invoice = $this->pendingInvoice();

        (new IssueInvoice($this->gatewayReturning(
            InvoiceIssueResult::failed(self::POISON)
        )))->handle($invoice);

        $this->assertContains(
            $invoice->fresh()->failure_code,
            array_column(InvoiceFailureReason::cases(), 'value')
        );
    }

    public function test_nothing_leaks_into_the_log(): void
    {
        Log::spy();

        $invoice = $this->pendingInvoice();

        (new IssueInvoice($this->gatewayReturning(
            InvoiceIssueResult::failed(self::POISON)
        )))->handle($invoice);

        // ⛔ 任何一次 log 呼叫都不得帶著 marker。
        Log::shouldNotHaveReceived('error', [self::POISON]);
        Log::shouldNotHaveReceived('warning', [self::POISON]);

        $this->assertNoMarkersIn(
            json_encode(DB::table('invoices')->get(), JSON_UNESCAPED_UNICODE),
            'log 路徑落盤'
        );
    }

    // ============================================ 3. 後台畫面

    public function test_nothing_leaks_into_the_admin_screens(): void
    {
        $invoice = $this->pendingInvoice();

        (new IssueInvoice($this->gatewayReturning(
            InvoiceIssueResult::failed(self::POISON)
        )))->handle($invoice);

        $this->actingAs(User::factory()->create(['role' => 'owner', 'is_active' => true]));

        // 列表與詳情的 HTML 與 Livewire state 都不得出現 marker。
        $list = Livewire::test(ListInvoices::class);
        $this->assertNoMarkersIn($list->html(), '發票列表 HTML');

        $view = Livewire::test(ViewInvoice::class, ['record' => $invoice->fresh()->getKey()]);
        $this->assertNoMarkersIn($view->html(), '發票詳情 HTML');
        $this->assertNoMarkersIn(json_encode($view->snapshot, JSON_UNESCAPED_UNICODE), 'Livewire state');
    }

    // ============================================ 4. Fake adapter 沒有後門

    public function test_the_fake_gateway_takes_no_arbitrary_message(): void
    {
        // ⛔ 測試便利不能成為「把任意文字送進資料庫」的公開路徑。
        foreach (['alwaysFail', 'alwaysBeAmbiguous'] as $method) {
            $params = (new ReflectionClass(FakeInvoiceGateway::class))
                ->getMethod($method)->getParameters();

            foreach ($params as $param) {
                $this->assertSame(
                    InvoiceFailureReason::class,
                    (string) $param->getType(),
                    "FakeInvoiceGateway::{$method}() 仍接受任意字串"
                );
            }
        }
    }

    // ============================================ 5. 補：DB constraint 負向測試

    public function test_the_database_refuses_a_negative_amount(): void
    {
        $order = $this->paidOrder();

        $this->expectException(QueryException::class);

        DB::table('invoices')->insert([
            'order_id' => $order->id, 'provider' => 'ecpay_invoice', 'status' => 'pending',
            'amount' => -1, 'currency' => 'TWD', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_database_refuses_an_unknown_provider(): void
    {
        $order = $this->paidOrder();

        $this->expectException(QueryException::class);

        DB::table('invoices')->insert([
            'order_id' => $order->id, 'provider' => 'some_other_provider', 'status' => 'pending',
            'amount' => 590, 'currency' => 'TWD', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_invoice_tables_have_no_raw_payload_columns(): void
    {
        // ⛔ 沒有欄位可以存原始請求／回應，也就沒有地方讓它悄悄長回來。
        foreach (['invoices', 'invoice_attempts'] as $table) {
            $columns = Schema::getColumnListing($table);

            foreach (['raw_response', 'raw_request', 'response_body', 'request_body', 'payload'] as $forbidden) {
                $this->assertNotContains($forbidden, $columns, "{$table} 出現可存原始內容的欄位");
            }
        }
    }
}
