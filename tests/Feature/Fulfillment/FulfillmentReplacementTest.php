<?php

namespace Tests\Feature\Fulfillment;

use App\Actions\Fulfillment\CreateFulfillmentReplacement;
use App\Enums\FulfillmentEventCode;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SubmitFulfillmentOrder;
use App\Models\AdminAuditLog;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\User;
use App\Services\Fulfillment\TheMostPanelCurlCapability;
use App\Support\OrderActivityTimeline;
use App\Support\PublicOrderPresenter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresLiveIntegrations;
use Tests\TestCase;

/**
 * Owner replaces a fulfilment batch with a new link and quantity.
 *
 * ⭐ 實際流程：Owner **先自己**在 TheMostPanel 後台取消舊單，再回本站輸入
 * 新連結與新數量。本站因此 ⛔ 不呼叫取消 API、⛔ 不即時查 status、
 * ⛔ 不以 provider status 當按鈕閘門。
 *
 * ⛔ 這個檔案最重要的三件事：
 *
 *  1. **原始訂單完全不變**——order item、付款、發票都是既成事實。
 *  2. **舊批次不被改寫**，仍由既有排程繼續同步真正的 status 與 Remains。
 *  3. **一個 parent 最多一個 child**，併發也是。
 *
 * ⛔ 全程 0 外呼。
 */
class FulfillmentReplacementTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    private const NEW_TARGET = 'https://instagram.com/replacement_account';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // ⛔ 讓派單閘門成立，但仍然 0 真實外呼（driver 為 fake）。
        config()->set('fulfillment.driver', 'fake');
        $this->enableDispatchSwitch();
        $this->app->bind(
            TheMostPanelCurlCapability::class,
            fn () => TheMostPanelCurlCapability::supported(),
        );
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    private function editor(): User
    {
        return User::factory()->create(['role' => 'editor', 'is_active' => true]);
    }

    /** 一張已付款訂單，含一個商品項目與一筆已送出的履約。 */
    private function paidOrderWithSubmittedBatch(array $batchOverrides = []): FulfillmentOrder
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'paid_at' => now(),
        ]);

        $item = $order->items()->create([
            'platform_name' => 'Instagram',
            'service_name' => 'Instagram 粉絲',
            'variant_label' => '一般粉絲',
            'sku' => 'ig-followers-standard',
            'unit_price_mills' => 5900,
            'quantity' => 1000,
            'quantity_unit' => '個',
            'amount' => 590,
            'target_kind' => 'account',
            'target_value' => 'original_account',
        ]);

        return FulfillmentOrder::factory()
            ->submitted('SMM-PARENT-1')
            ->create(array_merge(['order_item_id' => $item->id], $batchOverrides));
    }

    private function replace(
        FulfillmentOrder $parent,
        ?User $actor = null,
        string $target = self::NEW_TARGET,
        int $quantity = 500,
    ): FulfillmentOrder {
        return app(CreateFulfillmentReplacement::class)->handle(
            $actor ?? $this->owner(),
            $parent,
            $target,
            $quantity,
        );
    }

    // ==================================== 1. 原始資料不變

    /**
     * ⛔⛔ 更換**絕不改動**原始訂單、order item、付款與發票。
     *
     * ⭐ 那些是既成事實：客人同意的內容、他付的錢、開出去的發票。
     * 更換履約是「我們接下來要送什麼」，⛔ 不是「他當初買了什麼」。
     */
    public function test_the_original_order_and_item_are_never_modified(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();
        $item = $parent->orderItem;
        $order = $item->order;

        $before = [
            'item' => $item->only(['quantity', 'amount', 'unit_price_mills', 'target_kind']),
            'target' => $item->target_value,
            'order' => $order->only(['order_status', 'payment_status', 'total_amount']),
        ];

        Bus::fake();
        $this->replace($parent, target: self::NEW_TARGET, quantity: 777);

        $item->refresh();
        $order->refresh();

        $this->assertSame($before['item'], $item->only(['quantity', 'amount', 'unit_price_mills', 'target_kind']));
        // ⛔ 原下單 target 逐字不變。
        $this->assertSame($before['target'], $item->target_value);
        $this->assertSame($before['order'], $order->only(['order_status', 'payment_status', 'total_amount']));

        // ⛔ 不新增付款、不開新發票。
        $this->assertSame(0, $order->paymentAttempts()->count());
        $this->assertNull($order->invoice);
    }

    /** ⛔ parent 的 status／原文／Remains 一律不被改寫。 */
    public function test_the_parent_batch_is_never_rewritten(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();
        DB::table('fulfillment_orders')->where('id', $parent->id)->update([
            'provider_status_code' => 'Partial',
            'provider_remains' => 300,
            'status' => FulfillmentStatus::Partial->value,
        ]);
        $parent->refresh();

        Bus::fake();
        $this->replace($parent);

        $parent->refresh();

        $this->assertSame(FulfillmentStatus::Partial, $parent->status);
        $this->assertSame('Partial', $parent->provider_status_code);
        $this->assertSame(300, $parent->provider_remains);
        $this->assertSame('SMM-PARENT-1', $parent->provider_order_id);
    }

    // ==================================== 2. 建議數量與 Owner 的實際輸入

    /**
     * ⭐ 建議值：有 Remains 用 Remains，否則用本批次送出量。
     *
     * ⛔ 它只是**快照**，⛔ 不參與任何限制。
     */
    public function test_the_suggestion_prefers_remains_and_is_only_a_snapshot(): void
    {
        $withRemains = $this->paidOrderWithSubmittedBatch();
        DB::table('fulfillment_orders')->where('id', $withRemains->id)
            ->update(['provider_remains' => 250]);

        Bus::fake();
        $child = $this->replace($withRemains->fresh(), quantity: 999);

        $this->assertSame(250, $child->suggested_quantity_snapshot);
        // ⛔ 實際數量完全採 Owner 輸入，⛔ 不被建議值影響。
        $this->assertSame(999, $child->quantity_override);
    }

    /** 沒有 Remains 時，建議值退回本批次實際送出的數量（＝原訂購量）。 */
    public function test_the_suggestion_falls_back_to_the_batch_quantity(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();

        Bus::fake();
        $child = $this->replace($parent, quantity: 10);

        $this->assertNull($parent->fresh()->provider_remains);
        $this->assertSame(1000, $child->suggested_quantity_snapshot);
    }

    /**
     * ⛔⛔ Owner 的數量可以**大於**Remains 與原訂購量。
     *
     * ⭐ 這是施工單明確要求的反例：⛔ 不得套商品／provider 的 min-max，
     * ⛔ 不得自動截斷或調整。Owner 是那個知道 SMM 後台實際發生什麼的人；
     * 我們自作主張調整，等於用一個猜測覆蓋掉他的判斷。
     *
     * @return array<string, array{0: int}>
     */
    public static function acceptedQuantityProvider(): array
    {
        return [
            'far below remains' => [1],
            'below remains' => [100],
            'exactly remains' => [300],
            'above remains' => [500],
            'above original order quantity' => [5000],
            'very large' => [999999],
        ];
    }

    #[DataProvider('acceptedQuantityProvider')]
    public function test_any_positive_quantity_is_accepted(int $quantity): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();
        DB::table('fulfillment_orders')->where('id', $parent->id)
            ->update(['provider_remains' => 300]);

        Bus::fake();
        $child = $this->replace($parent->fresh(), quantity: $quantity);

        $this->assertSame($quantity, $child->quantity_override);
        $this->assertSame($quantity, $child->effectiveQuantity());
    }

    // ==================================== 3. 不查 provider status

    /**
     * ⛔⛔ parent 仍是 Pending／Processing 時也能更換，⛔ 且 0 provider call。
     *
     * ⭐ 施工單第 2 條：不做即時 status query、不等排程、不以 provider status
     * 當按鈕閘門。人工取消的判斷由 Owner 承擔。
     *
     * @return array<string, array{0: FulfillmentStatus, 1: string}>
     */
    public static function anyParentStatusProvider(): array
    {
        return [
            'submitted' => [FulfillmentStatus::Submitted, 'SMM-A1'],
            'pending' => [FulfillmentStatus::Pending, 'SMM-A2'],
            'processing' => [FulfillmentStatus::Processing, 'SMM-A3'],
            'partial' => [FulfillmentStatus::Partial, 'SMM-A4'],
            'canceled' => [FulfillmentStatus::Canceled, 'SMM-A5'],
            'failed' => [FulfillmentStatus::Failed, 'SMM-A6'],
        ];
    }

    #[DataProvider('anyParentStatusProvider')]
    public function test_a_replacement_is_allowed_from_any_dispatched_status(
        FulfillmentStatus $status,
        string $providerOrderId,
    ): void {
        $parent = $this->paidOrderWithSubmittedBatch(['provider_order_id' => $providerOrderId]);
        DB::table('fulfillment_orders')->where('id', $parent->id)
            ->update(['status' => $status->value]);

        Bus::fake();
        $child = $this->replace($parent->fresh());

        $this->assertSame(2, $child->sequence_no);
        // ⛔ 整條操作 0 外呼。
        Http::assertNothingSent();
    }

    // ==================================== 4. 拒絕條件

    /**
     * ⛔ 沒有 provider order ID：那一批從未成立，不是「更換」。
     *
     * ⛔ 這裡**直接建立** Ready 的列，⛔ 不是把 submitted 改回 ready——
     * 那個轉移會被 DB 的 transition guard（正確地）擋下，測到的就變成
     * guard 而不是這條規則。
     */
    public function test_a_batch_without_a_provider_order_id_is_refused(): void
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'paid_at' => now(),
        ]);
        $item = $order->items()->create([
            'platform_name' => 'Instagram', 'service_name' => 'Instagram 粉絲',
            'variant_label' => '一般粉絲', 'sku' => 'ig-followers-standard',
            'unit_price_mills' => 5900, 'quantity' => 1000, 'quantity_unit' => '個',
            'amount' => 590, 'target_kind' => 'account', 'target_value' => 'original_account',
        ]);

        // ⛔ Ready 且沒有 provider order ID：這一批還沒送出去。
        $parent = FulfillmentOrder::factory()->ready()->create(['order_item_id' => $item->id]);

        Bus::fake();
        $this->expectException(ValidationException::class);

        try {
            $this->replace($parent);
        } finally {
            Bus::assertNothingDispatched();
            Http::assertNothingSent();
        }
    }

    /** ⛔ 未付款的訂單不得建立更換。 */
    public function test_an_unpaid_order_is_refused(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();
        $parent->orderItem->order->forceFill([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'paid_at' => null,
        ])->save();

        Bus::fake();
        $this->expectException(ValidationException::class);

        try {
            $this->replace($parent->fresh());
        } finally {
            Bus::assertNothingDispatched();
        }
    }

    /** ⛔⛔ Editor 直接呼叫 action 也必須被拒絕。 */
    public function test_an_editor_cannot_create_a_replacement(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();

        Bus::fake();
        $this->expectException(ValidationException::class);

        try {
            $this->replace($parent, actor: $this->editor());
        } finally {
            Bus::assertNothingDispatched();
            $this->assertSame(1, FulfillmentOrder::query()->count());
        }
    }

    /**
     * ⛔ 非法的 target 與 quantity。
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function invalidInputProvider(): array
    {
        return [
            'blank target' => ['', 100],
            'whitespace target' => ['   ', 100],
            'target too long' => [str_repeat('a', 256), 100],
            'zero quantity' => ['https://example.test/x', 0],
            'negative quantity' => ['https://example.test/x', -5],
        ];
    }

    #[DataProvider('invalidInputProvider')]
    public function test_invalid_input_is_refused(string $target, int $quantity): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();

        Bus::fake();
        $this->expectException(ValidationException::class);

        try {
            $this->replace($parent, target: $target, quantity: $quantity);
        } finally {
            Bus::assertNothingDispatched();
            Http::assertNothingSent();
            $this->assertSame(1, FulfillmentOrder::query()->count());
        }
    }

    /**
     * ⛔⛔ 派單總開關關閉時：⛔ 不建立 child、⛔ 不排 job、⛔ 不外呼。
     *
     * ⭐ 建立一筆看起來會立刻送出、實際卻卡住的 replacement，比直接拒絕更糟
     * ——Owner 會以為已經處理好了。
     */
    public function test_a_disabled_dispatch_gate_creates_nothing(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();

        // ⛔ 關閉閘門。
        config()->set('fulfillment.driver', 'disabled');

        Bus::fake();
        $this->expectException(ValidationException::class);

        try {
            $this->replace($parent);
        } finally {
            Bus::assertNothingDispatched();
            Http::assertNothingSent();
            $this->assertSame(1, FulfillmentOrder::query()->count());
        }
    }

    // ==================================== 5. 併發與鏈

    /**
     * ⛔⛔ 雙擊／併發只能建立**一個** child 與**一個** job。
     *
     * ⭐ 這是會花錢的錯誤：兩個 child 代表同一筆訂單被送去供應商兩次。
     */
    public function test_a_double_click_creates_only_one_child(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();
        $owner = $this->owner();

        Bus::fake();

        $first = $this->replace($parent, actor: $owner);

        // ⛔ 第二次必須得到明確的「此批次已更換」，⛔ 不是靜默成功。
        try {
            $this->replace($parent->fresh(), actor: $owner);
            $this->fail('⛔ 第二次更換必須被拒絕。');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('已更換', $e->validator->errors()->first());
        }

        $this->assertSame(1, FulfillmentOrder::query()->where('sequence_no', 2)->count());
        Bus::assertDispatchedTimes(SubmitFulfillmentOrder::class, 1);
        $this->assertNotNull($first->fresh());
    }

    /** ⛔⛔ DB 層的最終防線：同一個 parent 不得有兩個 child。 */
    public function test_the_database_refuses_a_second_child_for_one_parent(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();

        Bus::fake();
        $this->replace($parent);

        $this->expectException(QueryException::class);

        /*
         * ⛔ 用 **raw insert** 繞過 model 層，⛔ 不用 factory。
         *
         * ⭐ R1 之後 model observer 會先擋下不合法的 chain，所以走 model 的
         * 寫入根本到不了資料庫——那樣測到的是 observer，不是這條要證明的
         * **DB unique**。這裡刻意直接寫 SQL：即使有人繞過整個應用層，
         * 同一個 parent 仍然不可能有第二個 child。
         *
         * ⛔ 這是併發下的最終防線：兩個 transaction 可以同時通過應用層檢查。
         */
        DB::table('fulfillment_orders')->insert([
            'order_item_id' => $parent->order_item_id,
            'sequence_no' => 2,
            // ⛔ 同一個 parent——unique index 必須擋下。
            'replaces_fulfillment_order_id' => $parent->id,
            'provider' => $parent->provider,
            'provider_service_id_snapshot' => $parent->provider_service_id_snapshot,
            'payload_type_snapshot' => 'link_quantity',
            'target_value_override' => 'ciphertext-placeholder',
            'quantity_override' => 100,
            'suggested_quantity_snapshot' => 100,
            'replacement_created_by_user_id' => $this->owner()->id,
            'status' => FulfillmentStatus::Ready->value,
            'attempt_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** ⭐ A → B → C 的鏈成立，且每一批各自獨立。 */
    public function test_a_chain_of_replacements_is_supported(): void
    {
        $a = $this->paidOrderWithSubmittedBatch();
        $owner = $this->owner();

        Bus::fake();

        $b = $this->replace($a, actor: $owner, target: 'https://example.test/b', quantity: 200);
        /*
         * ⛔ B 也必須先取得 provider order ID 才能被更換。
         *
         * ⛔ 必須走真實的狀態機：`Ready → Submitting → Submitted`。
         * 直接 `Ready → Submitted` 會被 DB 的 transition guard（正確地）擋下
         * ——那道 guard 存在的理由正是不讓任何路徑跳過送出權的取得。
         */
        DB::table('fulfillment_orders')->where('id', $b->id)
            ->update(['status' => FulfillmentStatus::Submitting->value]);
        DB::table('fulfillment_orders')->where('id', $b->id)->update([
            'status' => FulfillmentStatus::Submitted->value,
            'provider_order_id' => 'SMM-B',
        ]);

        $c = $this->replace($b->fresh(), actor: $owner, target: 'https://example.test/c', quantity: 50);

        $this->assertSame(1, $a->fresh()->sequence_no);
        $this->assertSame(2, $b->fresh()->sequence_no);
        $this->assertSame(3, $c->sequence_no);

        $this->assertSame($a->id, $b->fresh()->replaces_fulfillment_order_id);
        $this->assertSame($b->id, $c->replaces_fulfillment_order_id);

        // ⛔ 已被替換的 A 不能再產生第二個 child。
        try {
            $this->replace($a->fresh(), actor: $owner);
            $this->fail('⛔ A 已被替換，不得再產生 child。');
        } catch (ValidationException) {
            // 預期。
        }

        $this->assertSame(3, FulfillmentOrder::query()->count());
    }

    /**
     * ⭐ 舊批次建立 child 之後**仍可繼續同步**，且不影響 child。
     *
     * ⛔ 這是施工單第 9 條：舊列日後真正回傳 Partial／Canceled 時照常保存
     * 原文與 final Remains。
     */
    public function test_the_parent_keeps_syncing_after_being_replaced(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();

        Bus::fake();
        $child = $this->replace($parent);

        // 模擬排程稍後同步到 parent 的最終狀態。
        DB::table('fulfillment_orders')->where('id', $parent->id)->update([
            'status' => FulfillmentStatus::Canceled->value,
            'provider_status_code' => 'Canceled',
            'provider_remains' => 120,
            'last_synced_at' => now(),
        ]);

        $this->assertSame(FulfillmentStatus::Canceled, $parent->fresh()->status);
        $this->assertSame('Canceled', $parent->fresh()->provider_status_code);
        $this->assertSame(120, $parent->fresh()->provider_remains);

        // ⛔ child 的狀態完全獨立，不受 parent 影響。
        $this->assertSame(FulfillmentStatus::Ready, $child->fresh()->status);
        $this->assertNull($child->fresh()->provider_remains);
    }

    // ==================================== 6. 派單資料

    /** ⭐ 派單用的是**新** target 與 Owner 的實際數量。 */
    public function test_the_replacement_dispatches_with_its_own_target_and_quantity(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();

        Bus::fake();
        $child = $this->replace($parent, target: self::NEW_TARGET, quantity: 321);

        $this->assertSame(self::NEW_TARGET, $child->effectiveTarget());
        $this->assertSame(321, $child->effectiveQuantity());

        // ⛔ parent 仍讀原始快照。
        $this->assertSame('original_account', $parent->fresh()->effectiveTarget());
        $this->assertSame(1000, $parent->fresh()->effectiveQuantity());

        Bus::assertDispatchedTimes(SubmitFulfillmentOrder::class, 1);
    }

    /**
     * ⛔⛔ 供應商服務**逐字沿用 parent 的凍結快照**。
     *
     * ⭐ 絕不重新讀目前的 mapping 或 catalog：那兩者可能在下單之後被改過。
     * 若在更換途中改讀最新設定，Owner 以為只是換個連結重送，實際上卻把訂單
     * 送去了**另一個服務**。
     */
    public function test_the_provider_snapshot_is_copied_verbatim(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();
        DB::table('fulfillment_orders')->where('id', $parent->id)->update([
            'provider_service_id_snapshot' => 'FROZEN-SERVICE-9',
            'provider_service_name_snapshot' => '凍結的服務名稱',
        ]);

        Bus::fake();
        $child = $this->replace($parent->fresh());

        $this->assertSame('FROZEN-SERVICE-9', $child->provider_service_id_snapshot);
        $this->assertSame('凍結的服務名稱', $child->provider_service_name_snapshot);
        $this->assertSame($parent->provider, $child->provider);
        $this->assertSame($parent->fulfillment_mapping_id, $child->fulfillment_mapping_id);
    }

    // ==================================== 7. Audit

    /**
     * ⛔⛔ Audit 記錄存在，但**不得含** target 或任何 provider／credential 值。
     *
     * audit log 的存取控制比訂單資料寬鬆；新 target 只該存在於 encrypted 欄位。
     */
    public function test_the_audit_log_contains_no_target_or_provider_values(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();
        $owner = $this->owner();

        Bus::fake();
        $child = $this->replace($parent, actor: $owner, target: self::NEW_TARGET, quantity: 42);

        $audit = AdminAuditLog::query()->latest('id')->first();

        $this->assertNotNull($audit);
        $this->assertSame('fulfillment_replacement_created', $audit->action);
        $this->assertSame($owner->id, $audit->user_id);

        $serialised = json_encode($audit->after, JSON_UNESCAPED_UNICODE);

        foreach ([self::NEW_TARGET, 'original_account', 'SMM-PARENT-1',
            'FAKE-SERVICE-0000', 'themostpanel',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $serialised);
        }

        // ⭐ 可稽核的識別碼與數字仍在。
        $this->assertSame($child->id, $audit->after['child_fulfillment_order_id']);
        $this->assertSame(42, $audit->after['actual_quantity']);
    }

    // ==================================== 8. 時間線與公開頁

    /** ⭐ 時間線有固定本地文字，⛔ 不含 target 或 provider 原文。 */
    public function test_the_timeline_records_the_replacement_safely(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();

        Bus::fake();
        $this->replace($parent, target: self::NEW_TARGET);

        $entries = OrderActivityTimeline::for($parent->orderItem->order->fresh());
        $labels = array_column($entries, 'label');

        $this->assertContains('Owner 已建立第 1 次更換履約', $labels);

        $serialised = json_encode($entries, JSON_UNESCAPED_UNICODE);
        foreach ([self::NEW_TARGET, 'SMM-PARENT-1', 'FAKE-SERVICE-0000'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $serialised);
        }
    }

    /**
     * ⭐ 公開頁：上方原始批次與下方更換紀錄**各自顯示自己的狀態**。
     *
     * ⛔⛔ 這條測試原本把「上方取鏈尾」寫成規格，而那正是 Owner 在 staging
     * 看到的缺陷：原始履約已 `Canceled`，上方卻顯示「進行中／50」，
     * 上下兩筆看起來像同一批，客人無法看懂原始單已取消、更換單正在進行。
     *
     * ⭐ Owner 指定的新語意：上方只反映**第 1 批原始履約**。
     *
     * ⛔ 完全不含 provider order ID／service ID／service name／raw token／
     * SMM／TheMostPanel／操作者／Email／手機。
     */
    public function test_the_public_lookup_shows_replacements_without_provider_data(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();
        DB::table('fulfillment_orders')->where('id', $parent->id)->update([
            'status' => FulfillmentStatus::Canceled->value,
            'provider_status_code' => 'Canceled',
            'provider_service_name_snapshot' => 'PROVIDER SERVICE NAME',
        ]);

        Bus::fake();
        $child = $this->replace($parent->fresh(), target: self::NEW_TARGET, quantity: 250);
        // ⛔ 走真實狀態機：Ready → Submitting → Submitted → Processing。
        DB::table('fulfillment_orders')->where('id', $child->id)
            ->update(['status' => FulfillmentStatus::Submitting->value]);
        DB::table('fulfillment_orders')->where('id', $child->id)->update([
            'status' => FulfillmentStatus::Submitted->value,
            'provider_order_id' => 'SMM-CHILD-9',
        ]);
        DB::table('fulfillment_orders')->where('id', $child->id)->update([
            'status' => FulfillmentStatus::Processing->value,
            'provider_remains' => 80,
        ]);

        $shaped = PublicOrderPresenter::for($parent->orderItem->order->fresh());
        $item = $shaped['items'][0];

        /*
         * ⛔⛔ 上方原始區塊只反映**第 1 批**。
         *
         * parent 已 `Canceled`，所以公開語意是「請聯絡客服」、剩餘 `-`。
         * ⛔ 不得因為鏈尾正在跑就顯示「進行中」——那會讓客人以為原始單
         * 還會自己完成。
         */
        $this->assertSame('請聯絡客服', $item['status']);
        $this->assertSame('-', $item['remains']);
        $this->assertSame('danger', $item['status_tone']);

        // ⭐ 原購買數量與原下單 target 維持原意。
        $this->assertSame(1000, $item['quantity']);
        $this->assertSame('original_account', $item['target']);

        /*
         * ⭐ 下方更換紀錄用**自己**的狀態與剩餘，⛔ 不被上方覆蓋。
         */
        $this->assertCount(1, $item['replacements']);
        $replacement = $item['replacements'][0];
        $this->assertSame(2, $replacement['sequence']);
        $this->assertSame(self::NEW_TARGET, $replacement['target']);
        $this->assertSame(self::NEW_TARGET, $replacement['target_url']);
        $this->assertSame(250, $replacement['quantity']);
        $this->assertSame('進行中', $replacement['status']);
        $this->assertSame('80', $replacement['remains']);
        $this->assertSame('info', $replacement['status_tone']);

        // ⛔ 整包輸出不得含任何 provider 資料。
        $serialised = json_encode($shaped, JSON_UNESCAPED_UNICODE);
        foreach (['SMM-PARENT-1', 'SMM-CHILD-9', 'FAKE-SERVICE-0000', 'PROVIDER SERVICE NAME',
            'Canceled', 'TheMostPanel', 'themostpanel', 'SMM',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $serialised, "⛔ 公開輸出外洩：{$forbidden}");
        }
    }

    /**
     * ⛔ 惡意 target 必須被 escape，且不可點。
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function replacementTargetProvider(): array
    {
        return [
            'https is linkable' => ['https://instagram.com/ok', true],
            'http is linkable' => ['http://example.test/ok', true],
            'plain account' => ['just_an_account', false],
            'javascript' => ['javascript:alert(1)', false],
            'data uri' => ['data:text/html,<script>alert(1)</script>', false],
            'file' => ['file:///etc/passwd', false],
        ];
    }

    #[DataProvider('replacementTargetProvider')]
    public function test_only_safe_targets_become_links(string $target, bool $linkable): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();

        Bus::fake();
        $this->replace($parent, target: $target);

        $shaped = PublicOrderPresenter::for($parent->orderItem->order->fresh());
        $replacement = $shaped['items'][0]['replacements'][0];

        $this->assertSame($target, $replacement['target']);

        if ($linkable) {
            $this->assertSame($target, $replacement['target_url']);
        } else {
            $this->assertNull($replacement['target_url'], '⛔ 非 http(s) 絕不可成為 href。');
        }
    }

    // ==================================== 9. 事件

    /** ⭐ child 會有 Created 與 ReplacementCreated 兩筆事件。 */
    public function test_the_child_records_its_creation_events(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();

        Bus::fake();
        $child = $this->replace($parent);

        /*
         * ⛔ `event_code` 有 enum cast，所以 `pluck` 回來的是 enum 實例，
         * ⛔ 不是字串——直接比對字串會得到一個看起來像「事件沒寫入」的失敗，
         * 而實際上事件好好地在那裡。
         */
        $codes = $child->events()->pluck('event_code')->all();

        $this->assertContains(FulfillmentEventCode::Created, $codes);
        $this->assertContains(FulfillmentEventCode::ReplacementCreated, $codes);

        // ⛔ parent 不得因此被追加任何事件。
        $this->assertSame(0, $parent->events()
            ->where('event_code', FulfillmentEventCode::ReplacementCreated->value)
            ->count());
    }

    // ==================================== 10. DB shape guard

    /** ⛔ sequence 1 不得帶任何更換欄位。 */
    public function test_the_first_batch_cannot_carry_replacement_columns(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();

        $this->expectException(QueryException::class);

        DB::table('fulfillment_orders')->where('id', $parent->id)
            ->update(['quantity_override' => 100]);
    }

    /** ⛔ sequence >1 缺任何必要欄位都會被 DB 拒絕。 */
    public function test_a_replacement_row_without_a_parent_is_refused(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();

        $this->expectException(QueryException::class);

        DB::table('fulfillment_orders')->insert([
            'order_item_id' => $parent->order_item_id,
            'sequence_no' => 2,
            // ⛔ 沒有 parent、沒有 target、沒有數量。
            'provider' => $parent->provider,
            'status' => FulfillmentStatus::Ready->value,
            'attempt_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** ⛔ 同一個商品項目的 sequence 不得重複（防重複派單的等價防線）。 */
    public function test_two_rows_cannot_share_a_sequence_for_one_item(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();

        $this->expectException(QueryException::class);

        // ⛔ 第二筆 sequence 1：原本的單欄 unique 擋的就是這個。
        FulfillmentOrder::factory()->create([
            'order_item_id' => $parent->order_item_id,
            'sequence_no' => 1,
        ]);
    }

    // ==================================== 11. Migration rollback

    /** ⛔⛔ 有 replacement 時 `down()` 必須 fail closed。 */
    public function test_the_migration_refuses_to_roll_back_with_replacements(): void
    {
        $parent = $this->paidOrderWithSubmittedBatch();

        Bus::fake();
        $this->replace($parent);

        $migration = require database_path(
            'migrations/2026_08_27_200000_enable_fulfillment_replacements.php'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/已有 .* 筆更換履約/u');

        $migration->down();
    }

    /** ⭐ 沒有 replacement 時 `down()` 可以正常回滾。 */
    public function test_the_migration_can_roll_back_without_replacements(): void
    {
        $this->assertSame(0, FulfillmentOrder::query()->where('sequence_no', '>', 1)->count());

        $migration = require database_path(
            'migrations/2026_08_27_200000_enable_fulfillment_replacements.php'
        );

        $migration->down();

        // ⛔ 欄位確實被移除。
        $this->assertFalse(
            Schema::hasColumn('fulfillment_orders', 'sequence_no'),
        );

        // ⛔ 復原，避免影響同一個程序中的其他測試。
        $migration->up();
    }
}
