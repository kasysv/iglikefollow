<?php

namespace Tests\Feature;

use App\Actions\Orders\MarkPaymentPending;
use App\Actions\Orders\RecordPaymentResult;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\PaymentAttempt;
use App\Models\ServiceVariant;
use App\Support\Money;
use Database\Seeders\CatalogSeeder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * M3A-R1: transactional correctness of the order lifecycle.
 *
 * Tests passing is not the same as money being right. These cover the three
 * places where a green suite could still hide a real defect: an in-flight
 * payment recorded as finished, fulfilment triggered before the order is
 * durably paid, and prices losing precision on the way into the snapshot.
 */
class OrderTransactionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        Http::preventStrayRequests();
    }

    private function variant(string $sku = 'ig-followers-standard'): ServiceVariant
    {
        return ServiceVariant::query()->where('sku', $sku)->firstOrFail();
    }

    private function attempt(): PaymentAttempt
    {
        return PaymentAttempt::factory()->create([
            'order_id' => Order::factory()->create()->id,
        ]);
    }

    // ============================================ R1-1 非終止狀態

    public function test_recording_a_pending_result_is_refused(): void
    {
        $attempt = $this->attempt();

        $this->expectException(InvalidArgumentException::class);

        app(RecordPaymentResult::class)->handle($attempt, PaymentStatus::Pending);
    }

    public function test_recording_an_initiated_result_is_refused(): void
    {
        $attempt = $this->attempt();

        $this->expectException(InvalidArgumentException::class);

        app(RecordPaymentResult::class)->handle($attempt, PaymentStatus::Initiated);
    }

    public function test_a_refused_status_writes_nothing_at_all(): void
    {
        $attempt = $this->attempt();
        $order = $attempt->order;

        try {
            app(RecordPaymentResult::class)->handle($attempt, PaymentStatus::Pending);
        } catch (InvalidArgumentException) {
            // 預期。
        }

        $attempt->refresh();

        // ⛔ 拒絕必須發生在任何寫入之前。
        $this->assertSame(PaymentStatus::Initiated, $attempt->status);
        $this->assertNull($attempt->completed_at);
        $this->assertSame(0, $order->events()->count());
    }

    public function test_marking_pending_keeps_the_attempt_open(): void
    {
        $attempt = $this->attempt();

        app(MarkPaymentPending::class)->handle($attempt, 'TXN-PENDING');

        $attempt->refresh();

        $this->assertSame(PaymentStatus::Pending, $attempt->status);
        $this->assertTrue($attempt->status->isOpen());
        // ⛔ 尚未結束，不得寫入完成時間。
        $this->assertNull($attempt->completed_at);
        $this->assertSame('TXN-PENDING', $attempt->provider_reference);
    }

    public function test_marking_pending_creates_no_failure_event(): void
    {
        $attempt = $this->attempt();

        app(MarkPaymentPending::class)->handle($attempt);

        $order = $attempt->order->fresh();

        $this->assertSame(0, $order->events()->where('type', OrderEvent::TYPE_PAYMENT_FAILED)->count());
        // 訂單仍是待付款。
        $this->assertSame(OrderStatus::PendingPayment, $order->order_status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
    }

    public function test_a_pending_attempt_can_still_succeed_afterwards(): void
    {
        $attempt = $this->attempt();

        app(MarkPaymentPending::class)->handle($attempt);
        app(RecordPaymentResult::class)->handle($attempt->fresh(), PaymentStatus::Succeeded, 'TXN-OK');

        $this->assertSame(OrderStatus::Paid, $attempt->order->fresh()->order_status);
    }

    public function test_pending_cannot_overwrite_a_finished_attempt(): void
    {
        $attempt = $this->attempt();
        app(RecordPaymentResult::class)->handle($attempt, PaymentStatus::Failed);

        app(MarkPaymentPending::class)->handle($attempt->fresh());

        // ⛔ 已有結果的嘗試不得倒退回付款中。
        $this->assertSame(PaymentStatus::Failed, $attempt->fresh()->status);
    }

    public function test_the_mock_pending_outcome_leaves_an_open_attempt(): void
    {
        $this->post('/checkout/start', ['variant' => $this->variant()->id, 'quantity' => 1000]);
        $this->post('/checkout/mock', [
            'target' => 'example_account',
            'payment' => 'line-pay',
            'customer_email' => 'buyer@example.com',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'email',
            'fake_payment_result' => PaymentStatus::Pending->value,
        ])->assertOk();

        $order = Order::latest('id')->firstOrFail();
        $attempt = $order->paymentAttempts()->firstOrFail();

        $this->assertSame(PaymentStatus::Pending, $attempt->status);
        $this->assertNull($attempt->completed_at);
        $this->assertSame(OrderStatus::PendingPayment, $order->order_status);
        $this->assertSame(0, $order->events()->where('type', OrderEvent::TYPE_PAYMENT_FAILED)->count());
    }

    // ============================================ R1-2 after-commit event

    public function test_the_order_paid_event_defers_until_commit(): void
    {
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new OrderPaid(Order::factory()->create()));
    }

    public function test_a_rolled_back_transaction_dispatches_nothing(): void
    {
        $fired = 0;
        Event::listen(OrderPaid::class, function () use (&$fired) {
            $fired++;
        });

        $attempt = $this->attempt();

        try {
            DB::transaction(function () use ($attempt) {
                app(RecordPaymentResult::class)->handle($attempt, PaymentStatus::Succeeded, 'TXN-ROLLBACK');

                throw new \RuntimeException('模擬後續失敗');
            });
        } catch (\RuntimeException) {
            // 預期。
        }

        // ⛔ 訂單沒有真的付款，履約 seam 就不得觸發。
        $this->assertSame(0, $fired);
        $this->assertSame(OrderStatus::PendingPayment, $attempt->order->fresh()->order_status);
    }

    public function test_a_committed_success_dispatches_exactly_once(): void
    {
        $fired = 0;
        Event::listen(OrderPaid::class, function () use (&$fired) {
            $fired++;
        });

        $attempt = $this->attempt();

        app(RecordPaymentResult::class)->handle($attempt, PaymentStatus::Succeeded, 'TXN-COMMIT');

        $this->assertSame(1, $fired);
        $this->assertSame(OrderStatus::Paid, $attempt->order->fresh()->order_status);
    }

    public function test_repeated_notifications_dispatch_only_once_with_a_real_listener(): void
    {
        $fired = 0;
        Event::listen(OrderPaid::class, function () use (&$fired) {
            $fired++;
        });

        $attempt = $this->attempt();
        $action = app(RecordPaymentResult::class);

        $action->handle($attempt, PaymentStatus::Succeeded, 'TXN-DUP');
        $action->handle($attempt->fresh(), PaymentStatus::Succeeded, 'TXN-DUP');
        $action->handle($attempt->fresh(), PaymentStatus::Succeeded, 'TXN-DUP');

        // ⛔ 不能只靠 Event::fake 證明；這裡用真實 listener。
        $this->assertSame(1, $fired);

        // 資料庫層的冪等證據仍然保留。
        $this->assertSame(
            1,
            $attempt->order->events()->where('type', OrderEvent::TYPE_ORDER_PAID)->count()
        );
    }

    // ============================================ R1-3 四位小數與精確計價

    public function test_money_never_uses_binary_float(): void
    {
        $source = file_get_contents(app_path('Support/Money.php'));

        // ⛔ 金額運算不得出現 float 轉型。
        $this->assertStringNotContainsString('(float)', $source);
        $this->assertStringNotContainsString('floatval', $source);
    }

    public function test_a_four_decimal_price_is_calculated_exactly(): void
    {
        $variant = $this->variant();
        $variant->forceFill(['unit_price' => '0.1234'])->save();

        // 0.1234 × 10000 = 1234
        $this->assertSame(1234, $variant->fresh()->amountFor(10000));
    }

    public function test_half_a_dollar_rounds_up(): void
    {
        $variant = $this->variant();

        // 0.0005 × 1000 = 0.5 → 1
        $variant->forceFill(['unit_price' => '0.0005'])->save();
        $this->assertSame(1, $variant->fresh()->amountFor(1000));

        // 0.0015 × 1000 = 1.5 → 2
        $variant->forceFill(['unit_price' => '0.0015'])->save();
        $this->assertSame(2, $variant->fresh()->amountFor(1000));
    }

    public function test_a_four_decimal_price_survives_into_the_snapshot(): void
    {
        $variant = $this->variant();
        $variant->forceFill(['unit_price' => '0.1234'])->save();

        $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => 10000]);
        $this->post('/checkout/mock', [
            'target' => 'example_account',
            'payment' => 'line-pay',
            'customer_email' => 'buyer@example.com',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'email',
        ])->assertOk();

        $item = Order::latest('id')->firstOrFail()->items()->firstOrFail();

        // ⛔ 以「分」保存會變成 0.12；必須完整保留四位。
        $this->assertSame(1234, (int) $item->unit_price_mills);
        $this->assertSame('0.1234', $item->unitPrice());
        $this->assertSame(1234, $item->amount);
    }

    public function test_changing_the_price_afterwards_does_not_move_the_snapshot(): void
    {
        $variant = $this->variant();
        $variant->forceFill(['unit_price' => '0.1234'])->save();

        $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => 10000]);
        $this->post('/checkout/mock', [
            'target' => 'example_account', 'payment' => 'line-pay',
            'customer_email' => 'buyer@example.com',
            'invoice_kind' => 'personal', 'personal_invoice_mode' => 'email',
        ])->assertOk();

        $variant->forceFill(['unit_price' => '9.9999'])->save();

        $item = Order::latest('id')->firstOrFail()->items()->firstOrFail();

        $this->assertSame(1234, (int) $item->fresh()->unit_price_mills);
        $this->assertSame(1234, Order::latest('id')->value('total_amount'));
    }

    public function test_a_forged_amount_is_still_ignored(): void
    {
        $this->post('/checkout/start', ['variant' => $this->variant()->id, 'quantity' => 1000]);
        $this->post('/checkout/mock', [
            'target' => 'example_account', 'payment' => 'line-pay',
            'customer_email' => 'buyer@example.com',
            'invoice_kind' => 'personal', 'personal_invoice_mode' => 'email',
            'unit_price_mills' => 1, 'total_amount' => 1, 'amount' => 1, 'price' => 1,
        ])->assertOk();

        // 1000 × 0.59 = 590
        $this->assertSame(590, Order::latest('id')->value('total_amount'));
    }

    public function test_money_rejects_a_price_beyond_four_decimals(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toMills('0.12345');
    }

    // ============================================ R1-4 個資加密

    /**
     * 直接查資料庫不得看到明文。
     *
     * 用 DB facade 繞過 Eloquent cast，這才是備份外流或有人直接連資料庫時
     * 看到的內容。
     */
    public function test_raw_database_rows_contain_no_plaintext_personal_data(): void
    {
        $this->post('/checkout/start', ['variant' => $this->variant()->id, 'quantity' => 1000]);
        $this->post('/checkout/mock', [
            'target' => 'secret_ig_account',
            'payment' => 'line-pay',
            'customer_email' => 'private@example.com',
            'customer_phone' => '0912345678',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'mobile_barcode',
            'carrier_number' => '/ABC1234',
        ])->assertOk();

        $raw = json_encode([
            DB::table('orders')->get(),
            DB::table('order_items')->get(),
        ], JSON_UNESCAPED_UNICODE);

        foreach ([
            'private@example.com',
            '0912345678',
            '/ABC1234',
            'secret_ig_account',
        ] as $plaintext) {
            $this->assertStringNotContainsString($plaintext, $raw, "raw DB 出現明文：{$plaintext}");
        }
    }

    public function test_business_invoice_fields_are_also_encrypted(): void
    {
        $this->post('/checkout/start', ['variant' => $this->variant()->id, 'quantity' => 1000]);
        $this->post('/checkout/mock', [
            'target' => 'example_account', 'payment' => 'line-pay',
            'customer_email' => 'buyer@example.com',
            'invoice_kind' => 'business',
            'buyer_tax_id' => '12345678',
            'buyer_name' => '祕密股份有限公司',
        ])->assertOk();

        $raw = json_encode(DB::table('orders')->get(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('12345678', $raw);
        $this->assertStringNotContainsString('祕密股份有限公司', $raw);
    }

    public function test_the_model_still_reads_the_values_back(): void
    {
        $order = Order::factory()->create([
            'customer_email' => 'buyer@example.com',
            'customer_phone' => '0912345678',
            'buyer_tax_id' => '12345678',
        ]);

        $fresh = $order->fresh();

        // 加密只影響落盤；模型讀取與遮罩必須照常。
        $this->assertSame('buyer@example.com', $fresh->customer_email);
        $this->assertSame('0912345678', $fresh->customer_phone);
        $this->assertSame('b****@example.com', $fresh->maskedEmail());
        $this->assertSame('*******678', $fresh->maskedPhone());
    }

    public function test_non_sensitive_columns_stay_queryable(): void
    {
        $order = Order::factory()->create(['total_amount' => 590]);

        // ⛔ 狀態、金額與 reference 不加密，後台才能篩選與對帳。
        $raw = DB::table('orders')->where('reference', $order->reference)->first();

        $this->assertSame(590, (int) $raw->total_amount);
        $this->assertSame('pending_payment', $raw->order_status);
        $this->assertSame($order->reference, $raw->reference);
    }

    public function test_encrypted_columns_carry_no_index(): void
    {
        $indexes = collect(DB::select("PRAGMA index_list('orders')"))
            ->flatMap(fn ($i) => DB::select("PRAGMA index_info('{$i->name}')"))
            ->pluck('name');

        foreach (['customer_email', 'customer_phone', 'carrier_number', 'buyer_tax_id'] as $column) {
            $this->assertNotContains($column, $indexes, "加密欄位不得建索引：{$column}");
        }
    }
}
