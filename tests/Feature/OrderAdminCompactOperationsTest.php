<?php

namespace Tests\Feature;

use App\Enums\FulfillmentStatus;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\FulfillmentOrdersRelationManager;
use App\Models\FulfillmentOrder;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\OrderActivityTimeline;
use App\Support\OrderOperationsIndicators;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The compact order admin: fixed fulfilment columns, newest-first timeline,
 * and the two at-a-glance list columns.
 *
 * ⛔⛔ 這一輪的核心要求是「收斂」，而收斂最容易出的錯是**把例外狀態一起藏掉**。
 * ⭐ 所以 SMM 欄的測試特別多：`Partial`／`Canceled` 必須看得見、不得互相覆蓋、
 * 不得被「還有商品沒送出」的叉蓋掉，⛔ 也不得被舊批次誤報成勾。
 */
class OrderAdminCompactOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'is_active' => true]);
    }

    private function editor(): User
    {
        return User::factory()->create(['role' => 'editor', 'is_active' => true]);
    }

    private function order(string $email = 'private@example.com'): Order
    {
        $order = Order::factory()->create([
            'customer_email' => $email,
            'customer_phone' => '0912345678',
        ]);

        $this->addItem($order);

        return $order->fresh();
    }

    private function addItem(Order $order, string $sku = 'ig-followers-standard'): OrderItem
    {
        return $order->items()->create([
            'platform_name' => 'Instagram',
            'service_name' => 'Instagram 粉絲',
            'variant_label' => '一般粉絲',
            'sku' => $sku,
            'unit_price_mills' => 5900,
            'quantity' => 1000,
            'quantity_unit' => '個',
            'amount' => 590,
            'target_kind' => 'account',
            'target_value' => 'example_account',
        ]);
    }

    /** ⛔ `order_events` 沒有 factory；用既有關聯建立，與其他測試一致。 */
    private function orderEvent(Order $order, Carbon $at): OrderEvent
    {
        $event = $order->events()->create([
            'type' => OrderEvent::TYPE_ORDER_CREATED,
            'summary' => '結帳驗證通過，訂單建立為待付款。',
        ]);

        // ⛔ `created_at` 由 timestamps 自動填入，這裡明確覆寫成指定時間。
        $event->forceFill(['created_at' => $at])->save();

        return $event->fresh();
    }

    // ==================================================== 1. 履約紀錄固定 9 欄

    /**
     * ⛔⛔ 資料欄的**標籤與順序**逐字等於 Owner 指定的 9 欄。
     *
     * ⭐ 這一條刻意檢查順序而不只是「有沒有出現」：欄位順序就是客服的閱讀
     * 動線，⛔ 換過位置的表跟換過內容的表一樣難用。
     */
    public function test_the_fulfillment_table_shows_exactly_the_nine_fixed_columns(): void
    {
        $order = $this->order();

        FulfillmentOrder::factory()->submitted('SMM-COL-1')->create([
            'order_item_id' => $order->items()->first()->id,
        ]);

        $html = Livewire::actingAs($this->owner())
            ->test(FulfillmentOrdersRelationManager::class, [
                'ownerRecord' => $order,
                'pageClass' => ViewOrder::class,
            ])
            ->assertOk()
            ->html();

        $expected = ['送出時間', '供應商單號', '服務名稱', '連結／帳號',
            '起始值', '數量', '狀態', '剩餘', '批次'];

        $positions = [];

        foreach ($expected as $label) {
            /*
             * ⛔ 不用 `>標籤<` 比對：Filament 的 header cell 會在標籤前後
             * 留下換行與縮排，那樣比對會全部落空（我實測確認過）。
             */
            $at = mb_strpos($html, $label);
            $this->assertNotFalse($at, "⛔ 缺少欄位標籤：{$label}");
            $positions[$label] = $at;
        }

        // ⛔ 順序必須嚴格遞增。
        $sorted = $positions;
        asort($sorted);
        $this->assertSame(
            $expected,
            array_keys($sorted),
            '⛔⛔ 9 個欄位的實際顯示順序與指定順序不符。',
        );
    }

    /**
     * ⛔⛔ R1：**Editor 也必須看到同樣的 9 欄**，包含實際交付目標。
     *
     * ⭐ 初版把 `連結／帳號` 設成 Owner-only，於是 Owner 看到 9 欄、
     * Editor 只看到 8 欄——⛔ 那既違反「固定 9 欄」，也與既有權限決策
     * 不一致（policy 允許 Editor 讀取，`ViewOrder` 也已給客服完整資料）。
     *
     * ⭐ 客服要回答「我們把東西送到哪裡」，看不到交付目標就答不了。
     */
    public function test_an_editor_sees_the_same_nine_columns_and_the_actual_target(): void
    {
        $order = $this->order();
        $item = $order->items()->first();

        $parent = FulfillmentOrder::factory()->submitted('SMM-ED-1')->create([
            'order_item_id' => $item->id,
        ]);

        // 第 2 批：Owner 換過連結；⭐ Editor 也必須看得到新的那個。
        FulfillmentOrder::factory()
            ->replacing($parent, 'editor_visible_target', 250)
            ->submitted('SMM-ED-2')
            ->create();

        $html = Livewire::actingAs($this->editor())
            ->test(FulfillmentOrdersRelationManager::class, [
                'ownerRecord' => $order,
                'pageClass' => ViewOrder::class,
            ])
            ->assertOk()
            ->html();

        $expected = ['送出時間', '供應商單號', '服務名稱', '連結／帳號',
            '起始值', '數量', '狀態', '剩餘', '批次'];

        $positions = [];

        foreach ($expected as $label) {
            $at = mb_strpos($html, $label);
            $this->assertNotFalse($at, "⛔⛔ Editor 也必須看到這一欄：{$label}");
            $positions[$label] = $at;
        }

        $sorted = $positions;
        asort($sorted);
        $this->assertSame($expected, array_keys($sorted), '⛔ Editor 的欄位順序也必須相同。');

        // ⭐ Editor 看得到**該批次實際**的交付目標。
        $this->assertStringContainsString('editor_visible_target', $html);

        // ⛔⛔ 但仍然不得看到「更換連結」——看得到與改得動是兩件事。
        $this->assertStringNotContainsString('更換連結', $html);
    }

    /** ⛔ 被移除的舊資料欄一個都不得留下。 */
    #[DataProvider('removedColumnLabels')]
    public function test_the_removed_fulfillment_columns_are_gone(string $label): void
    {
        $order = $this->order();

        FulfillmentOrder::factory()->submitted('SMM-COL-2')->create([
            'order_item_id' => $order->items()->first()->id,
        ]);

        $html = Livewire::actingAs($this->owner())
            ->test(FulfillmentOrdersRelationManager::class, [
                'ownerRecord' => $order,
                'pageClass' => ViewOrder::class,
            ])
            ->html();

        $this->assertStringNotContainsString(
            $label,
            $html,
            "⛔ 這一欄應該已被移除：{$label}",
        );
    }

    /** @return array<string, array{string}> */
    public static function removedColumnLabels(): array
    {
        return [
            '本站分類' => ['本站分類'],
            '待處理原因' => ['待處理原因'],
            '服務代碼' => ['服務代碼'],
        ];
    }

    /**
     * ⛔⛔ `0` 與 `null` 不得混淆。
     *
     * ⭐ `0` 是「確實是 0」（全部補完／還沒開始），`null` 是「還沒問到」。
     * ⛔ 把 `0` 顯示成 `—` 會讓客服以為系統還沒同步，然後白等。
     */
    public function test_zero_start_count_and_remains_are_not_shown_as_placeholders(): void
    {
        $order = $this->order();

        FulfillmentOrder::factory()->submitted('SMM-ZERO-1')->create([
            'order_item_id' => $order->items()->first()->id,
            'provider_start_count' => 0,
            'provider_remains' => 0,
        ]);

        $record = FulfillmentOrder::where('provider_order_id', 'SMM-ZERO-1')->firstOrFail();

        // ⭐ 直接釘住 display 方法：`0` 出得來、且不是「尚未取得」。
        $this->assertSame('0', $record->displayStartCount());
        $this->assertSame('0', $record->displayRemains());

        $null = FulfillmentOrder::factory()->submitted('SMM-NULL-1')->create([
            'order_item_id' => $this->addItem($order, 'ig-second')->id,
            'provider_start_count' => null,
            'provider_remains' => null,
        ]);

        $this->assertSame('尚未取得', $null->displayStartCount());
        $this->assertSame('尚未取得', $null->displayRemains());
    }

    /**
     * ⛔ 每一欄讀的是**該批次**的 effective 值，⛔ 不是原始 order item。
     */
    public function test_the_columns_read_this_batch_not_the_original_item(): void
    {
        $order = $this->order();
        $item = $order->items()->first();

        $parent = FulfillmentOrder::factory()->submitted('SMM-BATCH-1')->create([
            'order_item_id' => $item->id,
        ]);

        // 第 2 批：Owner 換了連結與數量。
        $child = FulfillmentOrder::factory()
            ->replacing($parent, 'replacement_account', 250)
            ->submitted('SMM-BATCH-2')
            ->create();

        $this->assertSame('replacement_account', $child->effectiveTarget());
        $this->assertSame(250, $child->effectiveQuantity());
        // ⛔ 原始列不受影響。
        $this->assertSame('example_account', $parent->fresh()->effectiveTarget());
        $this->assertSame(1000, $parent->fresh()->effectiveQuantity());

        $html = Livewire::actingAs($this->owner())
            ->test(FulfillmentOrdersRelationManager::class, [
                'ownerRecord' => $order,
                'pageClass' => ViewOrder::class,
            ])
            ->html();

        $this->assertStringContainsString('replacement_account', $html);
        $this->assertStringContainsString('第 2 次', $html);
    }

    /** ⭐ 「更換連結」是操作按鈕，⛔ 不算資料欄，且仍只有 Owner 看得到。 */
    public function test_the_replace_action_survives_and_stays_owner_only(): void
    {
        $order = $this->order();

        FulfillmentOrder::factory()->submitted('SMM-ACT-1')->create([
            'order_item_id' => $order->items()->first()->id,
        ]);

        $ownerHtml = Livewire::actingAs($this->owner())
            ->test(FulfillmentOrdersRelationManager::class, [
                'ownerRecord' => $order,
                'pageClass' => ViewOrder::class,
            ])
            ->html();

        $this->assertStringContainsString('更換連結', $ownerHtml);

        $editorHtml = Livewire::actingAs($this->editor())
            ->test(FulfillmentOrdersRelationManager::class, [
                'ownerRecord' => $order,
                'pageClass' => ViewOrder::class,
            ])
            ->html();

        $this->assertStringNotContainsString('更換連結', $editorHtml);
    }

    // ==================================================== 2. 時間線最新在最上

    /**
     * ⛔⛔ 合併後一律**最新在最上**，且重讀順序穩定。
     */
    public function test_the_timeline_puts_the_newest_entry_first(): void
    {
        $order = $this->order();
        $item = $order->items()->first();

        $fulfillment = FulfillmentOrder::factory()->submitted('SMM-TL-1')->create([
            'order_item_id' => $item->id,
        ]);

        $this->orderEvent($order, now()->subMinutes(10));

        DB::table('fulfillment_events')->insert([
            'fulfillment_order_id' => $fulfillment->id,
            'event_code' => 'SUBMITTED',
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $this->orderEvent($order, now());

        $entries = OrderActivityTimeline::for($order->fresh());

        $this->assertGreaterThanOrEqual(3, count($entries));

        // ⛔ created_at 必須是遞減的。
        for ($i = 1; $i < count($entries); $i++) {
            $this->assertTrue(
                $entries[$i - 1]['created_at']->greaterThanOrEqualTo($entries[$i]['created_at']),
                '⛔⛔ 時間線必須最新在最上：'
                .$entries[$i - 1]['created_at'].' 應不早於 '.$entries[$i]['created_at'],
            );
        }
    }

    /**
     * ⛔ 同一秒的事件，重讀兩次的順序必須完全一致。
     *
     * ⭐ 不穩定的排序在畫面上看起來像「事件自己跳來跳去」，
     * 而那種 bug 只在同秒資料上出現，很難事後重現。
     */
    public function test_the_timeline_order_is_stable_across_reads(): void
    {
        $order = $this->order();
        $item = $order->items()->first();

        $fulfillment = FulfillmentOrder::factory()->submitted('SMM-TL-2')->create([
            'order_item_id' => $item->id,
        ]);

        $sameSecond = now()->startOfSecond();

        foreach (range(1, 3) as $i) {
            $this->orderEvent($order, $sameSecond);
        }

        DB::table('fulfillment_events')->insert([
            'fulfillment_order_id' => $fulfillment->id,
            'event_code' => 'SUBMITTED',
            'created_at' => $sameSecond,
            'updated_at' => $sameSecond,
        ]);

        $first = array_column(OrderActivityTimeline::for($order->fresh()), 'key');
        $second = array_column(OrderActivityTimeline::for($order->fresh()), 'key');

        $this->assertSame($first, $second, '⛔ 同秒事件的順序在重讀時必須一致。');
        $this->assertNotEmpty($first);
    }

    // ==================================================== 3. 訂單列表

    /** ⛔⛔ 列表顯示**完整** Email，⛔ HTML 不得含遮罩版本。 */
    public function test_the_list_shows_the_full_email_and_not_the_mask(): void
    {
        $order = $this->order('buyer@example.com');

        $html = Livewire::actingAs($this->owner())
            ->test(ListOrders::class)
            ->assertOk()
            ->html();

        $this->assertStringContainsString('buyer@example.com', $html);
        // ⛔ 遮罩版本不得同時出現。
        $this->assertStringNotContainsString($order->maskedEmail(), $html);
    }

    /** ⛔ 沒有「檢視」按鈕，⭐ 但整列仍連到同一個訂單。 */
    public function test_the_list_has_no_view_button_but_rows_still_link_to_the_order(): void
    {
        $order = $this->order();

        $html = Livewire::actingAs($this->owner())
            ->test(ListOrders::class)
            ->assertOk()
            ->assertTableActionDoesNotExist('view')
            ->html();

        // ⭐ 整列的連結仍指向該訂單的 view route。
        $this->assertStringContainsString(
            '/admin/orders/'.$order->reference,
            $html,
            '⛔⛔ 移除按鈕後，客服仍必須能從列表進入訂單。',
        );
    }

    /** ⛔ 列表沒有「訂單狀態」欄，⭐ 但「付款狀態」仍在，且篩選不動。 */
    public function test_the_list_drops_the_duplicate_order_status_column(): void
    {
        $this->order();

        $html = Livewire::actingAs($this->owner())
            ->test(ListOrders::class)
            ->assertOk()
            ->assertTableColumnDoesNotExist('order_status')
            ->assertTableColumnExists('payment_status')
            ->html();

        // ⭐ 篩選仍保留兩個維度（標籤仍會出現在篩選區）。
        $this->assertStringContainsString('付款狀態', $html);
    }

    // ==================================================== 4. 發票勾叉

    /** ⛔ 只有最新 invoice 為 `Issued` 才算勾。 */
    #[DataProvider('invoiceStatuses')]
    public function test_the_invoice_tick_only_follows_a_saved_issued_invoice(
        ?string $status,
        bool $expected,
    ): void {
        $order = $this->order();

        if ($status !== null) {
            Invoice::factory()->create([
                'order_id' => $order->id,
                'status' => InvoiceStatus::from($status),
            ]);
        }

        $this->assertSame(
            $expected,
            OrderOperationsIndicators::invoiceIssued($order->fresh()),
            '⛔ 發票勾叉判定錯誤：'.($status ?? '無 invoice'),
        );
    }

    /** @return array<string, array{?string, bool}> */
    public static function invoiceStatuses(): array
    {
        return [
            '無 invoice' => [null, false],
            'issued' => ['issued', true],
            'pending' => ['pending', false],
            'pending_configuration' => ['pending_configuration', false],
            'processing' => ['processing', false],
            'failed' => ['failed', false],
            'reconciliation_required' => ['reconciliation_required', false],
            'voided' => ['voided', false],
        ];
    }

    // ==================================================== 5. SMM 勾叉與警示

    /** ⭐ 單一商品已送出 → 勾。 */
    public function test_a_single_submitted_item_is_a_tick(): void
    {
        $order = $this->order();

        FulfillmentOrder::factory()->submitted('SMM-OK-1')->create([
            'order_item_id' => $order->items()->first()->id,
        ]);

        $this->assertSame(
            ['partial' => false, 'canceled' => false, 'pending' => false, 'allSubmitted' => true],
            OrderOperationsIndicators::smm($order->fresh()),
        );
    }

    /** ⛔ 完全沒有履約 → 叉，⛔ 不是勾。 */
    public function test_an_item_without_any_fulfillment_is_a_cross(): void
    {
        $order = $this->order();

        $state = OrderOperationsIndicators::smm($order->fresh());

        $this->assertTrue($state['pending']);
        $this->assertFalse($state['allSubmitted']);
    }

    /** ⛔ 多商品只有部分送出 → 叉。 */
    public function test_a_partially_submitted_multi_item_order_is_a_cross(): void
    {
        $order = $this->order();
        $second = $this->addItem($order, 'ig-second');

        FulfillmentOrder::factory()->submitted('SMM-MULTI-1')->create([
            'order_item_id' => $order->items()->first()->id,
        ]);

        // 第二個商品有履約列，但還沒拿到單號。
        FulfillmentOrder::factory()->create([
            'order_item_id' => $second->id,
            'status' => FulfillmentStatus::Ready,
            'provider_order_id' => null,
        ]);

        $state = OrderOperationsIndicators::smm($order->fresh());

        $this->assertTrue($state['pending'], '⛔ 尚未全部送出必須顯示叉。');
        $this->assertFalse($state['allSubmitted']);
    }

    /**
     * ⛔⛔ 最容易錯的一條：舊批次有單號，但 latest 更換批次還沒送出。
     *
     * ⭐ 那個商品**現在**是沒送出的。⛔ 讀舊批次會把它誤報成勾，
     * 而客服會以為東西已經送去供應商了。
     */
    public function test_an_unsent_replacement_batch_is_never_reported_as_submitted(): void
    {
        $order = $this->order();
        $item = $order->items()->first();

        $parent = FulfillmentOrder::factory()->submitted('SMM-OLD-1')->create([
            'order_item_id' => $item->id,
        ]);

        // ⛔ 第 2 批：Owner 剛建立，還沒派出去（沒有 provider_order_id）。
        FulfillmentOrder::factory()
            ->replacing($parent, 'replacement_account', 250)
            ->create([
                'status' => FulfillmentStatus::Ready,
                'provider_order_id' => null,
            ]);

        $state = OrderOperationsIndicators::smm($order->fresh());

        $this->assertTrue(
            $state['pending'],
            '⛔⛔ latest 更換批次尚未送出，⛔ 不得因為舊批次有單號就顯示勾。',
        );
        $this->assertFalse($state['allSubmitted']);
    }

    /** ⭐ latest 為 `Partial` → warning 三角形。 */
    public function test_a_partial_latest_batch_raises_the_warning_flag(): void
    {
        $order = $this->order();

        FulfillmentOrder::factory()->submitted('SMM-PART-1')->create([
            'order_item_id' => $order->items()->first()->id,
            'status' => FulfillmentStatus::Partial,
            'provider_status_code' => 'Partial',
        ]);

        $state = OrderOperationsIndicators::smm($order->fresh());

        $this->assertTrue($state['partial']);
        $this->assertFalse($state['canceled']);
        $this->assertFalse($state['allSubmitted'], '⛔ Partial 不得同時顯示成勾。');
    }

    /** ⭐ latest 為 `Canceled` → danger 三角形。 */
    public function test_a_canceled_latest_batch_raises_the_danger_flag(): void
    {
        $order = $this->order();

        FulfillmentOrder::factory()->submitted('SMM-CANC-1')->create([
            'order_item_id' => $order->items()->first()->id,
            'status' => FulfillmentStatus::Canceled,
            'provider_status_code' => 'Canceled',
        ]);

        $state = OrderOperationsIndicators::smm($order->fresh());

        $this->assertTrue($state['canceled']);
        $this->assertFalse($state['partial']);
        $this->assertFalse($state['allSubmitted']);
    }

    /** ⛔⛔ 兩種同時存在時，兩個旗標都要成立，⛔ 不得互相覆蓋。 */
    public function test_partial_and_canceled_can_both_be_raised_at_once(): void
    {
        $order = $this->order();
        $second = $this->addItem($order, 'ig-second');

        FulfillmentOrder::factory()->submitted('SMM-BOTH-1')->create([
            'order_item_id' => $order->items()->first()->id,
            'status' => FulfillmentStatus::Partial,
            'provider_status_code' => 'Partial',
        ]);

        FulfillmentOrder::factory()->submitted('SMM-BOTH-2')->create([
            'order_item_id' => $second->id,
            'status' => FulfillmentStatus::Canceled,
            'provider_status_code' => 'Canceled',
        ]);

        $state = OrderOperationsIndicators::smm($order->fresh());

        $this->assertTrue($state['partial'], '⛔ Partial 不得被 Canceled 蓋掉。');
        $this->assertTrue($state['canceled'], '⛔ Canceled 不得被 Partial 蓋掉。');
        $this->assertFalse($state['allSubmitted']);
    }

    /** ⛔ 警示與「尚未送出」的叉可以同時出現，⛔ 不得互相隱藏。 */
    public function test_a_warning_and_a_cross_can_appear_together(): void
    {
        $order = $this->order();
        $second = $this->addItem($order, 'ig-second');

        FulfillmentOrder::factory()->submitted('SMM-MIX-1')->create([
            'order_item_id' => $order->items()->first()->id,
            'status' => FulfillmentStatus::Partial,
            'provider_status_code' => 'Partial',
        ]);

        FulfillmentOrder::factory()->create([
            'order_item_id' => $second->id,
            'status' => FulfillmentStatus::Ready,
            'provider_order_id' => null,
        ]);

        $state = OrderOperationsIndicators::smm($order->fresh());

        $this->assertTrue($state['partial']);
        $this->assertTrue($state['pending'], '⛔ 警示三角形不得把未送出的叉藏起來。');
    }

    // ==================================================== 6. 渲染：平常不露文字

    /**
     * ⛔⛔ 平常不得直接顯示 `Partial`／`Canceled` 文字或藥丸；
     * ⭐ exact token 只出現在 tooltip／`aria-label` 裡。
     */
    public function test_the_list_hides_the_exact_token_behind_a_tooltip(): void
    {
        $order = $this->order();

        FulfillmentOrder::factory()->submitted('SMM-TT-1')->create([
            'order_item_id' => $order->items()->first()->id,
            'status' => FulfillmentStatus::Partial,
            'provider_status_code' => 'Partial',
        ]);

        $html = Livewire::actingAs($this->owner())
            ->test(ListOrders::class)
            ->assertOk()
            ->html();

        /*
         * ⭐ R1：token 由 Filament 的 `icon-button` 以 `x-tooltip` ＋
         * `aria-label` 輸出。
         *
         * ⛔ 該 component 在有 tooltip 時會**刻意把 `title` 設為 null**
         * （`icon-button.blade.php:94`），避免原生 title 與 tooltip 疊加；
         * ⛔ 所以這裡不再斷言 `title=`——初版那條斷言在 R1 之後已不成立。
         */
        $this->assertStringContainsString('aria-label="Partial"', $html);
        $this->assertMatchesRegularExpression(
            "/x-tooltip=\"\{\s*content:\s*'Partial'/su",
            $html,
            '⛔ 警示必須使用 Filament 的 x-tooltip，⛔ 不得只靠原生 title。',
        );

        // ⛔ 但不得作為可見文字節點出現（`>Partial<`）。
        $this->assertStringNotContainsString('>Partial<', $html);
    }

    /**
     * ⛔⛔ R1：點擊／觸控警示圖示**不得**觸發整列的訂單導航。
     *
     * ⭐ 整列是可以點進訂單的連結。初版的手寫 button 沒有隔離事件，
     * ⛔ 於是使用者想看提示、卻直接被帶去另一頁——那個提示等於看不到。
     *
     * ⛔ 同時確認這顆 button 自己沒有 `href`，也不是 submit。
     */
    public function test_the_indicator_buttons_do_not_trigger_row_navigation(): void
    {
        $order = $this->order();

        FulfillmentOrder::factory()->submitted('SMM-STOP-1')->create([
            'order_item_id' => $order->items()->first()->id,
            'status' => FulfillmentStatus::Partial,
            'provider_status_code' => 'Partial',
        ]);

        $html = Livewire::actingAs($this->owner())
            ->test(ListOrders::class)
            ->assertOk()
            ->html();

        // ⭐ 事件必須被隔離。
        $this->assertMatchesRegularExpression(
            '/<button\b[^>]*\bx-on:click\.stop\.prevent=/su',
            $html,
            '⛔⛔ 警示圖示必須阻止 click 冒泡，否則會觸發整列跳轉。',
        );

        // ⛔ 該 button 不得帶 href，也不得是 submit。
        $this->assertMatchesRegularExpression(
            '/<button\b[^>]*\baria-label="Partial"[^>]*>/su',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<button\b[^>]*\baria-label="Partial"[^>]*\bhref=/su',
            $html,
            '⛔ 警示圖示不得是連結。',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<button\b[^>]*\baria-label="Partial"[^>]*\btype="submit"/su',
            $html,
            '⛔ 警示圖示不得是 submit。',
        );

        // ⭐ 整列的訂單連結仍然存在。
        $this->assertStringContainsString('/admin/orders/'.$order->reference, $html);
    }

    /**
     * ⛔⛔ warning 與 danger 的顏色不得對調。
     *
     * ⭐ 顏色是這一欄唯一能在不展開 tooltip 的情況下傳達嚴重度的線索；
     * 對調等於把「已取消」講成「還在跑」。
     */
    public function test_the_warning_and_danger_colours_are_not_swapped(): void
    {
        $order = $this->order();
        $second = $this->addItem($order, 'ig-second');

        FulfillmentOrder::factory()->submitted('SMM-CLR-1')->create([
            'order_item_id' => $order->items()->first()->id,
            'status' => FulfillmentStatus::Partial,
            'provider_status_code' => 'Partial',
        ]);

        FulfillmentOrder::factory()->submitted('SMM-CLR-2')->create([
            'order_item_id' => $second->id,
            'status' => FulfillmentStatus::Canceled,
            'provider_status_code' => 'Canceled',
        ]);

        $html = Livewire::actingAs($this->owner())
            ->test(ListOrders::class)
            ->assertOk()
            ->html();

        /*
         * ⭐ 直接比對**同一個 button 標籤內**的 class 與 `aria-label`。
         *
         * ⛔ 不用「往前抓 N 個字元再看有沒有 warning」那種寫法：
         * 兩顆圖示在 HTML 裡相鄰，抓太寬會抓到隔壁那顆的 class，
         * 於是顏色對調時測試仍然會通過——那等於沒有測。
         *
         * ⛔ R1：Filament 的 `icon-button` 輸出的是 `fi-color-warning`／
         * `fi-color-danger`（它自己的 color 系統），⛔ 不是我初版手寫的
         * `text-warning-600`；而且有 tooltip 時不再輸出 `title`。
         */
        $this->assertMatchesRegularExpression(
            '/<button\b[^>]*\bfi-color-warning\b[^>]*\baria-label="Partial"/su',
            $html,
            '⛔⛔ Partial 必須是 warning 色（同一個 button 上）。',
        );

        $this->assertMatchesRegularExpression(
            '/<button\b[^>]*\bfi-color-danger\b[^>]*\baria-label="Canceled"/su',
            $html,
            '⛔⛔ Canceled 必須是 danger 色（同一個 button 上）。',
        );

        // ⛔ 反向：顏色不得對調。
        $this->assertDoesNotMatchRegularExpression(
            '/<button\b[^>]*\bfi-color-danger\b[^>]*\baria-label="Partial"/su',
            $html,
            '⛔ Partial 不得是 danger 色。',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/<button\b[^>]*\bfi-color-warning\b[^>]*\baria-label="Canceled"/su',
            $html,
            '⛔ Canceled 不得是 warning 色。',
        );
    }

    // ==================================================== 7. N+1

    /**
     * ⛔⛔ 列表不得逐列查詢。
     *
     * ⭐ 用**查詢次數**反證：多加訂單不應該讓查詢次數等比成長。
     * ⛔ 只斷言「有 with()」是不夠的——那不會在 relation 改名時失敗。
     */
    public function test_the_list_does_not_run_a_query_per_row(): void
    {
        $owner = $this->owner();

        foreach (range(1, 5) as $i) {
            $order = $this->order("buyer{$i}@example.com");

            FulfillmentOrder::factory()->submitted("SMM-N1-{$i}")->create([
                'order_item_id' => $order->items()->first()->id,
            ]);

            Invoice::factory()->create([
                'order_id' => $order->id,
                'status' => InvoiceStatus::Issued,
            ]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        Livewire::actingAs($owner)->test(ListOrders::class)->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        /*
         * ⭐ 5 張訂單、每張含 items／latest 履約／invoice。
         * ⛔ 若逐列查詢，光是這三種關聯就會超過 15 次額外查詢。
         * 這個上限刻意寬鬆——它要抓的是**等比成長**，
         * ⛔ 不是把查詢次數凍結成一個易碎的數字。
         */
        $this->assertLessThan(
            30,
            $count,
            "⛔⛔ 列表可能有 N+1：實際查詢 {$count} 次。",
        );
    }
}
