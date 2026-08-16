<?php

namespace Tests\Feature;

use App\Actions\Invoices\CreateInvoiceForPaidOrder;
use App\Actions\Invoices\IssueInvoice;
use App\Actions\Orders\RecordPaymentResult;
use App\Contracts\InvoiceGateway;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\InvoiceAttemptStatus;
use App\Enums\InvoiceFailureReason;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPaid;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Jobs\IssueInvoiceForOrder;
use App\Models\IntegrationSetting;
use App\Models\Invoice;
use App\Models\InvoiceAttempt;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\Invoices\FakeInvoiceGateway;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Issuing a tax document exactly once, for money that actually arrived.
 *
 * Two failure modes drive most of these tests. Issuing an invoice for an
 * unpaid order is a tax problem; issuing a *second* invoice for an order whose
 * first attempt timed out is the same problem wearing a disguise, which is why
 * ambiguous results stop and wait for a person instead of retrying.
 */
class InvoiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private FakeInvoiceGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        // ⛔ 這一輪不得有任何外部呼叫。
        Http::preventStrayRequests();

        $this->gateway = new FakeInvoiceGateway;
        $this->app->instance(InvoiceGateway::class, $this->gateway);
    }

    private function paidOrder(int $amount = 590): Order
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'total_amount' => $amount,
            'paid_at' => now(),
        ]);

        return $order->fresh();
    }

    private function configureInvoiceGateway(): IntegrationSetting
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayInvoice, IntegrationEnvironment::Sandbox)
            ->configured()->create();

        // 啟用受 config allowlist 管制，測試中直接寫 DB 模擬「已獲批准啟用」。
        DB::table('integration_settings')->where('id', $setting->id)->update(['is_enabled' => true]);

        return $setting->fresh();
    }

    private function create(): CreateInvoiceForPaidOrder
    {
        return app(CreateInvoiceForPaidOrder::class);
    }

    private function issue(): IssueInvoice
    {
        return new IssueInvoice($this->gateway);
    }

    // ============================================ 1. 只有已付款訂單才開發票

    public static function unpaidStatusProvider(): array
    {
        return [
            'pending payment' => [OrderStatus::PendingPayment, PaymentStatus::Pending],
            'failed' => [OrderStatus::PendingPayment, PaymentStatus::Failed],
            'canceled' => [OrderStatus::Canceled, PaymentStatus::Canceled],
            'expired' => [OrderStatus::Canceled, PaymentStatus::Expired],
        ];
    }

    #[DataProvider('unpaidStatusProvider')]
    public function test_an_unpaid_order_gets_no_invoice(
        OrderStatus $orderStatus, PaymentStatus $paymentStatus
    ): void {
        $order = Order::factory()->create([
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
        ]);

        $this->expectException(RuntimeException::class);

        $this->create()->handle($order);
    }

    public function test_an_unpaid_order_creates_no_invoice_row(): void
    {
        $order = Order::factory()->create();

        try {
            $this->create()->handle($order);
        } catch (RuntimeException) {
            // 預期。
        }

        $this->assertSame(0, Invoice::count());
    }

    public function test_a_paid_order_gets_exactly_one_invoice(): void
    {
        $this->configureInvoiceGateway();
        $order = $this->paidOrder();

        $invoice = $this->create()->handle($order);

        $this->assertSame($order->id, $invoice->order_id);
        $this->assertSame(590, $invoice->amount);
        $this->assertSame(InvoiceStatus::Pending, $invoice->status);
        $this->assertSame(1, Invoice::count());
    }

    // ============================================ 2. 冪等

    public function test_creating_twice_returns_the_same_invoice(): void
    {
        $this->configureInvoiceGateway();
        $order = $this->paidOrder();

        $first = $this->create()->handle($order);
        $second = $this->create()->handle($order);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Invoice::count());
    }

    public function test_a_duplicate_invoice_is_impossible_at_the_database(): void
    {
        $this->configureInvoiceGateway();
        $order = $this->paidOrder();
        $this->create()->handle($order);

        // ⛔ 就算繞過 action 直接寫，unique 仍然擋下。
        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('invoices')->insert([
            'order_id' => $order->id,
            'provider' => 'ecpay_invoice',
            'status' => 'pending',
            'amount' => 590,
            'currency' => 'TWD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_repeated_order_paid_event_queues_but_yields_one_invoice(): void
    {
        $this->configureInvoiceGateway();
        $order = $this->paidOrder();

        // 同一張訂單被通知三次。
        for ($i = 0; $i < 3; $i++) {
            (new IssueInvoiceForOrder($order->id))->handle($this->create(), $this->issue());
        }

        $this->assertSame(1, Invoice::count());
        // 第一次開立後狀態就是 issued，後兩次不再呼叫 gateway。
        $this->assertCount(1, $this->gateway->calls);
    }

    public function test_a_rolled_back_transaction_creates_no_invoice(): void
    {
        $this->configureInvoiceGateway();
        $order = $this->paidOrder();

        try {
            DB::transaction(function () use ($order) {
                $this->create()->handle($order);

                throw new RuntimeException('模擬後續失敗');
            });
        } catch (RuntimeException) {
            // 預期。
        }

        $this->assertSame(0, Invoice::count());
    }

    public function test_the_order_paid_event_only_queues_a_job(): void
    {
        Queue::fake();

        $this->configureInvoiceGateway();
        $order = $this->paidOrder();

        OrderPaid::dispatch($order);

        // ⛔ 監聽器只排隊，不在請求內呼叫外部服務。
        Queue::assertPushed(IssueInvoiceForOrder::class, 1);
        $this->assertSame(0, Invoice::count());
    }

    // ============================================ 3. 沒有 credential 就停下

    public function test_a_missing_credential_leaves_the_invoice_pending_configuration(): void
    {
        $order = $this->paidOrder();

        $invoice = $this->create()->handle($order);

        // ⛔ 不是失敗，也不重試：什麼都還沒設定。
        $this->assertSame(InvoiceStatus::PendingConfiguration, $invoice->status);
        $this->assertSame(OrderStatus::Paid, $order->fresh()->order_status);
    }

    public function test_a_disabled_credential_leaves_the_invoice_pending_configuration(): void
    {
        IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayInvoice, IntegrationEnvironment::Sandbox)
            ->configured()->create(); // is_enabled 預設 false

        $invoice = $this->create()->handle($this->paidOrder());

        $this->assertSame(InvoiceStatus::PendingConfiguration, $invoice->status);
    }

    public function test_pending_configuration_never_calls_the_gateway(): void
    {
        $order = $this->paidOrder();

        (new IssueInvoiceForOrder($order->id))->handle($this->create(), $this->issue());

        // ⛔ 沒有 credential 就不該有任何呼叫。
        $this->assertSame([], $this->gateway->calls);
    }

    // ============================================ 4. Fake 三條路徑

    public function test_a_successful_issue_records_the_number(): void
    {
        $this->configureInvoiceGateway();
        $this->gateway->alwaysIssue();

        $invoice = $this->issue()->handle($this->create()->handle($this->paidOrder()));

        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertNotNull($invoice->invoice_number);
        $this->assertNotNull($invoice->issued_at);
        $this->assertSame(InvoiceAttemptStatus::Succeeded, $invoice->attempts()->first()->status);
    }

    public function test_a_deterministic_failure_is_recorded_as_failed(): void
    {
        $this->configureInvoiceGateway();
        $this->gateway->alwaysFail(InvoiceFailureReason::InvalidBuyerDetails);

        $invoice = $this->issue()->handle($this->create()->handle($this->paidOrder()));

        $this->assertSame(InvoiceStatus::Failed, $invoice->status);
        // ⛔ 代碼與訊息都來自本地 allowlist。
        $this->assertSame('INVALID_BUYER_DETAILS', $invoice->failure_code);
        $this->assertSame(InvoiceFailureReason::InvalidBuyerDetails->message(), $invoice->failure_message);
        $this->assertNull($invoice->invoice_number);
    }

    public function test_an_ambiguous_result_waits_for_a_human(): void
    {
        $this->configureInvoiceGateway();
        $this->gateway->alwaysBeAmbiguous();

        $invoice = $this->issue()->handle($this->create()->handle($this->paidOrder()));

        // ⛔ 結果不明不是失敗：對方可能已經開出一張真的發票。
        $this->assertSame(InvoiceStatus::ReconciliationRequired, $invoice->status);
        $this->assertTrue($invoice->status->needsHuman());
        $this->assertNotNull($invoice->reconciliation_required_at);
        $this->assertSame(InvoiceAttemptStatus::Ambiguous, $invoice->attempts()->first()->status);
    }

    public function test_an_ambiguous_result_is_never_resent_automatically(): void
    {
        $this->configureInvoiceGateway();
        $this->gateway->alwaysBeAmbiguous();

        $order = $this->paidOrder();

        // 再跑三次 job，模擬 queue 重送。
        for ($i = 0; $i < 3; $i++) {
            (new IssueInvoiceForOrder($order->id))->handle($this->create(), $this->issue());
        }

        // ⛔ 只呼叫過一次；重送會有開出第二張發票的風險。
        $this->assertCount(1, $this->gateway->calls);
        $this->assertSame(1, InvoiceAttempt::count());
    }

    public function test_a_failed_invoice_is_not_retried_automatically(): void
    {
        $this->configureInvoiceGateway();
        $this->gateway->alwaysFail();

        $order = $this->paidOrder();

        for ($i = 0; $i < 3; $i++) {
            (new IssueInvoiceForOrder($order->id))->handle($this->create(), $this->issue());
        }

        // 確定性的拒絕重送只會再被拒絕一次。
        $this->assertCount(1, $this->gateway->calls);
    }

    // ============================================ 5. 金額與資料衛生

    public function test_the_invoice_amount_equals_the_paid_amount(): void
    {
        $this->configureInvoiceGateway();

        $invoice = $this->create()->handle($this->paidOrder(1234));

        $this->assertSame(1234, $invoice->amount);
    }

    public function test_a_zero_amount_order_cannot_be_invoiced(): void
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'total_amount' => 0,
            'paid_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);

        $this->create()->handle($order->fresh());
    }

    public function test_the_invoice_stores_no_personal_data(): void
    {
        $this->configureInvoiceGateway();

        $order = Order::factory()->create([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'total_amount' => 590,
            'paid_at' => now(),
            'customer_email' => 'buyer@example.com',
            'customer_phone' => '0912345678',
            'buyer_tax_id' => '12345678',
            'buyer_name' => '祕密股份有限公司',
        ]);

        $this->issue()->handle($this->create()->handle($order->fresh()));

        $raw = json_encode([
            DB::table('invoices')->get(),
            DB::table('invoice_attempts')->get(),
        ], JSON_UNESCAPED_UNICODE);

        // ⛔ 訂單快照才是個資的唯一來源，發票不得複製一份。
        foreach (['buyer@example.com', '0912345678', '12345678', '祕密股份有限公司'] as $pii) {
            $this->assertStringNotContainsString($pii, $raw, "發票資料出現個資：{$pii}");
        }
    }

    public function test_an_attempt_stores_a_fingerprint_not_a_payload(): void
    {
        $this->configureInvoiceGateway();
        $this->issue()->handle($this->create()->handle($this->paidOrder()));

        $attempt = InvoiceAttempt::firstOrFail();

        // 單向雜湊，⛔ 不可還原成請求內容。
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $attempt->request_fingerprint);

        $columns = Schema::getColumnListing('invoice_attempts');
        foreach (['request_body', 'response_body', 'raw_request', 'raw_response', 'payload'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    /**
     * ⛔ 取代舊的「訊息不含大括號」測試。
     *
     * 那個測試保證的是錯的東西：移除結構不等於移除機密。
     * `MerchantID=SECRET123 buyer@example.com` 完全沒有大括號，舊實作原封不動
     * 存進資料庫也照樣通過。現在的保證是「provider 的文字根本進不來」。
     */
    public function test_the_stored_message_comes_from_our_own_allowlist(): void
    {
        $this->configureInvoiceGateway();
        $this->gateway->alwaysFail(InvoiceFailureReason::MerchantRejected);

        $invoice = $this->issue()->handle($this->create()->handle($this->paidOrder()));

        // 訊息逐字等於本地 enum 定義的內容。
        $this->assertSame(InvoiceFailureReason::MerchantRejected->message(), $invoice->failure_message);
        $this->assertContains(
            $invoice->failure_code,
            array_column(InvoiceFailureReason::cases(), 'value')
        );
    }

    // ============================================ 6. 發票失敗不得影響付款

    public function test_a_failed_invoice_leaves_the_payment_succeeded(): void
    {
        $this->configureInvoiceGateway();
        $this->gateway->alwaysFail();

        $order = $this->paidOrder();
        $this->issue()->handle($this->create()->handle($order));

        // ⛔ 錢確實收到了；發票開不出來是另一件事。
        $this->assertSame(OrderStatus::Paid, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Succeeded, $order->fresh()->payment_status);
    }

    public function test_an_invoice_failure_does_not_block_another_listener(): void
    {
        $this->configureInvoiceGateway();
        $this->gateway->alwaysFail();

        $otherRan = false;
        Event::listen(OrderPaid::class, function () use (&$otherRan) {
            $otherRan = true;
        });

        $attempt = PaymentAttempt::factory()->create([
            'order_id' => Order::factory()->create()->id,
        ]);

        app(RecordPaymentResult::class)->handle($attempt, PaymentStatus::Succeeded, 'TXN-OK');

        // 履約 seam（M4A 將掛在這裡）不受發票影響。
        $this->assertTrue($otherRan);
    }

    // ============================================ 7. 後台唯讀

    public function test_the_invoice_resource_offers_no_write_actions(): void
    {
        $resource = InvoiceResource::class;

        $this->assertFalse($resource::canCreate());
        $this->assertFalse($resource::canEdit(Invoice::factory()->create()));
        $this->assertFalse($resource::canDelete(Invoice::factory()->create()));
        $this->assertFalse($resource::canDeleteAny());
    }

    public function test_the_invoice_policy_refuses_every_write(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $editor = User::factory()->create(['role' => 'editor', 'is_active' => true]);
        $invoice = Invoice::factory()->create();

        $this->assertTrue($owner->can('view', $invoice));
        // ⛔ 發票是稅務文件，比訂單更窄：editor 不得檢視。
        $this->assertFalse($editor->can('view', $invoice));

        foreach (['update', 'delete', 'restore', 'forceDelete'] as $ability) {
            $this->assertFalse($owner->can($ability, $invoice), "owner 不應能 {$ability}");
        }
        $this->assertFalse($owner->can('create', Invoice::class));
    }

    public function test_no_invoice_page_is_indexable(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $this->actingAs($owner)
            ->get('/admin/invoices')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    // ============================================ 8. rollback 不得吃掉稅務資料

    /**
     * ⛔ 有發票就不准 drop table。
     *
     * 發票是稅務憑證，國稅局那邊有另一份；把我們這份靜靜刪掉，等於日後說不出
     * 自己開過什麼。回滾是開發便利，必須讓路給資料。
     */
    public function test_rolling_back_fails_closed_when_invoices_exist(): void
    {
        Invoice::factory()->create();

        $migration = require database_path('migrations/2026_08_17_100001_create_invoices_table.php');

        try {
            $migration->down();
            $this->fail('有發票時 down() 應該中止。');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('無法回滾', $e->getMessage());
        }

        // 資料完全沒動。
        $this->assertSame(1, Invoice::count());
    }

    public function test_rolling_back_fails_closed_when_credentials_exist(): void
    {
        IntegrationSetting::factory()->create();

        $migration = require database_path('migrations/2026_08_17_100000_create_integration_settings_table.php');

        try {
            $migration->down();
            $this->fail('有串接設定時 down() 應該中止。');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('無法回滾', $e->getMessage());
        }

        $this->assertSame(1, IntegrationSetting::count());
    }

    public function test_rolling_back_fails_closed_when_attempts_exist(): void
    {
        $invoice = Invoice::factory()->create();
        InvoiceAttempt::create([
            'invoice_id' => $invoice->id,
            'idempotency_key' => 'inv-test-1',
            'status' => InvoiceAttemptStatus::Succeeded,
        ]);

        $migration = require database_path('migrations/2026_08_17_100002_create_invoice_attempts_table.php');

        try {
            $migration->down();
            $this->fail('有嘗試紀錄時 down() 應該中止。');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('無法回滾', $e->getMessage());
        }

        $this->assertSame(1, InvoiceAttempt::count());
    }
}
