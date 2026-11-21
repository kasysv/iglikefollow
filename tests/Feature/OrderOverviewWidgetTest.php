<?php

namespace Tests\Feature;

use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Filament\Widgets\OrderOverview;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 首頁訂單概況：總訂單／處理中／已完成。
 *
 * ⛔⛔ 判定必須與訂單列表 SMM 欄相同：列表是 O 的就算處理中，列表是勾的才算完成。
 */
class OrderOverviewWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function paidOrder(?FulfillmentStatus $status, ?string $providerId = 'SMM-1', OrderStatus $orderStatus = OrderStatus::Paid): Order
    {
        $order = Order::factory()->create(['order_status' => $orderStatus]);

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
            'target_value' => 'example_account',
        ]);

        if ($status !== null) {
            FulfillmentOrder::factory()->submitted($providerId ?? 'X')->create([
                'order_item_id' => $item->id,
                'status' => $status,
                'provider_order_id' => $providerId,
            ]);
        }

        return $order;
    }

    public function test_counts_follow_the_same_rules_as_the_order_list(): void
    {
        $this->paidOrder(FulfillmentStatus::Completed, 'A');
        $this->paidOrder(FulfillmentStatus::Completed, 'B');
        $this->paidOrder(FulfillmentStatus::Pending, 'C');
        $this->paidOrder(FulfillmentStatus::Processing, 'D');
        $this->paidOrder(FulfillmentStatus::Submitted, 'E');
        $this->paidOrder(FulfillmentStatus::Partial, 'F');
        $this->paidOrder(FulfillmentStatus::Canceled, 'G');
        $this->paidOrder(null); // 已付款、尚未建立履約

        // ⛔ 未成立的訂單不計入總訂單。
        $this->paidOrder(null, null, OrderStatus::PendingPayment);
        $this->paidOrder(null, null, OrderStatus::Expired);
        $this->paidOrder(null, null, OrderStatus::Canceled);

        $this->assertSame(
            ['total' => 8, 'inProgress' => 3, 'completed' => 2],
            OrderOverview::counts(),
        );
    }

    public function test_the_dashboard_shows_the_three_numbers(): void
    {
        $this->paidOrder(FulfillmentStatus::Completed, 'A');
        $this->paidOrder(FulfillmentStatus::Processing, 'B');
        $this->paidOrder(FulfillmentStatus::Partial, 'C');

        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        Livewire::actingAs($owner)
            ->test(OrderOverview::class)
            ->assertOk()
            ->assertSeeInOrder(['總訂單', '3', '處理中', '1', '已完成', '1'])
            ->assertSee('另有 1 筆');

        /*
         * ⭐ Dashboard 的 widget 是 lazy 載入：頁面 HTML 只有佔位與元件名稱，
         * 數字在之後的 Livewire 請求才出現（上面已驗證）。這裡驗證它確實掛在首頁。
         */
        $this->actingAs($owner)->get('/ignfdash')
            ->assertOk()
            ->assertSee('wire:name="App\Filament\Widgets\OrderOverview"', false);
    }

    public function test_an_editor_who_can_see_orders_also_sees_the_numbers(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'editor', 'is_active' => true]));

        $this->assertTrue(OrderOverview::canView());
    }

    public function test_a_guest_cannot_see_the_numbers(): void
    {
        $this->assertFalse(OrderOverview::canView());
    }
}
