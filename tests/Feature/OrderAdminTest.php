<?php

namespace Tests\Feature;

use App\Enums\FulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\OrderEventsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\PaymentAttemptsRelationManager;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    /** ⛔ 發票是稅務資料：Editor 進得了訂單頁，但看不到發票 section 的完整值。 */
    public function test_the_editor_detail_screen_hides_invoice_sections(): void
    {
        $this->actingAs($this->editor());
        $order = $this->order();

        $page = Livewire::test(ViewOrder::class, ['record' => $order->reference])->assertOk();

        $this->assertStringNotContainsString('客戶要求的發票資料', $page->html());
        $this->assertStringNotContainsString('實際開立結果', $page->html());
        $this->assertStringNotContainsString(
            json_encode('客戶要求的發票資料', JSON_UNESCAPED_UNICODE),
            json_encode($page->snapshot, JSON_UNESCAPED_UNICODE),
        );
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

    /** ⛔ 公司發票模式顯示完整統編與抬頭，個人 Email 模式不堆不相關欄位。 */
    public function test_the_owner_detail_screen_shows_company_invoice_fields(): void
    {
        $this->actingAs($this->owner());
        $order = Order::factory()->create([
            'customer_email' => 'company-buyer@example.com',
            'invoice_kind' => 'business',
            'personal_invoice_mode' => null,
            'buyer_tax_id' => '12345678',
            'buyer_name' => '測試股份有限公司',
        ]);

        $html = Livewire::test(ViewOrder::class, ['record' => $order->reference])->assertOk()->html();

        $this->assertStringContainsString('12345678', $html);
        $this->assertStringContainsString('測試股份有限公司', $html);
        $this->assertStringNotContainsString('手機條碼載具', $html);
        $this->assertStringNotContainsString('捐贈碼', $html);
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
}
