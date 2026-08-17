<?php

namespace Database\Factories;

use App\Enums\FulfillmentStatus;
use App\Enums\IntegrationProvider;
use App\Models\FulfillmentOrder;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FulfillmentOrder>
 */
class FulfillmentOrderFactory extends Factory
{
    protected $model = FulfillmentOrder::class;

    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'provider' => IntegrationProvider::TheMostPanel->value,
            'status' => FulfillmentStatus::ConfigurationPending->value,
            'attempt_count' => 0,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn () => [
            'status' => FulfillmentStatus::Ready->value,
            'provider_service_id_snapshot' => 'FAKE-SERVICE-0000',
            'payload_type_snapshot' => 'link_quantity',
        ]);
    }

    /** ⛔ submitted 一定要有 provider_order_id，否則 DB constraint 會拒絕。 */
    public function submitted(string $providerOrderId = 'FAKE-1'): static
    {
        return $this->state(fn () => [
            'status' => FulfillmentStatus::Submitted->value,
            'provider_order_id' => $providerOrderId,
            'provider_service_id_snapshot' => 'FAKE-SERVICE-0000',
            'payload_type_snapshot' => 'link_quantity',
            'submitted_at' => now(),
            'attempt_count' => 1,
        ]);
    }
}
