<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * A product snapshot as `CreatePendingOrder` would have frozen it.
     *
     * ⛔ `target_value` is an obviously fake test account. Fulfilment tests
     * assert this value never leaves the encrypted column — it must be a string
     * nobody could mistake for a real customer's.
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'service_variant_id' => null,
            'platform_name' => 'Instagram',
            'service_name' => '粉絲',
            'variant_label' => '標準',
            'sku' => 'ig-followers-standard',
            'external_sku' => null,
            'unit_price_mills' => 590,
            'unit_price_cents' => 5,
            'quantity' => 1000,
            'quantity_unit' => '個',
            'amount' => 590,
            'target_kind' => 'account',
            'target_value' => '@fulfillment-test-account',
        ];
    }
}
