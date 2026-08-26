<?php

namespace Tests\Feature;

use App\Enums\FulfillmentEventCode;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\FulfillmentOrdersRelationManager;
use App\Filament\Resources\Orders\RelationManagers\OrderEventsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\PaymentAttemptsRelationManager;
use App\Models\FulfillmentOrder;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Support\OrderActivityTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The order admin is a window, not a control panel.
 *
 * Support needs to answer "what happened to this order", so both roles can
 * read. Nothing may write: an order records what a customer agreed to and
 * what a provider confirmed, so a hand-edited "paid" would defeat the
 * verification the whole lifecycle exists to enforce.
 */
class OrderAdminTest extends TestCase
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

    private function order(): Order
    {
        $order = Order::factory()->create([
            'customer_email' => 'private@example.com',
            'customer_phone' => '0912345678',
        ]);

        $order->items()->create([
            'platform_name' => 'Instagram',
            'service_name' => 'Instagram 粉絲',
            'variant_label' => '一般粉絲',
            'sku' => 'ig-followers-standard',
            'unit_price_mills' => 5900,
            'quantity' => 1000,
            'quantity_unit' => '個',
            'amount' => 590,
            'target_kind' => 'account',
            'target_value' => 'example_account',
        ]);

        PaymentAttempt::factory()->create(['order_id' => $order->id]);

        return $order->fresh();
    }

    // ------------------------------------------------------------ M4C 交易流程摘要

    public function test_the_operations_summary_shows_four_independent_lanes(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->owner())->get('/admin/orders/'.$order->reference);

        $response->assertOk();
        $response->assertSee('交易流程');
        // 四條線各自呈現;不存在的紀錄是「尚未建立」,不是成功或失敗。
        $response->assertSee('尚未建立'); // invoice 與 fulfillment 都還沒有
        $response->assertSee('最新嘗試'); // payment attempt 存在(initiated)
    }

    public function test_multiple_payment_attempts_follow_the_deterministic_rule(): void
    {
        $order = $this->order();
        // 第二筆:成功——有成功以成功為準,不取任意 first()。
        PaymentAttempt::factory()->create([
            'order_id' => $order->id,
            'status' => PaymentStatus::Succeeded,
        ]);

        $response = $this->actingAs($this->owner())->get('/admin/orders/'.$order->reference);

        $response->assertOk();
        $response->assertSee('已成功(1/2 次嘗試)');
    }

    /** ⛔ mixed fulfillment 不得標成全部完成。 */
    public function test_mixed_fulfillment_rows_are_never_labelled_fully_complete(): void
    {
        $order = $this->order();
        $item = $order->items()->first();

        $second = $order->items()->create([
            'platform_name' => 'Instagram', 'service_name' => 'Instagram 粉絲',
            'variant_label' => '真人粉絲', 'sku' => 'ig-followers-real-x',
            'unit_price_mills' => 5900, 'quantity' => 500, 'quantity_unit' => '個',
            'amount' => 295, 'target_kind' => 'account', 'target_value' => 'example_account',
        ]);

        $completed = FulfillmentOrder::factory()->submitted('71001')->create(['order_item_id' => $item->id]);
        $completed->forceFill(['status' => FulfillmentStatus::Completed])->save();
        FulfillmentOrder::factory()->submitted('71002')->create(['order_item_id' => $second->id]);

        $response = $this->actingAs($this->owner())->get('/admin/orders/'.$order->reference);

        $response->assertOk();
        $response->assertDontSee('全部完成');
        $response->assertSee('共 2 筆');
    }

    public function test_all_completed_fulfillment_rows_are_labelled_fully_complete(): void
    {
        $order = $this->order();
        $item = $order->items()->first();

        $second = $order->items()->create([
            'platform_name' => 'Instagram', 'service_name' => 'Instagram 粉絲',
            'variant_label' => '真人粉絲', 'sku' => 'ig-followers-real-y',
            'unit_price_mills' => 5900, 'quantity' => 500, 'quantity_unit' => '個',
            'amount' => 295, 'target_kind' => 'account', 'target_value' => 'example_account',
        ]);

        foreach ([[$item, '72001'], [$second, '72002']] as [$target, $id]) {
            $row = FulfillmentOrder::factory()->submitted($id)->create(['order_item_id' => $target->id]);
            $row->forceFill(['status' => FulfillmentStatus::Completed])->save();
        }

        $response = $this->actingAs($this->owner())->get('/admin/orders/'.$order->reference);

        $response->assertOk();
        $response->assertSee('全部完成(2/2)');
    }

    // ------------------------------------------------------------ 權限

    public function test_an_owner_can_list_and_view_orders(): void
    {
        $this->actingAs($this->owner());
        $order = $this->order();

        Livewire::test(ListOrders::class)->assertOk()->assertCanSeeTableRecords([$order]);
        Livewire::test(ViewOrder::class, ['record' => $order->reference])->assertOk();
    }

    public function test_an_editor_can_also_view_orders(): void
    {
        $this->actingAs($this->editor());
        $order = $this->order();

        // 客服需要查得到付款結果。
        Livewire::test(ListOrders::class)->assertOk()->assertCanSeeTableRecords([$order]);
    }

    public function test_the_policy_refuses_every_write(): void
    {
        $owner = $this->owner();
        $order = $this->order();

        $this->assertTrue($owner->can('view', $order));
        // ⛔ 建立、修改、刪除一律拒絕，即使是 owner。
        $this->assertFalse($owner->can('create', Order::class));
        $this->assertFalse($owner->can('update', $order));
        $this->assertFalse($owner->can('delete', $order));
        $this->assertFalse($owner->can('forceDelete', $order));
    }

    // ------------------------------------------------------------ 唯讀

    public function test_the_resource_exposes_no_create_edit_or_delete(): void
    {
        $order = $this->order();

        $this->assertFalse(OrderResource::canCreate());
        $this->assertFalse(OrderResource::canEdit($order));
        $this->assertFalse(OrderResource::canDelete($order));
        $this->assertFalse(OrderResource::canDeleteAny());
    }

    public function test_the_resource_registers_no_create_or_edit_page(): void
    {
        // ⛔ 連路由都不存在，不只是隱藏按鈕。
        $this->assertSame(['index', 'view'], array_keys(OrderResource::getPages()));
    }

    public function test_the_list_screen_offers_no_actions_that_could_change_an_order(): void
    {
        $this->actingAs($this->owner());
        $this->order();

        Livewire::test(ListOrders::class)
            ->assertOk()
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');
    }

    public function test_the_detail_screen_has_no_header_actions(): void
    {
        $this->actingAs($this->owner());
        $order = $this->order();

        // ⛔ 沒有「標記為已付款」這類手動改狀態的入口。
        Livewire::test(ViewOrder::class, ['record' => $order->reference])
            ->assertOk()
            ->assertActionDoesNotExist('edit')
            ->assertActionDoesNotExist('delete')
            ->assertActionDoesNotExist('markPaid');
    }

    public function test_the_payment_attempts_panel_is_read_only(): void
    {
        $this->actingAs($this->owner());
        $order = $this->order();

        $this->assertTrue((new PaymentAttemptsRelationManager)->isReadOnly());
        // ⛔ 三個 relation manager 全部唯讀。合併時間線更是連 table 都沒有——
        // 它用自訂 view 純呈現，⛔ 沒有任何寫入路徑可言。
        $this->assertTrue((new FulfillmentOrdersRelationManager)->isReadOnly());
        $this->assertTrue((new OrderEventsRelationManager)->isReadOnly());

        $this->assertContains(
            PaymentAttemptsRelationManager::class,
            OrderResource::getRelations()
        );
    }

    // ------------------------------------------------------------ 個資

    public function test_the_admin_list_masks_the_customer_email(): void
    {
        $this->actingAs($this->owner());
        $this->order();

        $html = Livewire::test(ListOrders::class)->assertOk()->html();

        // ⛔ 後台列表也不顯示完整 Email。
        $this->assertStringNotContainsString('private@example.com', $html);
        $this->assertStringContainsString('@example.com', $html);
    }

    /** ⛔ M4C:Owner 詳情頁改為完整顯示聯絡資料，客服才能真的聯絡客人。 */
    public function test_the_owner_detail_screen_shows_full_contact_details(): void
    {
        $this->actingAs($this->owner());
        $order = $this->order();

        $page = Livewire::test(ViewOrder::class, ['record' => $order->reference])->assertOk();

        $this->assertStringContainsString('private@example.com', $page->html());
        $this->assertStringContainsString('0912345678', $page->html());
        $this->assertStringContainsString('example_account', $page->html());
    }

    /** ⛔ Editor 沿用 OrderPolicy，客服工作需要完整聯絡與交付資料。 */
    public function test_the_editor_detail_screen_also_shows_full_contact_details(): void
    {
        $this->actingAs($this->editor());
        $order = $this->order();

        $page = Livewire::test(ViewOrder::class, ['record' => $order->reference])->assertOk();

        $this->assertStringContainsString('private@example.com', $page->html());
        $this->assertStringContainsString('0912345678', $page->html());
    }

    /**
     * ⛔ 發票是稅務資料：Editor 進得了訂單頁，但完整發票值一個都不得出現，
     * 不管是在 HTML 還是 Livewire snapshot 裡——不只是 section 標題消失，
     * 是連值本身都不能在別處意外冒出來。用一筆帶滿四種模式相關欄位、
     * 且有實際 Invoice 的訂單建資料，逐值檢查 Editor 看不到任何一個。
     */
    public function test_the_editor_detail_screen_leaks_no_invoice_value_anywhere(): void
    {
        $order = Order::factory()->create([
            'invoice_kind' => 'business',
            'personal_invoice_mode' => null,
            'buyer_tax_id' => '87654321',
            'buyer_name' => '編輯者不可見股份有限公司',
        ]);

        $invoice = Invoice::factory()->create([
            'order_id' => $order->id,
            'invoice_number' => 'ED99887766',
            /*
             * ⛔ 這個值會被當成「不得出現在 Editor HTML」的 sentinel 逐字搜尋。
             *
             * 原本是 `4321`——四位數字太容易與頁面上其他數字（例如履約的
             * 起始值／剩餘數量、金額千分位）巧合相符，讓這個安全測試偶發
             * 誤報。改用一個不可能自然出現的字串，測的仍是同一件事，但
             * 不再受其他區塊的數字影響。
             */
            'random_code' => 'RND-EDITOR-ONLY-SENTINEL',
            'provider_reference' => 'EDITOR-SAFE-REF-11223',
        ]);

        $this->actingAs($this->editor());

        $page = Livewire::test(ViewOrder::class, ['record' => $order->reference])->assertOk();
        $html = $page->html();
        $snapshotJson = json_encode($page->snapshot, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('客戶要求的發票資料', $html);
        $this->assertStringNotContainsString('實際開立結果', $html);

        foreach ([
            '87654321', '編輯者不可見股份有限公司',
            $invoice->invoice_number, $invoice->random_code, $invoice->provider_reference,
        ] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $html, "Editor HTML 出現敏感值：{$sensitive}");
            $this->assertStringNotContainsString($sensitive, (string) $snapshotJson, "Editor Livewire snapshot 出現敏感值：{$sensitive}");
        }
    }

    /** ⛔ Owner 看得到發票 section 標題與統編相關欄位（個人 Email 模式不顯示統編）。 */
    public function test_the_owner_detail_screen_shows_invoice_sections(): void
    {
        $this->actingAs($this->owner());
        $order = $this->order();

        $html = Livewire::test(ViewOrder::class, ['record' => $order->reference])->assertOk()->html();

        $this->assertStringContainsString('客戶要求的發票資料', $html);
        $this->assertStringContainsString('實際開立結果', $html);
    }

    /**
     * ⛔ 四種發票輸入模式的顯示矩陣：每種模式只出現自己的完整值，
     * 不出現其他模式的欄位；`invoice_kind` 標籤是明確類型文字，
     * 不是 `invoiceSummary()` 的遮罩摘要（否則公司模式統編會重複顯示一次
     * 「後 3 碼」版本）。
     *
     * @return array<string, array{0: string, 1: ?string, 2: array<string, mixed>, 3: string, 4: list<string>, 5: list<string>}>
     */
    public static function invoiceModeProvider(): array
    {
        return [
            'personal email' => [
                'personal', 'email',
                ['customer_email' => 'personal-email-mode@example.com'],
                '個人電子發票（寄送至 Email）',
                ['personal-email-mode@example.com'],
                ['手機條碼載具', '捐贈碼', '統一編號', '公司抬頭'],
            ],
            'personal mobile barcode' => [
                'personal', 'mobile_barcode',
                ['carrier_number' => '/AB12345'],
                '個人電子發票（手機條碼載具）',
                ['/AB12345'],
                ['統一編號', '公司抬頭'],
            ],
            'personal donation' => [
                'personal', 'donation',
                ['love_code' => 'X9988'],
                '個人電子發票（捐贈）',
                ['X9988'],
                ['手機條碼載具', '統一編號', '公司抬頭'],
            ],
            'business' => [
                'business', null,
                ['buyer_tax_id' => '55667788', 'buyer_name' => '矩陣測試股份有限公司'],
                '公司電子發票',
                ['55667788', '矩陣測試股份有限公司'],
                ['手機條碼載具', '捐贈碼', '寄送 Email'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $expectedPresent
     * @param  list<string>  $expectedAbsentLabels
     */
    #[DataProvider('invoiceModeProvider')]
    public function test_the_owner_detail_screen_shows_only_the_matching_invoice_mode_fields(
        string $invoiceKind,
        ?string $personalMode,
        array $attributes,
        string $expectedTypeLabel,
        array $expectedPresent,
        array $expectedAbsentLabels,
    ): void {
        $this->actingAs($this->owner());
        $order = Order::factory()->create(array_merge([
            'invoice_kind' => $invoiceKind,
            'personal_invoice_mode' => $personalMode,
        ], $attributes));

        $html = Livewire::test(ViewOrder::class, ['record' => $order->reference])->assertOk()->html();

        $this->assertStringContainsString($expectedTypeLabel, $html);

        foreach ($expectedPresent as $value) {
            $this->assertStringContainsString($value, $html, "應顯示：{$value}");
        }

        foreach ($expectedAbsentLabels as $label) {
            $this->assertStringNotContainsString($label, $html, "不應出現不相關欄位：{$label}");
        }
    }

    public function test_masking_helpers_reveal_only_the_tail(): void
    {
        $order = Order::factory()->make([
            'customer_email' => 'buyer@example.com',
            'customer_phone' => '0912-345-678',
        ]);

        $this->assertSame('b****@example.com', $order->maskedEmail());
        $this->assertSame('*******678', $order->maskedPhone());
    }

    // ------------------------------------------------------------ M4C-ORDER-OPERATIONS-A：訂單頁直接顯示履約

    public function test_the_order_page_shows_smm_fulfillment_progress_directly(): void
    {
        $this->actingAs($this->owner());
        $order = $this->order();
        $item = $order->items()->first();

        FulfillmentOrder::factory()->submitted('SMM-ORDER-998877')->create([
            'order_item_id' => $item->id,
            'provider_service_name_snapshot' => '完整 SMM 服務名稱',
        ]);

        $html = Livewire::test(ViewOrder::class, ['record' => $order->reference])->assertOk()->html();

        $this->assertStringContainsString('SMM 履約進度', $html);
        $this->assertStringContainsString('完整 SMM 服務名稱', $html);
        $this->assertStringContainsString('SMM-ORDER-998877', $html);
    }

    public function test_the_smm_progress_section_is_hidden_when_there_is_nothing_to_show(): void
    {
        $this->actingAs($this->owner());
        $order = $this->order(); // 沒有建立任何 FulfillmentOrder。

        $html = Livewire::test(ViewOrder::class, ['record' => $order->reference])->assertOk()->html();

        $this->assertStringNotContainsString('SMM 履約進度', $html);
    }

    public function test_the_order_page_shows_the_merged_activity_timeline(): void
    {
        $this->actingAs($this->owner());
        $order = $this->order();
        $item = $order->items()->first();

        $fulfillment = FulfillmentOrder::factory()->submitted('SMM-TIMELINE-1')->create([
            'order_item_id' => $item->id,
            'provider_service_name_snapshot' => '時間表服務名稱',
        ]);
        // ⛔ factory 的 submitted() 只直接設定欄位，不會寫 event；這裡手動補上
        // 兩筆真實事件，才測得到 OrderActivityTimeline 的固定中文句子對應。
        $fulfillment->recordEvent(
            FulfillmentEventCode::Submitted,
            from: FulfillmentStatus::Ready,
            to: FulfillmentStatus::Submitted,
        );
        $fulfillment->recordEvent(
            FulfillmentEventCode::StatusSynced,
            from: FulfillmentStatus::Submitted,
            to: FulfillmentStatus::Processing,
        );

        /*
         * ⭐ R1：合併時間線的唯一呈現位置是**下方**的「訂單時間線」
         * RelationManager；主畫面那個重複的「訂單時間表」Section 已移除。
         *
         * ⛔ A1 做反了方向（移除下方、保留上方），與 Owner 原話相反——原話是
         * 把主畫面的「訂單時間表」**併入下方既有的**「訂單時間線」。R1 改回來。
         *
         * ⛔ 主畫面必須不再出現舊 Section 的標題：同一份合併資料在一頁出現
         * 兩次，客服會不確定哪一個才是完整的。
         */
        $page = Livewire::test(ViewOrder::class, ['record' => $order->reference])
            ->assertOk()
            ->html();

        $this->assertStringNotContainsString('訂單時間表', $page, '⛔ 主畫面不得有第二條時間線。');
        $this->assertStringNotContainsString('已在 SMM 平台下單', $page, '⛔ 合併內容不得留在主畫面。');

        /*
         * ⛔ 下方的唯一時間線必須同時含**兩個來源**——order events ＋
         * fulfillment events。只有 order events 的話，它就只是原本那個
         * relation manager，Owner 要的合併等於沒做。
         */
        $timeline = Livewire::test(OrderEventsRelationManager::class, [
            'ownerRecord' => $order->fresh(),
            'pageClass' => ViewOrder::class,
        ])->assertOk()->html();

        $this->assertStringContainsString('已在 SMM 平台下單', $timeline);
        $this->assertStringContainsString('SMM 平台已進行中', $timeline);
        $this->assertStringContainsString('時間表服務名稱', $timeline);
    }

    /**
     * ⭐ R1：三個 relation manager 都掛載，合併時間線是最後一個。
     *
     * ⛔ A1 把 events 那個拿掉了；R1 重新掛回來，因為它才是 Owner 指定的
     * 唯一時間線位置。
     */
    public function test_the_merged_timeline_relation_manager_is_mounted_last(): void
    {
        $this->assertSame(
            [
                PaymentAttemptsRelationManager::class,
                FulfillmentOrdersRelationManager::class,
                OrderEventsRelationManager::class,
            ],
            OrderResource::getRelations(),
        );
    }

    /**
     * ⭐ A1：合併時間線每列都有穩定唯一 key。
     *
     * ⛔ 不能用陣列索引——新事件插入時整批位移，列狀態會跳到別列；也不能單用
     * `id`，因為 `order_events` 與 `fulfillment_events` 的自增 id 會撞號。
     */
    public function test_the_merged_timeline_gives_every_row_a_stable_unique_key(): void
    {
        $order = $this->order();
        $item = $order->items()->first();

        $fulfillment = FulfillmentOrder::factory()->submitted('SMM-KEY-1')->create([
            'order_item_id' => $item->id,
        ]);
        $fulfillment->recordEvent(
            FulfillmentEventCode::Submitted,
            from: FulfillmentStatus::Ready,
            to: FulfillmentStatus::Submitted,
        );

        $entries = OrderActivityTimeline::for($order->fresh());
        $keys = array_column($entries, 'key');

        $this->assertNotEmpty($keys);
        $this->assertCount(count($keys), array_unique($keys), '⛔ key 必須唯一。');

        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/\A(order|fulfillment):[0-9]+\z/', $key);
        }

        // ⛔ 兩次讀取必須得到完全相同的順序與 key（穩定排序）。
        $this->assertSame($keys, array_column(OrderActivityTimeline::for($order->fresh()), 'key'));

        /*
         * ⛔ 兩個來源都必須能出現在同一條時間線上。
         *
         * `$this->order()` 本身不寫 order_events（訂單是 factory 直接建立的），
         * 所以這裡明確補一筆，才驗證得到「合併」而不是「只剩履約事件」。
         */
        $order->events()->create([
            'type' => OrderEvent::TYPE_ORDER_CREATED,
            'summary' => '結帳驗證通過，訂單建立為待付款。',
        ]);

        $sources = array_unique(array_column(OrderActivityTimeline::for($order->fresh()), 'source'));
        sort($sources);
        $this->assertSame(['fulfillment', 'order'], $sources);
    }

    // ------------------------------------------------------------ M4C-ORDER-OPERATIONS-A：補開發票按鈕

    public function test_the_recover_invoice_action_is_hidden_from_editor(): void
    {
        $this->actingAs($this->editor());
        $order = $this->order();

        Livewire::test(ViewOrder::class, ['record' => $order->reference])
            ->assertOk()
            ->assertActionHidden('recoverInvoice');
    }

    public function test_the_recover_invoice_action_is_visible_but_disabled_without_a_paid_order(): void
    {
        $owner = $this->owner();
        $order = Order::factory()->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'paid_at' => null,
        ]);

        Livewire::actingAs($owner)
            ->test(ViewOrder::class, ['record' => $order->reference])
            ->assertOk()
            ->assertActionVisible('recoverInvoice')
            ->assertActionDisabled('recoverInvoice');
    }
}
