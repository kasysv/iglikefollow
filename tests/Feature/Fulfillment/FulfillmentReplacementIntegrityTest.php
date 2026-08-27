<?php

namespace Tests\Feature\Fulfillment;

use App\Actions\Fulfillment\CreateFulfillmentReplacement;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Fulfillment\TheMostPanelCurlCapability;
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
 * The invariants that must hold even when nobody goes through the UI.
 *
 * ⭐ GPT 複審找出五個缺口，全部都是「正常路徑沒問題、但繞過去就穿透」的類型：
 *
 *  1. 數量先 `(int)` cast 再驗證——`1.5` 靜默變 `1`；
 *  2. DB shape guard 沒擋 `sequence_no = 0`；
 *  3. model 寫入層沒有跨列不變量——可建立跨商品／跳號／快照不一致的 child；
 *  4. SQLite `down()` 後舊履約 guards 消失；
 *  5. target 被 `trim()` 改寫。
 *
 * ⛔ 這個檔案只放**反證**：每一條都必須在修好之前失敗。
 */
class FulfillmentReplacementIntegrityTest extends TestCase
{
    use ConfiguresLiveIntegrations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

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

    private function paidItem(string $target = 'original_account'): OrderItem
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'paid_at' => now(),
        ]);

        return $order->items()->create([
            'platform_name' => 'Instagram',
            'service_name' => 'Instagram 粉絲',
            'variant_label' => '一般粉絲',
            'sku' => 'ig-followers-standard',
            'unit_price_mills' => 5900,
            'quantity' => 1000,
            'quantity_unit' => '個',
            'amount' => 590,
            'target_kind' => 'account',
            'target_value' => $target,
        ]);
    }

    private function submittedBatch(string $providerOrderId = 'SMM-P1'): FulfillmentOrder
    {
        return FulfillmentOrder::factory()
            ->submitted($providerOrderId)
            ->create(['order_item_id' => $this->paidItem()->id]);
    }

    // ==================================== R1-1 數量必須在 cast 前驗證

    /**
     * ⛔⛔ 非整數的數量必須**被拒絕**，⛔ 絕不靜默調整。
     *
     * ⭐ GPT 指出的缺口：action 的 type-hint 是 `int`，PHP 會把 `1.5` 悄悄
     * 變成 `1`、把 `'1e3'` 變成 `1000`。Owner 明確要求「只驗證可保存的正整數，
     * 不自動截斷或調整」——**靜默取整正是自動調整**，而且是最難察覺的那種：
     * 畫面上看起來成功了，送出去的數量卻不是 Owner 打的那個。
     *
     * @return array<string, array{0: mixed}>
     */
    public static function invalidQuantityProvider(): array
    {
        return [
            'decimal' => [1.5],
            'decimal string' => ['1.5'],
            'exponent string' => ['1e3'],
            'signed string' => ['+100'],
            'space wrapped' => [' 100 '],
            'empty string' => [''],
            'zero' => [0],
            'zero string' => ['0'],
            'negative' => [-5],
            'negative string' => ['-5'],
            'overflow' => ['4294967296'],
            'array' => [[100]],
            'bool' => [true],
            'null' => [null],
            'alpha' => ['abc'],
            'leading zeros' => ['007'],
        ];
    }

    #[DataProvider('invalidQuantityProvider')]
    public function test_a_non_integer_quantity_is_refused_before_any_cast(mixed $quantity): void
    {
        $parent = $this->submittedBatch();

        Bus::fake();

        try {
            app(CreateFulfillmentReplacement::class)->handle(
                $this->owner(),
                $parent,
                'https://example.test/new',
                $quantity,
            );

            $this->fail('⛔ 非正整數的數量必須被拒絕，不得靜默調整。');
        } catch (ValidationException) {
            // 預期。
        }

        // ⛔ 0 child、0 job、0 外呼、0 audit。
        $this->assertSame(1, FulfillmentOrder::query()->count());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('admin_audit_logs')
            ->where('action', 'fulfillment_replacement_created')->count());
    }

    /** ⭐ 合法的十進位正整數（含字串形式）仍必須被接受。 */
    public function test_a_canonical_positive_integer_is_accepted(): void
    {
        $parent = $this->submittedBatch();

        Bus::fake();

        $child = app(CreateFulfillmentReplacement::class)->handle(
            $this->owner(),
            $parent,
            'https://example.test/new',
            '100',
        );

        $this->assertSame(100, $child->quantity_override);
    }

    /** ⭐ 上限剛好可以，超過一格就拒絕。 */
    public function test_the_quantity_upper_bound_is_inclusive(): void
    {
        $parent = $this->submittedBatch();

        Bus::fake();

        $child = app(CreateFulfillmentReplacement::class)->handle(
            $this->owner(),
            $parent,
            'https://example.test/new',
            '4294967295',
        );

        $this->assertSame(4294967295, $child->quantity_override);
    }

    // ==================================== R1-2 DB 必須拒絕 sequence < 1

    /**
     * ⛔⛔ `sequence_no = 0` 必須被資料庫拒絕。
     *
     * ⭐ GPT 已以 fresh SQLite migration 實證它可以穿透：原本的 shape guard
     * 只處理 `= 1` 與 `> 1` 兩種情況，0 兩邊都不符合，於是整條檢查被跳過。
     *
     * ⛔ 不能只靠 unsigned 欄位——SQLite 不提供同等保證。
     */
    public function test_the_database_refuses_a_zero_sequence_on_insert(): void
    {
        $item = $this->paidItem();

        $this->expectException(QueryException::class);

        DB::table('fulfillment_orders')->insert([
            'order_item_id' => $item->id,
            'sequence_no' => 0,
            'provider' => 'themostpanel',
            'status' => FulfillmentStatus::Ready->value,
            'attempt_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** ⛔ update 也必須擋下 sequence 0。 */
    public function test_the_database_refuses_a_zero_sequence_on_update(): void
    {
        $parent = $this->submittedBatch();

        $this->expectException(QueryException::class);

        DB::table('fulfillment_orders')->where('id', $parent->id)
            ->update(['sequence_no' => 0]);
    }

    // ==================================== R1-3 跨列不變量

    /**
     * ⛔⛔ child 的 `order_item_id` 必須與 parent 相同。
     *
     * ⭐ GPT 已以 model 直接寫入實證：跨商品的 child 可以成立。那會讓 A 商品
     * 的更換批次掛在 B 商品底下——公開頁與後台都會顯示錯誤的鏈。
     *
     * ⛔ 施工單早已要求「不得只靠 UI」；只在 action 內檢查不算，
     * 因為 direct model／factory 路徑正是穿透點。
     */
    public function test_a_child_cannot_belong_to_a_different_order_item(): void
    {
        $parent = $this->submittedBatch('SMM-A');
        $otherItem = $this->paidItem('other_account');

        $this->expectException(\RuntimeException::class);

        FulfillmentOrder::create([
            // ⛔ 別的商品項目。
            'order_item_id' => $otherItem->id,
            'sequence_no' => 2,
            'replaces_fulfillment_order_id' => $parent->id,
            'provider' => $parent->provider,
            'provider_service_id_snapshot' => $parent->provider_service_id_snapshot,
            'payload_type_snapshot' => $parent->payload_type_snapshot,
            'target_value_override' => 'https://example.test/x',
            'quantity_override' => 100,
            'suggested_quantity_snapshot' => 100,
            'replacement_created_by_user_id' => $this->owner()->id,
            'status' => FulfillmentStatus::Ready,
            'attempt_count' => 0,
        ]);
    }

    /** ⛔⛔ child 的 sequence 必須恰為 parent + 1，⛔ 不得跳號。 */
    public function test_a_child_cannot_skip_a_sequence_number(): void
    {
        $parent = $this->submittedBatch('SMM-B');

        $this->expectException(\RuntimeException::class);

        FulfillmentOrder::create([
            'order_item_id' => $parent->order_item_id,
            // ⛔ parent 是 1，這裡卻是 5。
            'sequence_no' => 5,
            'replaces_fulfillment_order_id' => $parent->id,
            'provider' => $parent->provider,
            'provider_service_id_snapshot' => $parent->provider_service_id_snapshot,
            'payload_type_snapshot' => $parent->payload_type_snapshot,
            'target_value_override' => 'https://example.test/x',
            'quantity_override' => 100,
            'suggested_quantity_snapshot' => 100,
            'replacement_created_by_user_id' => $this->owner()->id,
            'status' => FulfillmentStatus::Ready,
            'attempt_count' => 0,
        ]);
    }

    /**
     * ⛔⛔ provider 快照必須與 parent 逐值一致。
     *
     * ⭐ 這是整個功能最危險的一條：若 child 可以帶著**不同的** service ID，
     * Owner 以為只是換個連結重送，實際上卻把訂單送去了另一個服務。
     *
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function tamperedSnapshotProvider(): array
    {
        return [
            'service id' => ['provider_service_id_snapshot', 'DIFFERENT-SERVICE'],
            'service name' => ['provider_service_name_snapshot', '別的服務名稱'],
            'provider' => ['provider', 'someone_else'],
        ];
    }

    #[DataProvider('tamperedSnapshotProvider')]
    public function test_a_child_cannot_change_the_provider_snapshot(string $column, mixed $value): void
    {
        $parent = $this->submittedBatch('SMM-C');

        $this->expectException(\RuntimeException::class);

        FulfillmentOrder::create(array_merge([
            'order_item_id' => $parent->order_item_id,
            'sequence_no' => 2,
            'replaces_fulfillment_order_id' => $parent->id,
            'provider' => $parent->provider,
            'provider_service_id_snapshot' => $parent->provider_service_id_snapshot,
            'provider_service_name_snapshot' => $parent->provider_service_name_snapshot,
            'payload_type_snapshot' => $parent->payload_type_snapshot,
            'target_value_override' => 'https://example.test/x',
            'quantity_override' => 100,
            'suggested_quantity_snapshot' => 100,
            'replacement_created_by_user_id' => $this->owner()->id,
            'status' => FulfillmentStatus::Ready,
            'attempt_count' => 0,
        ], [$column => $value]));
    }

    /** ⛔ 指向不存在的 parent 也必須被拒絕。 */
    public function test_a_child_without_an_existing_parent_is_refused(): void
    {
        $item = $this->paidItem();

        $this->expectException(\RuntimeException::class);

        FulfillmentOrder::create([
            'order_item_id' => $item->id,
            'sequence_no' => 2,
            // ⛔ 不存在的 parent id。
            'replaces_fulfillment_order_id' => 999999,
            'provider' => 'themostpanel',
            'provider_service_id_snapshot' => 'FAKE-SERVICE-0000',
            'payload_type_snapshot' => 'link_quantity',
            'target_value_override' => 'https://example.test/x',
            'quantity_override' => 100,
            'suggested_quantity_snapshot' => 100,
            'replacement_created_by_user_id' => $this->owner()->id,
            'status' => FulfillmentStatus::Ready,
            'attempt_count' => 0,
        ]);
    }

    /** ⭐ 合法的 A→B→C 仍然可以建立（⛔ 不得誤擋正常流程）。 */
    public function test_a_legitimate_chain_still_works(): void
    {
        $a = $this->submittedBatch('SMM-D1');
        $owner = $this->owner();

        Bus::fake();

        $b = app(CreateFulfillmentReplacement::class)
            ->handle($owner, $a, 'https://example.test/b', 200);

        DB::table('fulfillment_orders')->where('id', $b->id)
            ->update(['status' => FulfillmentStatus::Submitting->value]);
        DB::table('fulfillment_orders')->where('id', $b->id)->update([
            'status' => FulfillmentStatus::Submitted->value,
            'provider_order_id' => 'SMM-D2',
        ]);

        $c = app(CreateFulfillmentReplacement::class)
            ->handle($owner, $b->fresh(), 'https://example.test/c', 50);

        $this->assertSame(3, $c->sequence_no);
        $this->assertSame($b->id, $c->replaces_fulfillment_order_id);
    }

    /** ⭐ 初始派單流程不得被新的不變量誤擋。 */
    public function test_the_initial_dispatch_flow_is_not_blocked(): void
    {
        $item = $this->paidItem();

        $row = FulfillmentOrder::create([
            'order_item_id' => $item->id,
            'provider' => 'themostpanel',
            'status' => FulfillmentStatus::Ready,
            'attempt_count' => 0,
            'provider_service_id_snapshot' => 'FAKE-SERVICE-0000',
            'payload_type_snapshot' => 'link_quantity',
        ]);

        $this->assertSame(1, $row->sequence_no);
        $this->assertFalse($row->isReplacement());
    }

    // ==================================== R1-4 down() 後舊 guards 必須still在

    /**
     * ⛔⛔ `down()` 之後、尚未重新 `up()` 的**中間狀態**，舊履約 guards
     * 必須仍然有效。
     *
     * ⭐ 初版的 rollback 測試看不出問題：它 `down()` 之後只檢查欄位不見了，
     * 就立刻再 `up()` 把 guard 裝回去——那個「中間的無保護狀態」從來沒有被
     * 檢查過。SQLite 的表重建會帶走所有 trigger，於是回滾後資料庫進入一個
     * **完全沒有履約保護**的狀態。
     *
     * ⛔ 一支讓資料庫變成無保護、卻回報成功的 rollback，比直接失敗危險得多。
     */
    public function test_the_old_guards_survive_a_rollback(): void
    {
        $this->assertSame(0, FulfillmentOrder::query()->where('sequence_no', '>', 1)->count());

        $migration = require database_path(
            'migrations/2026_08_27_200000_enable_fulfillment_replacements.php'
        );

        $migration->down();

        try {
            // 欄位確實移除了。
            $this->assertFalse(
                Schema::hasColumn('fulfillment_orders', 'sequence_no'),
            );

            // ⭐ 三道舊 guard 都必須還在（SQLite）。
            $triggers = DB::table('sqlite_master')->where('type', 'trigger')->pluck('name')->all();

            foreach ([
                'fulfillment_orders_values_check_insert',
                'fulfillment_orders_values_check_update',
                'fulfillment_orders_transition_guard',
                'fulfillment_orders_identifier_guard_insert',
                'fulfillment_orders_identifier_guard_update',
            ] as $guard) {
                $this->assertContains($guard, $triggers, "⛔ rollback 後缺少 {$guard}。");
            }

            // ⛔ 而且必須真的還會擋：非法 status 應被拒絕。
            $item = $this->paidItem();

            try {
                DB::table('fulfillment_orders')->insert([
                    'order_item_id' => $item->id,
                    'provider' => 'themostpanel',
                    'status' => 'not_a_real_status',
                    'attempt_count' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->fail('⛔ rollback 後 values guard 仍必須拒絕非法 status。');
            } catch (QueryException) {
                // 預期。
            }

            // ⛔ identifier guard 也必須還在：submitted 沒有單號應被拒絕。
            try {
                DB::table('fulfillment_orders')->insert([
                    'order_item_id' => $item->id,
                    'provider' => 'themostpanel',
                    'status' => FulfillmentStatus::Submitted->value,
                    'provider_order_id' => null,
                    'attempt_count' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->fail('⛔ rollback 後 identifier guard 仍必須拒絕無單號的 submitted。');
            } catch (QueryException) {
                // 預期。
            }
        } finally {
            // ⛔ 復原，避免影響同一程序中的其他測試。
            $migration->up();
        }
    }

    // ==================================== R1-5 target 只驗證，不改寫

    /**
     * ⛔⛔ 含首尾空白但非空的 target 必須**逐字保存原值**。
     *
     * ⭐ 原施工單要求「保存原值，不自行補網址或改寫」。先 `trim()` 再保存
     * 就是改寫——而且是靜默的：Owner 看不出我們動過他的輸入。
     *
     * ⛔ 空白可能有意義（某些平台的帳號允許），而即使沒有意義，
     * 「我們認為那是多餘的」也不是我們該替他決定的事。
     */
    public function test_a_target_with_surrounding_whitespace_is_stored_verbatim(): void
    {
        $parent = $this->submittedBatch();
        $target = '  https://example.test/spaced  ';

        Bus::fake();

        $child = app(CreateFulfillmentReplacement::class)
            ->handle($this->owner(), $parent, $target, 100);

        // ⛔ 逐字相同，⛔ 沒有被 trim。
        $this->assertSame($target, $child->target_value_override);
        $this->assertSame($target, $child->effectiveTarget());
    }

    /** ⛔ 全空白仍必須被拒絕（用 trim 判定，但不用 trim 後的值儲存）。 */
    public function test_a_whitespace_only_target_is_refused(): void
    {
        $parent = $this->submittedBatch();

        Bus::fake();
        $this->expectException(ValidationException::class);

        try {
            app(CreateFulfillmentReplacement::class)
                ->handle($this->owner(), $parent, "   \t  ", 100);
        } finally {
            $this->assertSame(1, FulfillmentOrder::query()->count());
        }
    }

    /**
     * ⛔ max 255 的口徑對**原始字串**執行。
     *
     * ⛔ 若先 trim 再量長度，一個 260 字元、首尾各有幾個空白的輸入會被
     * 誤判為合法——然後那個超長值被存進 DB。
     */
    public function test_the_length_limit_applies_to_the_raw_string(): void
    {
        $parent = $this->submittedBatch();

        // 原始 260 字元；trim 後仍超過 255。
        $target = '  '.str_repeat('a', 256).'  ';

        Bus::fake();
        $this->expectException(ValidationException::class);

        try {
            app(CreateFulfillmentReplacement::class)
                ->handle($this->owner(), $parent, $target, 100);
        } finally {
            $this->assertSame(1, FulfillmentOrder::query()->count());
        }
    }
}
