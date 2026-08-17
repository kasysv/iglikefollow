<?php

namespace Tests\Feature\Fulfillment;

use App\Actions\Fulfillment\PrepareFulfillmentForOrder;
use App\Actions\Fulfillment\SubmitFulfillment;
use App\Contracts\FulfillmentGateway;
use App\Data\Fulfillment\FulfillmentSubmission;
use App\Enums\FulfillmentStatus;
use App\Enums\IntegrationProvider;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPaid;
use App\Jobs\IssueInvoiceForOrder;
use App\Jobs\PrepareFulfillmentForPaidOrder;
use App\Jobs\SubmitFulfillmentOrder;
use App\Models\FulfillmentMapping;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Services\Fulfillment\DisabledFulfillmentGateway;
use App\Services\Fulfillment\FakeFulfillmentGateway;
use App\Services\Fulfillment\FulfillmentDispatchGate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The boundaries: what must never happen, whatever the configuration says.
 */
class FulfillmentSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('fulfillment.driver', 'fake');
        config()->set('fulfillment.dispatch_enabled', true);
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

    // ==================================== 1. production 一律不派單

    public function test_production_never_dispatches(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        // ⛔ 不論設定怎麼寫，production 都不得派單。
        $this->assertFalse(FulfillmentDispatchGate::enabled());
    }

    public function test_production_gets_the_disabled_gateway(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->app->forgetInstance(FulfillmentGateway::class);

        $this->assertInstanceOf(
            DisabledFulfillmentGateway::class,
            $this->app->make(FulfillmentGateway::class)
        );
    }

    public function test_the_fake_gateway_refuses_to_exist_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        // ⛔ 第二道防線：即使有人直接 new 它。
        $this->expectException(\RuntimeException::class);

        new FakeFulfillmentGateway;
    }

    public static function nonDispatchingConfigProvider(): array
    {
        return [
            'switch off' => ['fake', false],
            'driver disabled' => ['disabled', true],
            'both off' => ['disabled', false],
            'unknown driver' => ['themostpanel', true],
        ];
    }

    /** ⛔ 只有「driver=fake ＋ 開關開啟 ＋ 非 production」三者同時成立才可能派單。 */
    #[DataProvider('nonDispatchingConfigProvider')]
    public function test_incomplete_configuration_never_dispatches(string $driver, bool $enabled): void
    {
        config()->set('fulfillment.driver', $driver);
        config()->set('fulfillment.dispatch_enabled', $enabled);

        $this->assertFalse(FulfillmentDispatchGate::enabled());
    }

    public function test_a_forged_driver_cannot_produce_an_http_client(): void
    {
        // ⛔ M4A 根本沒有 HTTP client，所以設定成 themostpanel 也只會得到 disabled。
        config()->set('fulfillment.driver', 'themostpanel');
        $this->app->forgetInstance(FulfillmentGateway::class);

        $this->assertInstanceOf(
            DisabledFulfillmentGateway::class,
            $this->app->make(FulfillmentGateway::class)
        );
    }

    // ==================================== 2. 付款事件的隔離

    public function test_a_paid_order_queues_both_invoice_and_fulfillment(): void
    {
        Queue::fake();

        $order = $this->paidOrder();

        event(new OrderPaid($order));

        // ⛔ 兩個 listener 互相獨立，都必須排進去。
        Queue::assertPushed(PrepareFulfillmentForPaidOrder::class);
        Queue::assertPushed(IssueInvoiceForOrder::class);
    }

    public function test_the_fulfillment_job_carries_only_an_integer_id(): void
    {
        $order = $this->paidOrder();
        $job = new PrepareFulfillmentForPaidOrder($order->id);

        $encoded = json_encode(unserialize(serialize($job)));

        // ⛔ queue payload 會被寫入儲存、重試並常被記錄。
        $this->assertStringNotContainsString('@fulfillment-test-account', (string) $encoded);
        $this->assertStringNotContainsString((string) $order->reference, (string) $encoded);
    }

    public function test_an_unpaid_order_job_is_a_no_op(): void
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'total_amount' => 590,
        ]);
        OrderItem::factory()->create(['order_id' => $order->id]);

        (new PrepareFulfillmentForPaidOrder($order->id))
            ->handle(app(PrepareFulfillmentForOrder::class));

        $this->assertSame(0, FulfillmentOrder::count());
    }

    public function test_a_deleted_order_job_is_a_no_op(): void
    {
        // ⛔ 重播的事件可能指向已經不存在的訂單。
        (new PrepareFulfillmentForPaidOrder(999999))
            ->handle(app(PrepareFulfillmentForOrder::class));

        $this->assertSame(0, FulfillmentOrder::count());
    }

    public function test_a_missing_fulfillment_row_submit_job_is_a_no_op(): void
    {
        (new SubmitFulfillmentOrder(999999))
            ->handle(app(SubmitFulfillment::class));

        $this->assertTrue(true, '不存在的列不得造成例外');
    }

    // ==================================== 3. 資料庫層的不變式

    public function test_submitted_requires_a_provider_order_id(): void
    {
        $row = FulfillmentOrder::factory()->ready()->create();

        // ⛔ 沒有單號的 submitted 是一筆無法對帳的紀錄。
        $this->expectException(QueryException::class);

        DB::table('fulfillment_orders')->where('id', $row->id)->update([
            'status' => FulfillmentStatus::Submitted->value,
        ]);
    }

    public function test_an_unknown_status_is_rejected_by_the_database(): void
    {
        $row = FulfillmentOrder::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('fulfillment_orders')->where('id', $row->id)->update(['status' => 'not_a_status']);
    }

    public function test_an_unknown_attention_code_is_rejected_by_the_database(): void
    {
        $row = FulfillmentOrder::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('fulfillment_orders')->where('id', $row->id)->update(['attention_code' => 'MADE_UP']);
    }

    public function test_a_duplicate_provider_order_id_is_rejected(): void
    {
        FulfillmentOrder::factory()->submitted('FAKE-DUP')->create();

        // ⛔ 同一個 provider 內單號不得重複：那代表兩筆本地紀錄指向同一張供應商單。
        $this->expectException(QueryException::class);

        FulfillmentOrder::factory()->submitted('FAKE-DUP')->create();
    }

    public function test_many_rows_may_share_a_null_provider_order_id(): void
    {
        // ⛔ 尚未送出的列都是 NULL；唯一索引不得因此擋住第二筆。
        FulfillmentOrder::factory()->count(3)->create();

        $this->assertSame(3, FulfillmentOrder::count());
    }

    public function test_a_mapping_needs_a_non_empty_service_id(): void
    {
        $this->expectException(QueryException::class);

        DB::table('fulfillment_mappings')->insert([
            'service_variant_id' => ServiceVariant::factory()->create()->id,
            'provider' => IntegrationProvider::TheMostPanel->value,
            'provider_service_id' => '   ',
            'payload_type' => 'link_quantity',
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_variant_cannot_have_two_mappings_for_one_provider(): void
    {
        $variant = ServiceVariant::factory()->create();
        FulfillmentMapping::factory()->create(['service_variant_id' => $variant->id]);

        $this->expectException(QueryException::class);

        FulfillmentMapping::factory()->create(['service_variant_id' => $variant->id]);
    }

    // ==================================== 4. 後台權限

    public function test_an_editor_cannot_see_mappings(): void
    {
        $editor = User::factory()->create(['role' => 'editor', 'is_active' => true]);

        // ⛔ 供應商代碼是商業敏感資訊。
        $this->assertFalse($editor->can('viewAny', FulfillmentMapping::class));
        $this->assertFalse($editor->can('create', FulfillmentMapping::class));
    }

    public function test_an_owner_manages_mappings_but_cannot_delete(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $mapping = FulfillmentMapping::factory()->create();

        $this->assertTrue($owner->can('viewAny', FulfillmentMapping::class));
        $this->assertTrue($owner->can('create', FulfillmentMapping::class));
        $this->assertTrue($owner->can('update', $mapping));
        // ⛔ 只能停用：既有履約紀錄需要它才能解釋自己送去了哪裡。
        $this->assertFalse($owner->can('delete', $mapping));
    }

    public function test_an_inactive_owner_is_refused(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => false]);

        $this->assertFalse($owner->can('viewAny', FulfillmentMapping::class));
        $this->assertFalse($owner->can('viewAny', FulfillmentOrder::class));
    }

    public function test_fulfillment_records_are_read_only_for_everyone(): void
    {
        $row = FulfillmentOrder::factory()->create();

        foreach (['owner', 'editor'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_active' => true]);

            // 兩種角色都看得到⋯⋯
            $this->assertTrue($user->can('viewAny', FulfillmentOrder::class), $role);

            // ⛔ ⋯⋯但沒有人能寫。沒有重送、取消或手動標記完成。
            $this->assertFalse($user->can('create', FulfillmentOrder::class), $role);
            $this->assertFalse($user->can('update', $row), $role);
            $this->assertFalse($user->can('delete', $row), $role);
        }
    }

    // ==================================== 5. 個資與機密不落盤

    public function test_no_customer_target_reaches_the_fulfillment_tables(): void
    {
        $variant = ServiceVariant::factory()->create();
        $order = $this->paidOrder();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_variant_id' => $variant->id,
            'target_value' => '@a-real-looking-customer',
        ]);
        FulfillmentMapping::factory()->enabled()->create(['service_variant_id' => $variant->id]);

        $gateway = new FakeFulfillmentGateway;
        $ready = app(PrepareFulfillmentForOrder::class)->handle($order->fresh());
        (new SubmitFulfillment($gateway))->handle($ready[0]);

        // gateway 確實收到了 target⋯⋯
        $this->assertSame('@a-real-looking-customer', $gateway->submissions[0]->target);

        // ⛔ ⋯⋯但它不得出現在任何一張履約表裡。
        foreach (['fulfillment_orders', 'fulfillment_events', 'fulfillment_mappings'] as $table) {
            $dump = json_encode(DB::table($table)->get(), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('@a-real-looking-customer', (string) $dump, $table);
        }
    }

    public function test_the_fingerprint_is_a_keyed_hash_that_hides_its_inputs(): void
    {
        $fingerprint = FulfillmentOrder::fingerprint([
            'provider_service_id' => 'FAKE-SERVICE-0000',
            'quantity' => 1000,
        ]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fingerprint);
        $this->assertStringNotContainsString('FAKE-SERVICE-0000', $fingerprint);

        // ⛔ 加了金鑰：輸入很短且可猜，未加金鑰的雜湊可以用窮舉還原。
        $this->assertNotSame(
            hash('sha256', json_encode(['provider_service_id' => 'FAKE-SERVICE-0000', 'quantity' => 1000])),
            $fingerprint,
        );
    }

    public function test_the_fingerprint_excludes_the_target(): void
    {
        $submission = new FulfillmentSubmission('SVC-1', '@someone', 100);

        // ⛔ 指紋會落盤，而短帳號的雜湊仍然是那位客人帳號的雜湊。
        $this->assertArrayNotHasKey('target', $submission->fingerprintInputs());
    }

    public function test_no_repository_file_contains_a_real_service_id_placeholder(): void
    {
        // ⛔ 測試資料一律用明顯是假的值；提交進 Git 的代碼等同公開。
        $mapping = FulfillmentMapping::factory()->create();

        $this->assertStringStartsWith('FAKE-', $mapping->provider_service_id);
    }
}
