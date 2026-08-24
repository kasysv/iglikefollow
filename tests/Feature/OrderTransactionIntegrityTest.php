<?php

namespace Tests\Feature;

use App\Actions\Orders\MarkPaymentPending;
use App\Actions\Orders\RecordPaymentResult;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPaid;
use App\Exceptions\UnsellablePriceException;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\ServiceVariant;
use App\Support\Money;
use Database\Seeders\CatalogSeeder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
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

    /**
     * A variant priced at NT$0.1234 per unit.
     *
     * The step is widened to 10000 at the same time: at that rate no smaller
     * quantity comes to a whole number of dollars, so any narrower step would
     * be a configuration the guard is right to reject.
     */
    private function fourDecimalVariant(): ServiceVariant
    {
        $variant = $this->variant();

        $variant->forceFill([
            'unit_price' => '0.1234',
            'min_quantity' => 10000,
            'max_quantity' => 100000,
            'step_quantity' => 10000,
            'default_quantity' => 10000,
        ])->save();

        return $variant;
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

                throw new RuntimeException('模擬後續失敗');
            });
        } catch (RuntimeException) {
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
        // 0.1234 元／個要能收款，階距就必須是 10000 的倍數。
        $variant = $this->fourDecimalVariant();

        // 0.1234 × 10000 = 1234
        $this->assertSame(1234, $variant->fresh()->amountFor(10000));
    }

    /**
     * ⛔ M3A:小數台幣改為 half-up 四捨五入,不再拋錯。
     *
     * Owner 決定顧客可買範圍內任何整數,小數金額因此成為常態而非設定錯誤。
     * 0.59 × 1001 = 590.59 → NT$591。
     */
    public function test_a_fractional_amount_is_rounded_half_up(): void
    {
        $variant = $this->variant();

        $this->assertSame(591, Money::total($variant->unitPriceMills(), 1001));
    }

    /**
     * ⛔ 精確的 half-up 邊界:餘數 4,999 捨去、5,000 進位。
     *
     * 這是本輪金額規則的核心,⛔ 必須直接構造餘數驗證,不能只用「看起來
     * 像 .5」的十進位數字帶過,也不得是 banker's rounding。
     */
    public function test_the_half_up_boundary_is_exact_in_both_directions(): void
    {
        // 餘數 4,999 → 捨去。
        $this->assertSame(1, Money::total(Money::toMills('1.4999'), 1));

        // 餘數 5,000 → 進位。
        $this->assertSame(2, Money::total(Money::toMills('1.5000'), 1));

        // ⛔ banker's rounding 會把 2.5 收成 2;half-up 必須是 3。
        $this->assertSame(3, Money::total(Money::toMills('2.5000'), 1));

        // 0.0005 × 1000 = 0.5 元 → 1 元;0.0015 × 1000 = 1.5 元 → 2 元。
        $this->assertSame(1, Money::total(Money::toMills('0.0005'), 1000));
        $this->assertSame(2, Money::total(Money::toMills('0.0015'), 1000));

        // divides() 仍只回答「是否剛好整除」;⛔ 與可否販售是兩個問題。
        $this->assertFalse(Money::divides(Money::toMills('0.0005'), 1000));
        $this->assertTrue(Money::divides(Money::toMills('0.0005'), 2000));
    }

    /** ⛔ 四捨五入後不足 1 元仍然拒絕:不建 NT$0 訂單,也不暗自墊到 1。 */
    public function test_an_amount_rounding_to_zero_is_still_refused(): void
    {
        // 0.0001 × 1 = 0.0001 元 → 四捨五入為 0。
        $this->expectException(UnsellablePriceException::class);

        Money::total(Money::toMills('0.0001'), 1);
    }

    public function test_a_variant_whose_minimum_rounds_to_zero_cannot_be_saved(): void
    {
        $variant = $this->variant();

        // ⛔ M3A:0.59 × 1001 = 590.59 已經合法(四捨五入 591)。
        // 四捨五入救不了的只剩「最低數量的金額仍不足 1 元」。
        $this->expectException(ValidationException::class);

        $variant->forceFill([
            'unit_price' => '0.0001',
            'min_quantity' => 1,
            'default_quantity' => 1,
        ])->save();
    }

    /**
     * ⛔ M3A:小數金額不再被 checkout 拒絕,但「四捨五入後收不到錢」仍然是。
     *
     * 用既有髒資料模擬:單價 0.0001、最低 1 → 買 1 個是 0 元,必須擋下且
     * 不留下任何 order／item／attempt。
     */
    public function test_checkout_refuses_a_quantity_whose_amount_rounds_to_zero(): void
    {
        $variant = $this->variant();

        // 繞過後台驗證直接寫入不合規設定，模擬既有髒資料。
        DB::table('service_variants')->where('id', $variant->id)
            ->update(['unit_price' => '0.0001', 'step_quantity' => 1, 'min_quantity' => 1]);

        $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => 1])
            ->assertRedirect();

        // ⛔ 不建任何 order／item／attempt。
        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());
        $this->assertSame(0, PaymentAttempt::count());
    }

    /** ⛔ 相對地,會產生小數的數量現在必須被接受並四捨五入。 */
    public function test_checkout_accepts_a_quantity_that_produces_a_fractional_amount(): void
    {
        $variant = $this->variant();   // 0.59／個

        DB::table('service_variants')->where('id', $variant->id)
            ->update(['step_quantity' => 1, 'min_quantity' => 10, 'max_quantity' => 10000]);

        $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => 101])
            ->assertRedirect('/checkout');

        // 0.59 × 101 = 59.59 → NT$60。
        $this->assertSame(60, $variant->fresh()->amountFor(101));
    }

    public function test_a_four_decimal_price_survives_into_the_snapshot(): void
    {
        $variant = $this->fourDecimalVariant();

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
        $variant = $this->fourDecimalVariant();

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

    // ==================================== R2-2／R2-3 既有明文 backfill 與可逆 rollback

    /** The PII migration, instantiated directly so up() and down() can be driven. */
    private function piiMigration(): object
    {
        return require database_path(
            'migrations/2026_08_16_110001_widen_order_pii_columns_for_encryption.php'
        );
    }

    /**
     * Write a row the way pre-R1 code did: plaintext, straight past the casts.
     *
     * @return array{order_id: int, item_id: int}
     */
    private function seedPlaintextOrder(): array
    {
        $orderId = DB::table('orders')->insertGetId([
            'reference' => 'IGL-PLAINTEXT01',
            'checkout_token' => 'tok-plaintext-1',
            'order_status' => 'pending_payment',
            'payment_status' => 'initiated',
            'total_amount' => 590,
            'currency' => 'TWD',
            'customer_email' => 'legacy@example.com',
            'customer_phone' => '0987654321',
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'mobile_barcode',
            'carrier_number' => '/LEGACY1',
            'buyer_tax_id' => '87654321',
            'buyer_name' => '舊資料股份有限公司',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'platform_name' => 'Instagram',
            'service_name' => '粉絲',
            'variant_label' => '一般粉絲',
            'unit_price_mills' => 5900,
            'unit_price_cents' => 59,
            'quantity' => 1000,
            'quantity_unit' => '個',
            'amount' => 590,
            'target_kind' => 'account',
            'target_value' => 'legacy_account',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_attempts')->insert([
            'order_id' => $orderId,
            'provider' => 'line-pay',
            'reference' => 'PAY-LEGACY000001',
            'status' => 'initiated',
            'amount' => 590,
            'currency' => 'TWD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('order_events')->insert([
            'order_id' => $orderId,
            'type' => 'order_created',
            'summary' => '舊資料事件',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['order_id' => $orderId, 'item_id' => $itemId];
    }

    /**
     * A checksum of every order table, row by row.
     *
     * ⛔ Counting rows is not enough — that is exactly how the cascade bug
     * survived R1 review. This hashes the full contents so a dropped, added or
     * altered row in any of the four tables shows up.
     *
     * @return array<string, string>
     */
    private function orderTablesChecksum(): array
    {
        $checksum = [];

        foreach (['orders', 'order_items', 'payment_attempts', 'order_events'] as $table) {
            $rows = DB::table($table)->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)->all();

            $checksum[$table] = count($rows).':'.md5(json_encode($rows));
        }

        return $checksum;
    }

    public function test_the_migration_encrypts_rows_that_were_already_plaintext(): void
    {
        $ids = $this->seedPlaintextOrder();

        // 這一列在 migration 之前就是明文，⛔ up() 必須把它一起加密。
        $this->piiMigration()->up();

        $raw = json_encode([
            DB::table('orders')->where('id', $ids['order_id'])->get(),
            DB::table('order_items')->where('id', $ids['item_id'])->get(),
        ], JSON_UNESCAPED_UNICODE);

        foreach ([
            'legacy@example.com', '0987654321', '/LEGACY1',
            '87654321', '舊資料股份有限公司', 'legacy_account',
        ] as $plaintext) {
            $this->assertStringNotContainsString($plaintext, $raw, "backfill 後仍有明文：{$plaintext}");
        }

        // 模型仍讀得回原值，否則就只是把資料弄壞而已。
        $order = Order::find($ids['order_id']);
        $this->assertSame('legacy@example.com', $order->customer_email);
        $this->assertSame('0987654321', $order->customer_phone);
        $this->assertSame('舊資料股份有限公司', $order->buyer_name);
        $this->assertSame('legacy_account', OrderItem::find($ids['item_id'])->target_value);
    }

    /**
     * ⛔ 這個 migration 不得動到任何一列訂單資料。
     *
     * SQLite 沒有真正的 ALTER COLUMN：driver 會整張表重建，而 drop `orders`
     * 會觸發所有子表的 ON DELETE CASCADE——order_items、payment_attempts 與
     * order_events 都會被清空。R1 只數了 orders，R2 只數了 order_items，兩輪
     * 都因此漏掉。這裡改用四張表的逐列 checksum，⛔ 不只數筆數。
     */
    public function test_the_pii_migration_preserves_every_order_table(): void
    {
        $this->seedPlaintextOrder();

        $before = $this->orderTablesChecksum();

        $migration = $this->piiMigration();
        $migration->up();

        // 加密會改變 orders／order_items 的內容，但子表完全不該被碰到。
        $afterUp = $this->orderTablesChecksum();
        $this->assertSame($before['payment_attempts'], $afterUp['payment_attempts'], 'up() 動到了付款嘗試');
        $this->assertSame($before['order_events'], $afterUp['order_events'], 'up() 動到了訂單事件');
        $this->assertStringStartsWith('1:', $afterUp['orders']);
        $this->assertStringStartsWith('1:', $afterUp['order_items']);

        $migration->down();

        // 回到原點後，四張表必須逐列完全相同。
        $this->assertSame($before, $this->orderTablesChecksum(), 'up/down 之後訂單資料漂移');
    }

    /**
     * 同樣的保證，這次包在外層 transaction 裡。
     *
     * GPT 的探針正是在這個情境下抓到子表被清空：SQLite 在 transaction 內會
     * 忽略 `PRAGMA foreign_keys`，所以任何依賴該 pragma 的防禦都會失效。
     */
    public function test_the_pii_migration_preserves_every_order_table_inside_a_transaction(): void
    {
        $this->seedPlaintextOrder();

        $before = $this->orderTablesChecksum();

        DB::transaction(function () {
            $migration = $this->piiMigration();
            $migration->up();
            $migration->down();
        });

        $this->assertSame($before, $this->orderTablesChecksum(), 'transaction 內 migration 造成資料漂移');
    }

    public function test_the_pii_migration_encrypts_inside_a_transaction_too(): void
    {
        $this->seedPlaintextOrder();

        DB::transaction(fn () => $this->piiMigration()->up());

        $raw = json_encode([
            DB::table('orders')->get(),
            DB::table('order_items')->get(),
        ], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('legacy@example.com', $raw);
        $this->assertStringNotContainsString('legacy_account', $raw);

        // 子表仍在。
        $this->assertSame(1, DB::table('payment_attempts')->count());
        $this->assertSame(1, DB::table('order_events')->count());
    }

    public function test_the_pii_rollback_restores_the_exact_plaintext(): void
    {
        $ids = $this->seedPlaintextOrder();
        $before = (array) DB::table('orders')->where('id', $ids['order_id'])->first();

        $migration = $this->piiMigration();
        $migration->up();
        $migration->down();

        $after = (array) DB::table('orders')->where('id', $ids['order_id'])->first();

        // ⛔ 值、nullability 與列數必須完全相同：rollback 不得截斷或留下密文。
        $this->assertSame($before, $after);
        $this->assertSame(
            'legacy_account',
            DB::table('order_items')->where('id', $ids['item_id'])->value('target_value')
        );
        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_the_pii_rollback_leaves_optional_nulls_null(): void
    {
        $orderId = DB::table('orders')->insertGetId([
            'reference' => 'IGL-NULLS000001',
            'checkout_token' => 'tok-nulls-1',
            'order_status' => 'pending_payment',
            'payment_status' => 'initiated',
            'total_amount' => 590,
            'currency' => 'TWD',
            'customer_email' => 'nulls@example.com',
            'customer_phone' => null,
            'invoice_kind' => 'personal',
            'carrier_number' => null,
            'buyer_tax_id' => null,
            'buyer_name' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = $this->piiMigration();
        $migration->up();
        $migration->down();

        $row = DB::table('orders')->where('id', $orderId)->first();

        // ⛔ 空值不得被寫成空字串或密文。
        $this->assertNull($row->customer_phone);
        $this->assertNull($row->carrier_number);
        $this->assertNull($row->buyer_name);
        $this->assertSame('nulls@example.com', $row->customer_email);
    }

    // ==================================== R2-4 價格快照 rollback 不得損失精度

    private function millsMigration(): object
    {
        return require database_path(
            'migrations/2026_08_16_110000_store_order_item_unit_price_in_mills.php'
        );
    }

    public function test_the_legacy_cents_column_is_kept_for_the_rollback_window(): void
    {
        $this->post('/checkout/start', ['variant' => $this->variant()->id, 'quantity' => 1000]);
        $this->post('/checkout/mock', [
            'target' => 'example_account', 'payment' => 'line-pay',
            'customer_email' => 'buyer@example.com',
            'invoice_kind' => 'personal', 'personal_invoice_mode' => 'email',
        ])->assertOk();

        $item = DB::table('order_items')->latest('id')->first();

        // 新程式寫精確的毫，同時維護 legacy 分供回退後的舊程式讀取。
        $this->assertSame(5900, (int) $item->unit_price_mills);
        $this->assertSame(59, (int) $item->unit_price_cents);
    }

    public function test_a_code_only_rollback_keeps_the_exact_price(): void
    {
        $ids = $this->seedPlaintextOrder();

        DB::table('order_items')->where('id', $ids['item_id'])
            ->update(['unit_price_mills' => 1234, 'unit_price_cents' => 12]);

        // 正式 rollback 只回退程式碼，不動 schema：精確值原封不動留在資料庫。
        $this->assertSame(
            1234,
            (int) DB::table('order_items')->where('id', $ids['item_id'])->value('unit_price_mills')
        );
    }

    public function test_a_destructive_price_rollback_fails_closed_instead_of_losing_precision(): void
    {
        $ids = $this->seedPlaintextOrder();

        // 1234 毫無法用「分」表達，⛔ down() 必須中止而不是變成 1200。
        DB::table('order_items')->where('id', $ids['item_id'])->update(['unit_price_mills' => 1234]);

        try {
            $this->millsMigration()->down();
            $this->fail('down() 應該中止，卻靜默丟失了精度。');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('無法回滾', $e->getMessage());
        }

        // 資料完全沒動。
        $this->assertSame(
            1234,
            (int) DB::table('order_items')->where('id', $ids['item_id'])->value('unit_price_mills')
        );
    }

    public function test_a_lossless_price_rollback_and_re_forward_keeps_the_value(): void
    {
        $ids = $this->seedPlaintextOrder();

        $migration = $this->millsMigration();
        $migration->down();   // 5900 毫 = 59 分，可無損表達。
        $migration->up();

        $this->assertSame(
            5900,
            (int) DB::table('order_items')->where('id', $ids['item_id'])->value('unit_price_mills')
        );
    }

    // ==================================== R3-2 合法數量必須從真正買得到的量算起

    /** An unsaved variant carrying exactly the price and quantity rules given. */
    private function probeVariant(string $rate, int $min, int $max, int $step): ServiceVariant
    {
        $variant = new ServiceVariant;

        $variant->setRawAttributes([
            'unit_price' => $rate,
            'min_quantity' => $min,
            'max_quantity' => $max,
            'step_quantity' => $step,
        ], true);

        return $variant;
    }

    /**
     * 購買規則是 `quantity % step === 0`，所以 min 不是 step 倍數時，
     * min 本身根本買不到。⛔ 驗證必須從第一個真正買得到的數量開始。
     */
    /** ⛔ M3A:第一個可購數量就是 min 本身,legacy step 不得再抬高它。 */
    public function test_validation_starts_at_the_minimum_quantity(): void
    {
        // legacy step 100 仍在資料裡;min 101 現在就是第一個可購數量。
        $variant = $this->probeVariant('0.5000', 101, 200, 100);

        $this->assertSame(101, $variant->firstPurchasableQuantity());
        $this->assertNull($variant->firstUnpayableQuantity());
        $this->assertTrue($variant->quantityIsValid(101));

        // 0.5 × 101 = 50.5 → half-up 為 51。
        $this->assertSame(51, $variant->amountFor(101));
    }

    /**
     * ⛔ M3A:窄範圍不再是錯誤——[101,199] 內每個整數都買得到。
     *
     * 原測試主張這種設定必須被拒絕(沒有 100 的倍數),那正是 legacy step
     * 造成的假性錯誤。真正該被拒絕的是空範圍。
     */
    public function test_a_narrow_range_is_now_purchasable(): void
    {
        $variant = $this->probeVariant('0.5000', 101, 199, 100);

        $this->assertSame(101, $variant->firstPurchasableQuantity());
        $this->assertTrue($variant->quantityIsValid(150));

        // ⛔ 空範圍(max < min)仍然是結構錯誤。
        $saved = $this->variant();
        $this->expectException(ValidationException::class);

        $saved->forceFill([
            'min_quantity' => 199, 'max_quantity' => 101, 'default_quantity' => 150,
        ])->save();
    }

    /** ⛔ 回報的「收不到錢」數量必須是客人真的選得到的(在範圍內)。 */
    public function test_the_offending_quantity_reported_is_one_a_customer_could_pick(): void
    {
        // 0.0001／個、min 150:150 × 0.0001 = 0.015 元 → 四捨五入為 0。
        $variant = $this->probeVariant('0.0001', 150, 1000, 100);

        $offending = $variant->firstUnpayableQuantity();

        $this->assertSame(150, $offending);
        $this->assertGreaterThanOrEqual(150, $offending);
        $this->assertLessThanOrEqual(1000, $offending);

        // 對照:單價正常時整段範圍都收得到錢。
        $ok = $this->probeVariant('0.5900', 150, 1000, 100);
        $this->assertNull($ok->firstUnpayableQuantity());
        $this->assertSame(150, $ok->firstPurchasableQuantity());
    }

    // ==================================== R3-3 負數／零／overflow 不得繞過

    public function test_a_negative_unit_price_cannot_produce_an_amount(): void
    {
        $variant = $this->probeVariant('-1.0000', 1, 10, 1);

        // ⛔ probe 曾經算出 -1；現在必須直接拒絕。
        $this->assertFalse($variant->quantityIsValid(1));

        $this->expectException(UnsellablePriceException::class);
        $variant->amountFor(1);
    }

    public function test_a_zero_unit_price_cannot_produce_an_amount(): void
    {
        $variant = $this->probeVariant('0.0000', 1, 10, 1);

        $this->assertFalse($variant->quantityIsValid(1));

        $this->expectException(UnsellablePriceException::class);
        $variant->amountFor(1);
    }

    public function test_a_negative_or_zero_price_cannot_be_saved(): void
    {
        $variant = $this->variant();

        $this->expectException(ValidationException::class);
        $variant->forceFill(['unit_price' => '-1.0000'])->save();
    }

    public function test_a_zero_price_cannot_be_saved(): void
    {
        $variant = $this->variant();

        $this->expectException(ValidationException::class);
        $variant->forceFill(['unit_price' => '0.0000'])->save();
    }

    public function test_an_overflowing_amount_is_refused_not_silently_floated(): void
    {
        // ⛔ PHP 會把整數溢位悄悄變成 float，那樣所有精確性保證都失效。
        $this->expectException(UnsellablePriceException::class);

        Money::total(PHP_INT_MAX, 999);
    }

    /**
     * ⛔ M3A:legacy step 0／負值不得再影響任何事,也絕不產生 PHP error。
     *
     * 舊規則的除零風險來自 `quantity % step`;那段程式已經移除,所以正確
     * 行為從「fail closed」變成「照常可購」——一筆 min/max 正常、只是舊
     * step 為 0 的資料,顧客本來就該買得到。原本的安全性質(無 warning、
     * 無 DivisionByZeroError)仍逐一斷言。
     */
    public function test_a_legacy_corrupt_step_is_ignored_without_any_php_error(): void
    {
        $variant = $this->variant();

        foreach ([0, -100] as $badStep) {
            DB::table('service_variants')->where('id', $variant->id)
                ->update(['step_quantity' => $badStep]);

            $fresh = ServiceVariant::query()->findOrFail($variant->id);

            set_error_handler(function (int $errno, string $errstr): bool {
                $this->fail('⛔ legacy step 產生了 PHP error:'.$errstr);
            });

            try {
                // min 100／max 10000:範圍內任何整數皆可,與 step 無關。
                $this->assertTrue($fresh->quantityIsValid(100));
                $this->assertTrue($fresh->quantityIsValid(101));
                $this->assertTrue($fresh->quantityIsValid(1000));
                $this->assertSame(100, $fresh->firstPurchasableQuantity());
            } finally {
                restore_error_handler();
            }

            // ⛔ 範圍外仍然拒絕。
            $this->assertFalse($fresh->quantityIsValid(99));
        }
    }

    /** ⛔ legacy min 0 的第一個可購數量必須是 1,永遠不是 0。 */
    public function test_a_zero_minimum_never_offers_zero_as_purchasable(): void
    {
        $variant = $this->variant();

        DB::table('service_variants')->where('id', $variant->id)
            ->update(['min_quantity' => 0]);

        $fresh = ServiceVariant::query()->findOrFail($variant->id);

        $this->assertFalse($fresh->quantityIsValid(0));
        $this->assertSame(1, $fresh->firstPurchasableQuantity());
        $this->assertGreaterThan(0, $fresh->firstPurchasableQuantity());
    }

    public function test_a_non_positive_quantity_is_refused(): void
    {
        $this->expectException(UnsellablePriceException::class);

        Money::total(5900, 0);
    }

    /**
     * 即使有人繞過後台直接改 DB，建單前仍必須 fail closed。
     *
     * ⛔ 這是最後一道防線：不建 order、item 或 attempt。
     */
    public function test_dirty_database_data_cannot_create_an_order(): void
    {
        $variant = $this->variant();

        // 直接寫入負單價，繞過 observer 與表單驗證。
        DB::table('service_variants')->where('id', $variant->id)
            ->update(['unit_price' => '-1.0000']);

        $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => 1000])
            ->assertRedirect();

        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());
        $this->assertSame(0, PaymentAttempt::count());
    }

    public function test_a_zero_priced_variant_cannot_create_an_order(): void
    {
        $variant = $this->variant();

        DB::table('service_variants')->where('id', $variant->id)
            ->update(['unit_price' => '0.0000']);

        $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => 1000])
            ->assertRedirect();

        $this->assertSame(0, Order::count());
        $this->assertSame(0, PaymentAttempt::count());
    }

    public function test_the_normal_case_still_creates_an_integer_order(): void
    {
        // ⛔ 以上所有守門都不得誤傷正常訂單。
        $this->post('/checkout/start', ['variant' => $this->variant()->id, 'quantity' => 1000]);
        $this->post('/checkout/mock', [
            'target' => 'example_account', 'payment' => 'line-pay',
            'customer_email' => 'buyer@example.com',
            'invoice_kind' => 'personal', 'personal_invoice_mode' => 'email',
        ])->assertOk();

        $order = Order::latest('id')->firstOrFail();

        $this->assertSame(590, $order->total_amount);
        $this->assertSame(590, (int) $order->items()->value('amount'));
        $this->assertSame(590, (int) $order->paymentAttempts()->value('amount'));
    }

    public function test_error_messages_carry_no_personal_data(): void
    {
        $variant = $this->probeVariant('-1.0000', 1, 10, 1);

        try {
            $variant->amountFor(1);
            $this->fail('負單價應該被拒絕。');
        } catch (UnsellablePriceException $e) {
            // ⛔ 錯誤訊息只談價格與數量，不得帶出個資或金鑰。
            $this->assertStringNotContainsString('@', $e->getMessage());
            $this->assertStringNotContainsString(config('app.key'), $e->getMessage());
        }
    }
}
