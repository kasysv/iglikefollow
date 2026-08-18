<?php

namespace Tests\Feature;

use App\Actions\Orders\MarkPaymentPending;
use App\Actions\Orders\RecordPaymentResult;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPaid;
use App\Exceptions\NonIntegerAmountException;
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
     * ⛔ 取代舊的 `half a dollar rounds up`。
     *
     * 台幣沒有小數收款：0.0005 × 1000 = NT$0.5 不是「四捨五入成 1 元」，
     * 而是一個根本收不到的金額。靜默進位會讓客人被收一筆商品從沒公告過的
     * 價格，所以現在必須拋出錯誤。
     */
    public function test_an_amount_that_is_not_whole_dollars_is_refused_not_rounded(): void
    {
        $variant = $this->variant();

        $this->expectException(NonIntegerAmountException::class);

        Money::total($variant->unitPriceMills(), 1001); // 0.59 × 1001 = 590.59
    }

    public function test_the_rounding_boundary_is_rejected_in_both_directions(): void
    {
        // 0.5 元與 1.5 元都收不到；⛔ 兩者都不得被進位成 1 或 2。
        $this->assertFalse(Money::divides(Money::toMills('0.0005'), 1000));
        $this->assertFalse(Money::divides(Money::toMills('0.0015'), 1000));

        // 剛好整除才是可收款的金額。
        $this->assertTrue(Money::divides(Money::toMills('0.0005'), 2000));
        $this->assertSame(1, Money::total(Money::toMills('0.0005'), 2000));
    }

    public function test_a_variant_offering_an_unpayable_quantity_cannot_be_saved(): void
    {
        $variant = $this->variant();

        // 0.59 元／個搭配階距 1：買 1001 個就是 590.59 元，收不到。
        $this->expectException(ValidationException::class);

        $variant->forceFill(['step_quantity' => 1, 'min_quantity' => 1000])->save();
    }

    public function test_checkout_refuses_a_quantity_that_is_not_whole_dollars(): void
    {
        $variant = $this->variant();

        // 繞過後台驗證直接寫入不合規設定，模擬既有髒資料。
        DB::table('service_variants')->where('id', $variant->id)
            ->update(['step_quantity' => 1, 'min_quantity' => 1000]);

        $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => 1001])
            ->assertRedirect();

        // ⛔ 不建任何 order／item／attempt。
        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());
        $this->assertSame(0, PaymentAttempt::count());
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
    public function test_validation_starts_at_the_first_purchasable_quantity(): void
    {
        // min 101、step 100 → 101 買不到，200 才是第一個合法數量。
        $variant = $this->probeVariant('0.5000', 101, 200, 100);

        $this->assertSame(200, $variant->firstPurchasableQuantity());
        // ⛔ 不得回報 101：那是客人買不到的數量。
        $this->assertNull($variant->firstNonIntegerQuantity());
        $this->assertTrue($variant->quantityIsValid(200));
        $this->assertSame(100, $variant->amountFor(200));
    }

    public function test_a_range_containing_no_purchasable_quantity_is_refused(): void
    {
        // 101 到 199 之間沒有任何 100 的倍數：客人買不到任何數量。
        $variant = $this->probeVariant('0.5000', 101, 199, 100);

        $this->assertNull($variant->firstPurchasableQuantity());

        $saved = $this->variant();
        $this->expectException(ValidationException::class);

        $saved->forceFill([
            'min_quantity' => 101, 'max_quantity' => 199,
            'step_quantity' => 100, 'default_quantity' => 101,
        ])->save();
    }

    public function test_the_offending_quantity_reported_is_one_a_customer_could_pick(): void
    {
        // rate 0.59、min 150、step 100 → 第一個合法量是 200，不是 150。
        $variant = $this->probeVariant('0.5900', 150, 1000, 100);

        $offending = $variant->firstNonIntegerQuantity();

        // 回報的數量必須真的買得到（是 step 的倍數且在範圍內）。
        if ($offending !== null) {
            $this->assertSame(0, $offending % 100, "回報了買不到的數量 {$offending}");
            $this->assertGreaterThanOrEqual(150, $offending);
        }

        $this->assertSame(200, $variant->firstPurchasableQuantity());
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
     * ⛔ M4B-MAPPING-UI-B R1:corrupt step 不是照常計算的理由。step 0／
     * 負值的 row(繞過 observer 直接寫入)在 checkout 根層只回 false——
     * 絕不 Modulo-by-zero,也不產生任何 warning;checkout 入口不建 order。
     */
    public function test_a_corrupt_step_fails_closed_without_dividing_by_zero(): void
    {
        $variant = $this->variant();

        foreach ([0, -100] as $badStep) {
            DB::table('service_variants')->where('id', $variant->id)
                ->update(['step_quantity' => $badStep]);

            $fresh = ServiceVariant::query()->findOrFail($variant->id);

            set_error_handler(function (int $errno, string $errstr): bool {
                $this->fail('⛔ corrupt step 產生了 PHP error:'.$errstr);
            });

            try {
                $this->assertFalse($fresh->quantityIsValid(100));
                $this->assertFalse($fresh->quantityIsValid(1000));
                // ⛔ 不得把 step<1 正規化成 1:沒有合法 step 就沒有可購數量。
                $this->assertNull($fresh->firstPurchasableQuantity());
            } finally {
                restore_error_handler();
            }

            $this->post('/checkout/start', ['variant' => $variant->id, 'quantity' => 1000])
                ->assertRedirect();

            $this->assertSame(0, Order::count());
            $this->assertSame(0, OrderItem::count());
        }
    }

    /** ⛔ R1:legacy min 0 的第一個可購數量是第一個正 step 倍數,永遠不是 0。 */
    public function test_a_zero_minimum_never_offers_zero_as_purchasable(): void
    {
        $variant = $this->variant();

        DB::table('service_variants')->where('id', $variant->id)
            ->update(['min_quantity' => 0]);

        $fresh = ServiceVariant::query()->findOrFail($variant->id);

        $this->assertFalse($fresh->quantityIsValid(0));
        $this->assertSame((int) $fresh->step_quantity, $fresh->firstPurchasableQuantity());
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
