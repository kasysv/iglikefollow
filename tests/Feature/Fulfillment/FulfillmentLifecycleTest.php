<?php

namespace Tests\Feature\Fulfillment;

use App\Actions\Fulfillment\PrepareFulfillmentForOrder;
use App\Actions\Fulfillment\SubmitFulfillment;
use App\Actions\Fulfillment\SyncFulfillmentState;
use App\Enums\FulfillmentAttentionReason;
use App\Enums\FulfillmentEventCode;
use App\Enums\FulfillmentStatus;
use App\Enums\IntegrationProvider;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\FulfillmentMapping;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProviderService;
use App\Models\ServiceVariant;
use App\Services\Fulfillment\FakeFulfillmentGateway;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * Ordering from a supplier on a paid customer's behalf.
 *
 * ⛔ Every provider here is a Fake and nothing reaches the network. These tests
 * say what *we* do with an answer — they prove nothing about TheMostPanel,
 * whose contract is unverified and stays that way until M4B.
 *
 * The rule shaping most of it: sending the same order twice costs real money
 * and delivers a service the customer did not buy, while sending it late costs
 * a delay. So every unclear outcome stops and waits.
 */
class FulfillmentLifecycleTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    private FakeFulfillmentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        // ⛔ 這一輪不得有任何外部呼叫。
        Http::preventStrayRequests();

        config()->set('fulfillment.driver', 'fake');
        $this->enableDispatchSwitch();

        $this->gateway = new FakeFulfillmentGateway;
    }

    private function variant(): ServiceVariant
    {
        return ServiceVariant::factory()->create();
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

    private function itemFor(Order $order, ?ServiceVariant $variant = null): OrderItem
    {
        return OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_variant_id' => $variant?->id,
        ]);
    }

    private function prepare(Order $order): array
    {
        return app(PrepareFulfillmentForOrder::class)->handle($order->fresh());
    }

    private function submit(FulfillmentOrder $fulfillment): FulfillmentOrder
    {
        return (new SubmitFulfillment($this->gateway))->handle($fulfillment);
    }

    /** 一張已付款、mapping 齊全、開關開啟的訂單。 */
    private function readyFulfillment(): FulfillmentOrder
    {
        $variant = $this->variant();
        $order = $this->paidOrder();
        $this->itemFor($order, $variant);
        FulfillmentMapping::factory()->enabled()->create(['service_variant_id' => $variant->id]);

        return $this->prepare($order)[0];
    }

    // ==================================== 1. 只有已付款才有履約列

    public static function unpaidOrderProvider(): array
    {
        return [
            'pending payment' => [OrderStatus::PendingPayment, PaymentStatus::Pending],
            'failed' => [OrderStatus::PendingPayment, PaymentStatus::Failed],
            'canceled' => [OrderStatus::Canceled, PaymentStatus::Canceled],
        ];
    }

    #[DataProvider('unpaidOrderProvider')]
    public function test_an_unpaid_order_gets_no_fulfillment_row(
        OrderStatus $orderStatus,
        PaymentStatus $paymentStatus,
    ): void {
        $variant = $this->variant();
        $order = Order::factory()->create([
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
            'total_amount' => 590,
        ]);
        $this->itemFor($order, $variant);
        FulfillmentMapping::factory()->enabled()->create(['service_variant_id' => $variant->id]);

        $this->prepare($order);

        // ⛔ 不是「建立一列然後不派單」，而是完全沒有列。
        $this->assertSame(0, FulfillmentOrder::count());
        $this->assertSame([], $this->gateway->submissions);
    }

    // ==================================== 2. 設定不齊全就停住

    public function test_a_missing_mapping_stays_configuration_pending(): void
    {
        $order = $this->paidOrder();
        $this->itemFor($order, $this->variant());

        $this->prepare($order);

        $row = FulfillmentOrder::sole();
        $this->assertSame(FulfillmentStatus::ConfigurationPending, $row->status);
        $this->assertSame(FulfillmentAttentionReason::MappingMissing, $row->attention_code);
        $this->assertSame([], $this->gateway->submissions);
    }

    public function test_a_disabled_mapping_stays_configuration_pending(): void
    {
        $variant = $this->variant();
        $order = $this->paidOrder();
        $this->itemFor($order, $variant);
        FulfillmentMapping::factory()->create(['service_variant_id' => $variant->id]);

        $this->prepare($order);

        $row = FulfillmentOrder::sole();
        $this->assertSame(FulfillmentStatus::ConfigurationPending, $row->status);
        $this->assertSame(FulfillmentAttentionReason::MappingDisabled, $row->attention_code);
    }

    public function test_a_closed_dispatch_switch_stays_configuration_pending(): void
    {
        // ⛔ R1:關閉派單＝Owner 在後台停用總開關,不是改已 deprecated 的 env 旗標。
        DB::table('integration_settings')->where('provider', 'themostpanel')->update(['is_enabled' => false]);

        $variant = $this->variant();
        $order = $this->paidOrder();
        $this->itemFor($order, $variant);
        FulfillmentMapping::factory()->enabled()->create(['service_variant_id' => $variant->id]);

        $this->prepare($order);

        $row = FulfillmentOrder::sole();
        // ⛔ mapping 正確 ≠ 可以送出：總開關是分開的一道閘。
        $this->assertSame(FulfillmentStatus::ConfigurationPending, $row->status);
        $this->assertSame(FulfillmentAttentionReason::DispatchDisabled, $row->attention_code);
        $this->assertNull($row->provider_service_id_snapshot);
    }

    public function test_a_blocked_row_never_freezes_a_snapshot(): void
    {
        $order = $this->paidOrder();
        $this->itemFor($order, $this->variant());

        $this->prepare($order);

        $row = FulfillmentOrder::sole();
        $this->assertNull($row->provider_service_id_snapshot);
        $this->assertNull($row->payload_type_snapshot);
    }

    // ==================================== 3. 設定齊全就進 ready

    public function test_a_complete_mapping_becomes_ready_with_a_frozen_snapshot(): void
    {
        $row = $this->readyFulfillment();

        $this->assertSame(FulfillmentStatus::Ready, $row->status);
        $this->assertNull($row->attention_code);
        $this->assertSame('FAKE-SERVICE-0000', $row->provider_service_id_snapshot);
    }

    public function test_changing_a_mapping_later_does_not_alter_an_existing_row(): void
    {
        $row = $this->readyFulfillment();
        $mapping = $row->mapping;

        $mapping->update(['provider_service_id' => 'FAKE-SERVICE-9999']);

        // ⛔ 快照是「當時送去哪裡」的證據，不能被日後的設定改寫。
        $this->assertSame('FAKE-SERVICE-0000', $row->fresh()->provider_service_id_snapshot);
    }

    // ==================================== 3b. M4C:SMM 服務名稱快照

    /** ⛔ 進 Ready 那一刻,目錄裡有名字就當場凍結成 name snapshot。 */
    public function test_a_new_row_freezes_the_catalog_name_at_ready(): void
    {
        ProviderService::factory()->create([
            'provider' => IntegrationProvider::TheMostPanel->value,
            'provider_service_id' => '10000',
        ]);

        $variant = $this->variant();
        $order = $this->paidOrder();
        $this->itemFor($order, $variant);
        FulfillmentMapping::factory()->enabled()->create([
            'service_variant_id' => $variant->id,
            'provider_service_id' => '10000',
        ]);

        $row = $this->prepare($order)[0];

        $this->assertNotNull($row->provider_service_name_snapshot);
        $this->assertSame($row->provider_service_name_snapshot, $row->displayServiceName());
    }

    /** ⛔ 之後目錄改名,不得覆寫已凍結的名稱快照——與 id 快照同一條規則。 */
    public function test_a_later_catalog_rename_does_not_alter_an_existing_name_snapshot(): void
    {
        $service = ProviderService::factory()->create([
            'provider' => IntegrationProvider::TheMostPanel->value,
            'provider_service_id' => '10001',
            'name' => '原始名稱',
        ]);

        $variant = $this->variant();
        $order = $this->paidOrder();
        $this->itemFor($order, $variant);
        FulfillmentMapping::factory()->enabled()->create([
            'service_variant_id' => $variant->id,
            'provider_service_id' => '10001',
        ]);

        $row = $this->prepare($order)[0];
        $service->update(['name' => '改名後']);

        $this->assertSame('原始名稱', $row->fresh()->provider_service_name_snapshot);
        $this->assertSame('原始名稱', $row->fresh()->displayServiceName());
    }

    /**
     * ⛔ 既有列(遷移前建立、snapshot 為 null)以同一組 exact key 即時查詢
     * 目前目錄名稱,不得只憑 provider_service_id 猜或模糊比對——用兩筆
     * 「id 前綴相同、實際不同」的服務證明只有精確相符的那筆會被選到。
     */
    public function test_an_existing_row_without_a_name_snapshot_falls_back_to_a_live_exact_catalog_lookup(): void
    {
        ProviderService::factory()->create([
            'provider' => IntegrationProvider::TheMostPanel->value,
            'provider_service_id' => '100020',
            'name' => '不精確相符的服務——不應被選到',
        ]);

        ProviderService::factory()->create([
            'provider' => IntegrationProvider::TheMostPanel->value,
            'provider_service_id' => '10002',
            'name' => '精確相符的服務',
        ]);

        $row = $this->readyFulfillment();
        $row->forceFill([
            // 模擬遷移前既有列:清掉 name snapshot,id snapshot 換成 10002。
            'provider_service_id_snapshot' => '10002',
            'provider_service_name_snapshot' => null,
        ])->saveQuietly();

        $this->assertSame('精確相符的服務', $row->fresh()->displayServiceName());
    }

    /** ⛔ 查不到目錄名稱時,fallback 本站分類名稱並明確標示「未找到」,不留空白。 */
    public function test_a_missing_catalog_entry_falls_back_to_the_site_name_with_a_marker(): void
    {
        $row = $this->readyFulfillment();
        $row->forceFill(['provider_service_name_snapshot' => null])->saveQuietly();

        $name = $row->fresh()->displayServiceName();

        $this->assertNotSame('', $name);
        $this->assertStringContainsString('SMM 目錄未找到', $name);
    }

    // ==================================== 4. 重複執行不產生第二列

    public function test_preparing_twice_creates_only_one_row(): void
    {
        $variant = $this->variant();
        $order = $this->paidOrder();
        $this->itemFor($order, $variant);
        FulfillmentMapping::factory()->enabled()->create(['service_variant_id' => $variant->id]);

        $this->prepare($order);
        $this->prepare($order);
        $this->prepare($order);

        $this->assertSame(1, FulfillmentOrder::count());
    }

    public function test_the_database_refuses_a_second_row_for_one_item(): void
    {
        $row = $this->readyFulfillment();

        // ⛔ 應用層檢查之外，unique index 才是最終防線。
        $this->expectException(QueryException::class);

        FulfillmentOrder::create([
            'order_item_id' => $row->order_item_id,
            'provider' => IntegrationProvider::TheMostPanel->value,
            'status' => FulfillmentStatus::ConfigurationPending->value,
        ]);
    }

    public function test_each_item_of_a_multi_item_order_gets_its_own_row(): void
    {
        $variant = $this->variant();
        $order = $this->paidOrder();
        $this->itemFor($order, $variant);
        $this->itemFor($order, $variant);
        FulfillmentMapping::factory()->enabled()->create(['service_variant_id' => $variant->id]);

        $ready = $this->prepare($order);

        $this->assertCount(2, $ready);
        $this->assertSame(2, FulfillmentOrder::count());
    }

    // ==================================== 5. submit 三條路徑

    public function test_an_accepted_submission_is_recorded(): void
    {
        $row = $this->submit($this->readyFulfillment());

        $this->assertSame(FulfillmentStatus::Submitted, $row->status);
        $this->assertNotNull($row->provider_order_id);
        $this->assertNotNull($row->submitted_at);
        $this->assertSame(1, $row->attempt_count);
        $this->assertNull($row->attention_code);
    }

    public function test_a_rejection_is_recorded_as_failed(): void
    {
        $this->gateway->willReject();

        $row = $this->submit($this->readyFulfillment());

        // 確定沒有成立，所以是失敗而不是不明。
        $this->assertSame(FulfillmentStatus::Failed, $row->status);
        $this->assertSame(FulfillmentAttentionReason::ProviderRejected, $row->attention_code);
        $this->assertNull($row->provider_order_id);
    }

    public function test_an_unclear_outcome_becomes_submission_unknown(): void
    {
        $this->gateway->willBeUnknown();

        $row = $this->submit($this->readyFulfillment());

        // ⛔ 逾時不是失敗：對方可能已經收下了。
        $this->assertSame(FulfillmentStatus::SubmissionUnknown, $row->status);
        $this->assertSame(FulfillmentAttentionReason::Timeout, $row->attention_code);
        $this->assertTrue($row->isTerminal());
    }

    public function test_an_exception_becomes_submission_unknown(): void
    {
        $this->gateway->willThrow();

        $row = $this->submit($this->readyFulfillment());

        $this->assertSame(FulfillmentStatus::SubmissionUnknown, $row->status);
        $this->assertSame(FulfillmentAttentionReason::Unknown, $row->attention_code);
    }

    // ==================================== 6. 絕不送出第二次

    public function test_a_submitted_row_is_never_submitted_again(): void
    {
        $row = $this->submit($this->readyFulfillment());

        $this->submit($row);
        $this->submit($row->fresh());

        // ⛔ 只呼叫過一次。
        $this->assertCount(1, $this->gateway->submissions);
        $this->assertSame(1, $row->fresh()->attempt_count);
    }

    public function test_an_unknown_row_is_never_retried(): void
    {
        $this->gateway->willBeUnknown();
        $row = $this->submit($this->readyFulfillment());

        $this->gateway->willAccept();
        $again = $this->submit($row->fresh());

        // ⛔ 結果不明之後不得自動重送：那可能會下第二筆單。
        $this->assertCount(1, $this->gateway->submissions);
        $this->assertSame(FulfillmentStatus::SubmissionUnknown, $again->status);
    }

    public static function terminalStatusProvider(): array
    {
        return [
            'completed' => [FulfillmentStatus::Completed],
            'partial' => [FulfillmentStatus::Partial],
            'canceled' => [FulfillmentStatus::Canceled],
            'failed' => [FulfillmentStatus::Failed],
            'submission unknown' => [FulfillmentStatus::SubmissionUnknown],
        ];
    }

    /**
     * ⛔ 終止狀態的列絕不再送出。
     *
     * 這裡透過真正的送出流程抵達終止狀態，而不是用 forceFill 硬寫。
     * R1 加上狀態轉移守衛後，`ready → completed` 這種跳躍會被正確拒絕——
     * 原本的寫法等於在測一個系統根本不允許存在的狀態組合。
     */
    #[DataProvider('terminalStatusProvider')]
    public function test_a_terminal_row_is_never_submitted(FulfillmentStatus $status): void
    {
        $row = $this->submit($this->readyFulfillment());
        $this->assertCount(1, $this->gateway->submissions);

        // submitted 之後才依對方回報走到各終止狀態。
        if ($status !== FulfillmentStatus::Submitted) {
            $row->forceFill(['status' => $status])->save();
        }

        $this->submit($row->fresh());

        // ⛔ 仍然只有最初那一次呼叫。
        $this->assertCount(1, $this->gateway->submissions);
        $this->assertSame($status, $row->fresh()->status);
    }

    public function test_a_row_that_already_has_a_provider_id_is_never_submitted(): void
    {
        $row = $this->readyFulfillment();

        // 狀態是 ready，但已經有供應商單號——⛔ 那就是已經成立的證據。
        $row->forceFill(['provider_order_id' => 'FAKE-EXISTING'])->save();

        $this->submit($row->fresh());

        $this->assertSame([], $this->gateway->submissions);
    }

    public function test_the_switch_closing_mid_flight_blocks_the_send(): void
    {
        $row = $this->readyFulfillment();

        // ⛔ R1:關閉派單＝Owner 在後台停用總開關,不是改已 deprecated 的 env 旗標。
        DB::table('integration_settings')->where('provider', 'themostpanel')->update(['is_enabled' => false]);

        $result = $this->submit($row);

        // ⛔ 什麼都沒送出，所以回到待設定而不是失敗或不明。
        $this->assertSame([], $this->gateway->submissions);
        $this->assertSame(FulfillmentStatus::ConfigurationPending, $result->status);
        $this->assertSame(FulfillmentAttentionReason::DispatchDisabled, $result->attention_code);
    }

    // ==================================== 7. 狀態同步

    public function test_a_known_status_moves_the_row_forward(): void
    {
        $row = $this->submit($this->readyFulfillment());

        $synced = (new SyncFulfillmentState($this->gateway->willSync(FulfillmentStatus::Completed)))
            ->handle($row);

        $this->assertSame(FulfillmentStatus::Completed, $synced->status);
        $this->assertNotNull($synced->last_synced_at);
    }

    public function test_an_unrecognised_status_never_becomes_completed(): void
    {
        $row = $this->submit($this->readyFulfillment());

        $synced = (new SyncFulfillmentState($this->gateway->willSyncUnrecognised()))->handle($row);

        // ⛔ 讀不懂就維持原狀：把未知狀態當成完成，會關掉一張還在跑或已經失敗的單。
        $this->assertSame(FulfillmentStatus::Submitted, $synced->status);
        $this->assertNotNull($synced->last_synced_at);

        $codes = $synced->events->pluck('event_code')->map(fn ($c) => $c->value)->all();
        $this->assertContains(FulfillmentEventCode::StatusUnrecognised->value, $codes);
    }

    public function test_a_terminal_row_is_not_resynced(): void
    {
        $row = $this->submit($this->readyFulfillment());
        $completed = (new SyncFulfillmentState($this->gateway->willSync(FulfillmentStatus::Completed)))
            ->handle($row);

        $again = (new SyncFulfillmentState($this->gateway->willSync(FulfillmentStatus::Failed)))
            ->handle($completed);

        // ⛔ 已完成的單不得被再次改寫。
        $this->assertSame(FulfillmentStatus::Completed, $again->status);
    }

    public function test_a_row_without_a_provider_id_is_not_synced(): void
    {
        $row = $this->readyFulfillment();

        $synced = (new SyncFulfillmentState($this->gateway->willSync(FulfillmentStatus::Completed)))
            ->handle($row);

        $this->assertSame(FulfillmentStatus::Ready, $synced->status);
        $this->assertNull($synced->last_synced_at);
    }

    // ==================================== 8. 時間線

    public function test_the_timeline_records_the_whole_journey(): void
    {
        $row = $this->submit($this->readyFulfillment());

        $codes = $row->events->pluck('event_code')->map(fn ($c) => $c->value)->all();

        $this->assertSame([
            FulfillmentEventCode::Created->value,
            FulfillmentEventCode::Ready->value,
            FulfillmentEventCode::SubmissionClaimed->value,
            FulfillmentEventCode::Submitted->value,
        ], $codes);
    }

    public function test_the_timeline_cannot_be_rewritten(): void
    {
        $row = $this->submit($this->readyFulfillment());

        // ⛔ 可以事後修改的時間線不是證據。
        $this->expectException(QueryException::class);

        DB::table('fulfillment_events')
            ->where('fulfillment_order_id', $row->id)
            ->update(['event_code' => FulfillmentEventCode::Created->value]);
    }
}
