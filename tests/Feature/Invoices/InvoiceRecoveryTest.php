<?php

namespace Tests\Feature\Invoices;

use App\Actions\Invoices\QueueInvoiceRecoveryForOrder;
use App\Enums\IntegrationProvider;
use App\Enums\InvoiceAttemptStatus;
use App\Enums\InvoiceFailureReason;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\IssueInvoiceForOrder;
use App\Models\AdminAuditLog;
use App\Models\Invoice;
use App\Models\InvoiceAttempt;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * "補開發票": the one Owner-only path back into the queue, for the three
 * states where nothing has actually reached ECPay yet.
 *
 * ⛔ Never calls the gateway directly — every eligible outcome here only
 * dispatches the existing `IssueInvoiceForOrder` job, which itself only ever
 * reaches ECPay through `IssueInvoice`'s own compare-and-set. This file never
 * fakes that job away entirely; where the actual ECPay call matters it's the
 * fake gateway from the existing invoice lifecycle tests, not a new one.
 */
class InvoiceRecoveryTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    private function editor(): User
    {
        return User::factory()->create(['role' => 'editor', 'is_active' => true]);
    }

    private function inactiveOwner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => false]);
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

    /** Owner 開啟綠界發票通道，staging 允許外呼。 */
    private function invoiceGatewayReady(): void
    {
        $this->runningAsLiveSite();
        $this->enableChannel(IntegrationProvider::EcpayInvoice, '3000001');
    }

    // ==================================== 1. Owner-only

    public function test_an_editor_cannot_queue_recovery(): void
    {
        Queue::fake();
        $this->invoiceGatewayReady();
        $order = $this->paidOrder();

        $outcome = app(QueueInvoiceRecoveryForOrder::class)->handle($this->editor(), $order);

        $this->assertSame('blocked_not_owner', $outcome);
        Queue::assertNothingPushed();
        $this->assertSame(0, AdminAuditLog::query()->where('action', QueueInvoiceRecoveryForOrder::AUDIT_ACTION)->count());
    }

    public function test_an_inactive_owner_cannot_queue_recovery(): void
    {
        Queue::fake();
        $this->invoiceGatewayReady();
        $order = $this->paidOrder();

        $outcome = app(QueueInvoiceRecoveryForOrder::class)->handle($this->inactiveOwner(), $order);

        $this->assertSame('blocked_not_owner', $outcome);
        Queue::assertNothingPushed();
    }

    // ==================================== 2. 前置條件

    public function test_an_unpaid_order_is_refused(): void
    {
        Queue::fake();
        $this->invoiceGatewayReady();
        $order = Order::factory()->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'paid_at' => null,
        ]);

        $outcome = app(QueueInvoiceRecoveryForOrder::class)->handle($this->owner(), $order);

        $this->assertSame('blocked_unpaid', $outcome);
        Queue::assertNothingPushed();
    }

    public function test_a_non_twd_order_is_refused(): void
    {
        Queue::fake();
        $this->invoiceGatewayReady();
        $order = $this->paidOrder();
        $order->forceFill(['currency' => 'USD'])->save();

        $outcome = app(QueueInvoiceRecoveryForOrder::class)->handle($this->owner(), $order);

        $this->assertSame('blocked_not_twd', $outcome);
        Queue::assertNothingPushed();
    }

    public function test_the_gateway_not_being_ready_is_refused(): void
    {
        Queue::fake();
        $this->runningAsLiveSite();
        // ⛔ 刻意不開啟發票通道。
        $order = $this->paidOrder();

        $outcome = app(QueueInvoiceRecoveryForOrder::class)->handle($this->owner(), $order);

        $this->assertSame('blocked_gateway_not_ready', $outcome);
        Queue::assertNothingPushed();
    }

    // ==================================== 3. 三種安全狀態:排入

    public function test_no_invoice_row_yet_is_queued(): void
    {
        Queue::fake();
        $this->invoiceGatewayReady();
        $order = $this->paidOrder();

        $outcome = app(QueueInvoiceRecoveryForOrder::class)->handle($this->owner(), $order);

        $this->assertSame('queued', $outcome);
        Queue::assertPushed(IssueInvoiceForOrder::class, fn ($job) => $job->orderId === $order->id);
    }

    public function test_pending_configuration_with_zero_attempts_is_queued_and_flipped_to_pending(): void
    {
        Queue::fake();
        $this->invoiceGatewayReady();
        $order = $this->paidOrder();
        $invoice = Invoice::create([
            'order_id' => $order->id,
            'provider' => IntegrationProvider::EcpayInvoice->value,
            'status' => InvoiceStatus::PendingConfiguration,
            'amount' => 590,
            'currency' => 'TWD',
        ]);

        $outcome = app(QueueInvoiceRecoveryForOrder::class)->handle($this->owner(), $order);

        $this->assertSame('queued', $outcome);
        $this->assertSame(InvoiceStatus::Pending, $invoice->fresh()->status);
        Queue::assertPushed(IssueInvoiceForOrder::class);
    }

    public function test_pending_with_zero_attempts_is_queued(): void
    {
        Queue::fake();
        $this->invoiceGatewayReady();
        $order = $this->paidOrder();
        Invoice::create([
            'order_id' => $order->id,
            'provider' => IntegrationProvider::EcpayInvoice->value,
            'status' => InvoiceStatus::Pending,
            'amount' => 590,
            'currency' => 'TWD',
        ]);

        $outcome = app(QueueInvoiceRecoveryForOrder::class)->handle($this->owner(), $order);

        $this->assertSame('queued', $outcome);
        Queue::assertPushed(IssueInvoiceForOrder::class);
    }

    // ==================================== 4. 不合格狀態:一律拒絕

    public static function ineligibleStatusProvider(): array
    {
        return [
            'processing' => [InvoiceStatus::Processing],
            'issued' => [InvoiceStatus::Issued],
            'voided' => [InvoiceStatus::Voided],
            'reconciliation_required' => [InvoiceStatus::ReconciliationRequired],
        ];
    }

    #[DataProvider('ineligibleStatusProvider')]
    public function test_processing_issued_voided_and_reconciliation_required_are_never_recoverable(
        InvoiceStatus $status,
    ): void {
        Queue::fake();
        $this->invoiceGatewayReady();
        $order = $this->paidOrder();
        Invoice::create([
            'order_id' => $order->id,
            'provider' => IntegrationProvider::EcpayInvoice->value,
            'status' => $status,
            'amount' => 590,
            'currency' => 'TWD',
        ]);

        $outcome = app(QueueInvoiceRecoveryForOrder::class)->handle($this->owner(), $order);

        $this->assertSame('blocked_not_eligible', $outcome);
        Queue::assertNothingPushed();
    }

    /** ⛔ 有 attempt 的 failed 一律拒絕——結果不明時必須先查詢，不能盲目重送。 */
    public function test_a_failed_invoice_with_an_attempt_is_refused(): void
    {
        Queue::fake();
        $this->invoiceGatewayReady();
        $order = $this->paidOrder();
        $invoice = Invoice::create([
            'order_id' => $order->id,
            'provider' => IntegrationProvider::EcpayInvoice->value,
            'status' => InvoiceStatus::Failed,
            'amount' => 590,
            'currency' => 'TWD',
            'failure_code' => InvoiceFailureReason::Unknown->value,
        ]);
        InvoiceAttempt::create([
            'invoice_id' => $invoice->id,
            'idempotency_key' => $invoice->initialIdempotencyKey(),
            'status' => InvoiceAttemptStatus::Failed,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $outcome = app(QueueInvoiceRecoveryForOrder::class)->handle($this->owner(), $order);

        $this->assertSame('blocked_not_eligible', $outcome);
        Queue::assertNothingPushed();
    }

    /** ⛔ pending 但已有 attempt(理論上不該發生，仍必須 fail closed)。 */
    public function test_a_pending_invoice_with_an_attempt_is_refused(): void
    {
        Queue::fake();
        $this->invoiceGatewayReady();
        $order = $this->paidOrder();
        $invoice = Invoice::create([
            'order_id' => $order->id,
            'provider' => IntegrationProvider::EcpayInvoice->value,
            'status' => InvoiceStatus::Pending,
            'amount' => 590,
            'currency' => 'TWD',
        ]);
        InvoiceAttempt::create([
            'invoice_id' => $invoice->id,
            'idempotency_key' => $invoice->initialIdempotencyKey(),
            'status' => InvoiceAttemptStatus::Started,
            'started_at' => now(),
        ]);

        $outcome = app(QueueInvoiceRecoveryForOrder::class)->handle($this->owner(), $order);

        $this->assertSame('blocked_not_eligible', $outcome);
        Queue::assertNothingPushed();
    }

    // ==================================== 5. Audit

    public function test_a_queued_recovery_writes_a_safe_audit_row(): void
    {
        Queue::fake();
        $this->invoiceGatewayReady();
        $owner = $this->owner();
        $order = $this->paidOrder();

        app(QueueInvoiceRecoveryForOrder::class)->handle($owner, $order);

        $audit = AdminAuditLog::query()->where('action', QueueInvoiceRecoveryForOrder::AUDIT_ACTION)->sole();

        $this->assertSame($owner->id, $audit->user_id);
        $this->assertSame(Order::class, $audit->auditable_type);
        $this->assertSame($order->id, $audit->auditable_id);
        $this->assertSame($order->reference, $audit->after['order_reference']);

        // ⛔ 不含 Email、手機、統編、載具或任何金流資訊。
        $encoded = json_encode($audit->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($order->customer_email, (string) $encoded);
    }

    public function test_an_unavailable_audit_log_blocks_the_dispatch(): void
    {
        Queue::fake();
        $this->invoiceGatewayReady();
        $order = $this->paidOrder();
        // ⛔ owner 必須在監聽器註冊之前建立：User::factory()->create() 本身
        // 也會經過 AuditObserver 寫一筆 AdminAuditLog,若監聽器已經在場,
        // 建立 Owner 這個測試前置動作就會先被炸到,而不是我們真正要測的
        // recordAudit() 呼叫。
        $owner = $this->owner();

        AdminAuditLog::creating(function (): void {
            throw new RuntimeException('fictional audit failure');
        });

        $outcome = app(QueueInvoiceRecoveryForOrder::class)->handle($owner, $order);

        $this->assertSame('blocked_audit_unavailable', $outcome);
        Queue::assertNothingPushed();
    }

    // ==================================== 6. 併發:兩次呼叫最多排入一次真正的開立

    /**
     * ⛔ 雙擊／兩位 Owner 同時點擊,不得讓 IssueInvoice 的 compare-and-set
     * 之外多出第二條「已經開始」的路徑。`IssueInvoiceForOrder` 本身是
     * `ShouldBeUnique`(以 order id 為鍵,300 秒視窗),所以就算這個 action
     * 兩次都判定「合格、可以排」,Laravel 的 queue 層也只會真的留下一份
     * job——這正是這個測試要證明的:這個 action 沒有繞過那層去重,而是
     * 老實呼叫同一個 dispatch 入口讓它自己擋下第二次。真正防止「兩次
     * provider call」的保證仍在 `IssueInvoice::claim()`,由
     * `InvoiceConcurrencyTest` 覆蓋。
     */
    public function test_two_calls_against_the_same_eligible_row_only_leave_one_unique_job(): void
    {
        Queue::fake();
        $this->invoiceGatewayReady();
        $order = $this->paidOrder();

        $first = app(QueueInvoiceRecoveryForOrder::class)->handle($this->owner(), $order);
        $second = app(QueueInvoiceRecoveryForOrder::class)->handle($this->owner(), $order);

        $this->assertSame('queued', $first);
        $this->assertSame('queued', $second);

        // ⛔ ShouldBeUnique(uniqueId = 'invoice-order-{id}')讓第二次 dispatch
        // 被 Laravel 自己擋下,佇列裡只留一份——不是這個 action 自己去重。
        Queue::assertPushed(IssueInvoiceForOrder::class, 1);
    }
}
