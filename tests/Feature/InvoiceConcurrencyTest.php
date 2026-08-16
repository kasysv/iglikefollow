<?php

namespace Tests\Feature;

use App\Actions\Integrations\RecordCredentialAudit;
use App\Actions\Integrations\UpdateIntegrationCredentials;
use App\Actions\Invoices\CreateInvoiceForPaidOrder;
use App\Actions\Invoices\IssueInvoice;
use App\Contracts\InvoiceGateway;
use App\DTO\InvoiceIssueResult;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\InvoiceAttemptStatus;
use App\Enums\InvoiceFailureReason;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\IssueInvoiceForOrder;
use App\Models\AdminAuditLog;
use App\Models\IntegrationSetting;
use App\Models\Invoice;
use App\Models\InvoiceAttempt;
use App\Models\Order;
use App\Models\User;
use App\Services\Invoices\FakeInvoiceGateway;
use App\Support\M3bRollbackGuard;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * The parts of invoicing that only break on duplicate delivery or failure.
 *
 * Everything here defends one outcome: never issuing a second real invoice for
 * one order. That can happen three ways — two workers racing, a timeout being
 * mistaken for a failure, or a redelivered job computing a fresh idempotency
 * key — and each has its own test below.
 *
 * ⛔ These are duplicate and interleaved *intent* simulations, not real
 * OS-level concurrent workers: PHPUnit runs in one process, so it cannot make
 * two transactions contend for the same row. What the tests do establish is
 * that a second handler finds the invoice no longer claimable and stops. The
 * actual race is held off by `lockForUpdate()` and the unique index, which
 * this suite can only assert indirectly.
 */
class InvoiceConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private FakeInvoiceGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->gateway = new FakeInvoiceGateway;
        $this->app->instance(InvoiceGateway::class, $this->gateway);
    }

    private function paidOrder(int $amount = 590): Order
    {
        return Order::factory()->create([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'total_amount' => $amount,
            'paid_at' => now(),
        ])->fresh();
    }

    private function configuredGateway(): void
    {
        $setting = IntegrationSetting::factory()
            ->forProvider(IntegrationProvider::EcpayInvoice, IntegrationEnvironment::Sandbox)
            ->configured()->create();

        DB::table('integration_settings')->where('id', $setting->id)->update(['is_enabled' => true]);
    }

    private function pendingInvoice(): Invoice
    {
        $this->configuredGateway();

        return app(CreateInvoiceForPaidOrder::class)->handle($this->paidOrder());
    }

    // ==================================== A. 真正的 queue unique 與 atomic claim

    public function test_the_job_implements_the_real_unique_contract(): void
    {
        // ⛔ 只寫 uniqueId() 而沒有這個介面，Laravel 根本不會去看它：
        // 那個方法會變成一段沒人呼叫的程式碼，同一張訂單仍可能被並行處理。
        $this->assertInstanceOf(ShouldBeUnique::class, new IssueInvoiceForOrder(1));
    }

    public function test_the_unique_key_does_not_change_between_deliveries(): void
    {
        $job = new IssueInvoiceForOrder(42);

        // 重複投遞必須算出同一把鎖。
        $this->assertSame('invoice-order-42', $job->uniqueId());
        $this->assertSame('invoice-order-42', (new IssueInvoiceForOrder(42))->uniqueId());
    }

    public function test_the_idempotency_key_is_stable_across_attempts(): void
    {
        $invoice = $this->pendingInvoice();

        $first = $invoice->initialIdempotencyKey();

        // 即使已經有一筆嘗試，鍵也不得改變。
        InvoiceAttempt::create([
            'invoice_id' => $invoice->id,
            'idempotency_key' => $first,
            'status' => InvoiceAttemptStatus::Ambiguous,
        ]);

        // ⛔ 用 count()+1 推導的鍵會在這裡變成另一個值，unique 就永遠擋不住。
        $this->assertSame($first, $invoice->fresh()->initialIdempotencyKey());
    }

    /**
     * 兩個「意圖」依序執行，⛔ 不是真正的 OS 層並行。
     *
     * 這個測試模擬的是重複投遞：第二個 handler 拿著同一張發票再跑一次。真正的
     * race 需要兩個行程同時打同一列，PHPUnit 單行程做不到——實際的保證來自
     * `lockForUpdate()` 的 compare-and-set 與 DB unique index，這裡只證明
     * 「狀態已不是 pending 時不會再呼叫 provider」。
     */
    public function test_a_second_issuing_intent_does_not_call_the_provider_again(): void
    {
        $invoice = $this->pendingInvoice();

        $a = new IssueInvoice($this->gateway);
        $b = new IssueInvoice($this->gateway);

        $a->handle($invoice);
        $b->handle($invoice->fresh());

        // ⛔ 第二次不得呼叫 provider，也不得再建一筆嘗試。
        $this->assertCount(1, $this->gateway->calls);
        $this->assertSame(1, InvoiceAttempt::count());
    }

    /** 交錯情境：另一個 handler 已經 claim 走（以直接改狀態模擬），⛔ 非真實並行。 */
    public function test_an_interleaved_handler_arriving_after_processing_does_not_call_the_gateway(): void
    {
        $invoice = $this->pendingInvoice();

        // 模擬另一個 handler 已經 claim 走。
        DB::table('invoices')->where('id', $invoice->id)
            ->update(['status' => InvoiceStatus::Processing->value]);

        (new IssueInvoice($this->gateway))->handle($invoice->fresh());

        $this->assertSame([], $this->gateway->calls);
        $this->assertSame(0, InvoiceAttempt::count());
    }

    public function test_repeated_job_delivery_produces_one_provider_call(): void
    {
        $this->configuredGateway();
        $order = $this->paidOrder();

        for ($i = 0; $i < 5; $i++) {
            (new IssueInvoiceForOrder($order->id))->handle(
                app(CreateInvoiceForPaidOrder::class),
                new IssueInvoice($this->gateway)
            );
        }

        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, InvoiceAttempt::count());
        $this->assertCount(1, $this->gateway->calls);
    }

    // ==================================== B. 例外一律轉人工對帳

    public function test_a_gateway_exception_becomes_reconciliation_required(): void
    {
        $invoice = $this->pendingInvoice();

        $throwing = new class implements InvoiceGateway
        {
            public function issue(Invoice $invoice, string $idempotencyKey): InvoiceIssueResult
            {
                throw new RuntimeException('connect timeout to 10.0.0.1 with MerchantID SECRET123');
            }
        };

        $result = (new IssueInvoice($throwing))->handle($invoice);

        // ⛔ 不得停在 processing／started：那樣既不會重試也沒人看得到。
        $this->assertSame(InvoiceStatus::ReconciliationRequired, $result->status);
        $this->assertSame(InvoiceAttemptStatus::Ambiguous, InvoiceAttempt::firstOrFail()->status);
        $this->assertNotNull($result->reconciliation_required_at);
    }

    public function test_a_gateway_exception_does_not_leak_its_message(): void
    {
        $invoice = $this->pendingInvoice();

        $throwing = new class implements InvoiceGateway
        {
            public function issue(Invoice $invoice, string $idempotencyKey): InvoiceIssueResult
            {
                throw new RuntimeException('MerchantID=SECRET123 buyer@example.com HashKey=LEAKME');
            }
        };

        Log::spy();

        (new IssueInvoice($throwing))->handle($invoice);

        $raw = json_encode([
            DB::table('invoices')->get(),
            DB::table('invoice_attempts')->get(),
            DB::table('admin_audit_logs')->get(),
        ], JSON_UNESCAPED_UNICODE);

        // ⛔ 固定訊息：原始例外內容可能帶著憑證與個資。
        foreach (['SECRET123', 'buyer@example.com', 'LEAKME', '10.0.0.1'] as $marker) {
            $this->assertStringNotContainsString($marker, $raw, "落盤出現敏感字串：{$marker}");
        }

        $this->assertSame(InvoiceFailureReason::Unknown->value, Invoice::firstOrFail()->failure_code);
    }

    public function test_a_gateway_exception_is_not_rethrown_for_queue_retry(): void
    {
        $invoice = $this->pendingInvoice();

        $throwing = new class implements InvoiceGateway
        {
            public int $calls = 0;

            public function issue(Invoice $invoice, string $idempotencyKey): InvoiceIssueResult
            {
                $this->calls++;

                throw new RuntimeException('timeout');
            }
        };

        $action = new IssueInvoice($throwing);

        // ⛔ 不丟出：丟出會讓 queue 自動重試，等於再打一次 provider。
        $action->handle($invoice);
        $action->handle($invoice->fresh());
        $action->handle($invoice->fresh());

        $this->assertSame(1, $throwing->calls);
    }

    // ==================================== C. credential 與 audit 原子化

    public function test_a_failing_audit_rolls_back_the_credential_write(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner', 'is_active' => true]));

        // 先建立一組原值。
        app(UpdateIntegrationCredentials::class)->handle(
            IntegrationProvider::LinePay, IntegrationEnvironment::Sandbox,
            'channel-1', ['ChannelSecret' => 'original-secret']
        );

        // 讓稽核寫入必定失敗。
        $this->app->bind(RecordCredentialAudit::class, fn () => new class extends RecordCredentialAudit
        {
            public function handle($provider, $environment, array $changedFields): void
            {
                throw new RuntimeException('audit 寫入失敗');
            }
        });

        try {
            app(UpdateIntegrationCredentials::class)->handle(
                IntegrationProvider::LinePay, IntegrationEnvironment::Sandbox,
                'channel-2', ['ChannelSecret' => 'rotated-secret']
            );
            $this->fail('稽核失敗時應該整筆 rollback。');
        } catch (RuntimeException) {
            // 預期。
        }

        $setting = IntegrationSetting::where('provider', IntegrationProvider::LinePay)->firstOrFail();

        // ⛔ 憑證與識別碼都必須維持原值，不得留下半套寫入。
        $this->assertSame('original-secret', $setting->secret('ChannelSecret'));
        $this->assertSame('channel-1', $setting->identifier);
    }

    public function test_the_audit_is_written_in_the_same_transaction(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner', 'is_active' => true]));

        app(UpdateIntegrationCredentials::class)->handle(
            IntegrationProvider::LinePay, IntegrationEnvironment::Sandbox,
            'channel-1', ['ChannelSecret' => 'a-secret']
        );

        // 呼叫 service 就會有稽核，⛔ 不需要頁面另外補一次。
        $this->assertSame(1, AdminAuditLog::where('action', 'credentials_updated')->count());
    }

    // ==================================== D. batch rollback 不得部分成功

    public function test_the_rollback_guard_checks_all_three_tables(): void
    {
        // 只有 settings 有資料。
        IntegrationSetting::factory()->create();

        try {
            M3bRollbackGuard::assertAllTablesAreEmpty();
            $this->fail('有資料時應該中止。');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('無法回滾', $e->getMessage());
            $this->assertStringContainsString('尚未刪除任何資料表', $e->getMessage());
        }
    }

    public function test_the_first_migration_to_run_refuses_when_another_table_has_data(): void
    {
        // ⛔ 這是關鍵情境：batch rollback 先跑 invoice_attempts 的 down()，
        // 而有資料的是 integration_settings。舊版會先 drop 兩張表才報錯。
        IntegrationSetting::factory()->create();

        $attempts = require database_path('migrations/2026_08_17_100002_create_invoice_attempts_table.php');

        try {
            $attempts->down();
            $this->fail('應該在 drop 任何表之前中止。');
        } catch (RuntimeException) {
            // 預期。
        }

        // 三張表都必須還在。
        foreach (['invoice_attempts', 'invoices', 'integration_settings'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} 被刪掉了");
        }
    }

    public function test_an_invoice_only_dataset_also_blocks_the_rollback(): void
    {
        Invoice::factory()->create();

        $attempts = require database_path('migrations/2026_08_17_100002_create_invoice_attempts_table.php');

        try {
            $attempts->down();
            $this->fail('應該中止。');
        } catch (RuntimeException) {
            // 預期。
        }

        $this->assertSame(1, Invoice::count());
        $this->assertTrue(Schema::hasTable('invoices'));
    }

    // ==================================== E. DB 與 domain 雙層 invariant

    public function test_a_non_twd_order_cannot_be_invoiced(): void
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'total_amount' => 590,
            'currency' => 'USD',
            'paid_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);

        app(CreateInvoiceForPaidOrder::class)->handle($order->fresh());
    }

    public function test_the_database_refuses_a_zero_amount_invoice(): void
    {
        $order = $this->paidOrder();

        // ⛔ 繞過模型直接寫入也必須被擋下。
        $this->expectException(QueryException::class);

        DB::table('invoices')->insert([
            'order_id' => $order->id, 'provider' => 'ecpay_invoice', 'status' => 'pending',
            'amount' => 0, 'currency' => 'TWD', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_database_refuses_a_non_twd_invoice(): void
    {
        $order = $this->paidOrder();

        $this->expectException(QueryException::class);

        DB::table('invoices')->insert([
            'order_id' => $order->id, 'provider' => 'ecpay_invoice', 'status' => 'pending',
            'amount' => 590, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_database_refuses_an_unknown_status(): void
    {
        $order = $this->paidOrder();

        $this->expectException(QueryException::class);

        DB::table('invoices')->insert([
            'order_id' => $order->id, 'provider' => 'ecpay_invoice', 'status' => 'whatever',
            'amount' => 590, 'currency' => 'TWD', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_database_refuses_an_unknown_attempt_status(): void
    {
        $invoice = Invoice::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('invoice_attempts')->insert([
            'invoice_id' => $invoice->id, 'idempotency_key' => 'k-1', 'status' => 'nonsense',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_an_issued_invoice_cannot_go_back_to_pending(): void
    {
        $invoice = Invoice::factory()->issued()->create();

        // ⛔ 回到 pending 就會被重新開立，等於同一張訂單開出第二張發票。
        $this->expectException(ValidationException::class);

        $invoice->forceFill(['status' => InvoiceStatus::Pending])->save();
    }

    public function test_a_voided_invoice_is_final(): void
    {
        $invoice = Invoice::factory()->issued()->create();
        $invoice->forceFill(['status' => InvoiceStatus::Voided, 'voided_at' => now()])->save();

        $this->expectException(ValidationException::class);

        $invoice->fresh()->forceFill(['status' => InvoiceStatus::Issued])->save();
    }

    public function test_a_failed_result_cannot_overwrite_an_issued_invoice(): void
    {
        $invoice = Invoice::factory()->issued()->create();

        $this->expectException(ValidationException::class);

        $invoice->forceFill(['status' => InvoiceStatus::Failed])->save();
    }

    public function test_deleting_an_order_cannot_delete_its_invoice(): void
    {
        $this->configuredGateway();
        $order = $this->paidOrder();
        app(CreateInvoiceForPaidOrder::class)->handle($order);

        // ⛔ 稅務憑證不得因為刪一張訂單就一起消失。
        $this->expectException(QueryException::class);

        DB::table('orders')->where('id', $order->id)->delete();
    }

    public function test_deleting_an_invoice_cannot_delete_its_attempts(): void
    {
        $invoice = Invoice::factory()->create();
        InvoiceAttempt::create([
            'invoice_id' => $invoice->id,
            'idempotency_key' => 'k-1',
            'status' => InvoiceAttemptStatus::Succeeded,
        ]);

        $this->expectException(QueryException::class);

        DB::table('invoices')->where('id', $invoice->id)->delete();
    }
}
