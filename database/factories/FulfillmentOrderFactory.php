<?php

namespace Database\Factories;

use App\Enums\FulfillmentStatus;
use App\Enums\IntegrationProvider;
use App\Models\FulfillmentOrder;
use App\Models\OrderItem;
use App\Models\User;
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
    /**
     * ⭐ 一筆更換履約（第 N 批）。
     *
     * ⛔ DB 的 shape guard 要求 sequence >1 的列必須同時具備 parent、
     * encrypted target、正整數數量、建議值快照與建立者——少任何一項都會被拒絕。
     */
    public function replacing(FulfillmentOrder $parent, string $target = 'https://example.invalid/replacement', int $quantity = 500): static
    {
        return $this->state(fn () => [
            'order_item_id' => $parent->order_item_id,
            'sequence_no' => $parent->sequence_no + 1,
            'replaces_fulfillment_order_id' => $parent->getKey(),
            'target_value_override' => $target,
            'quantity_override' => $quantity,
            'suggested_quantity_snapshot' => $parent->provider_remains ?? $quantity,
            'replacement_created_by_user_id' => User::factory(),
            'provider_service_id_snapshot' => $parent->provider_service_id_snapshot ?? 'FAKE-SERVICE-0000',
            'payload_type_snapshot' => $parent->payload_type_snapshot?->value ?? 'link_quantity',
        ]);
    }

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
