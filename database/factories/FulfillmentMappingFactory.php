<?php

namespace Database\Factories;

use App\Enums\FulfillmentPayloadType;
use App\Enums\IntegrationProvider;
use App\Models\FulfillmentMapping;
use App\Models\ServiceVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FulfillmentMapping>
 */
class FulfillmentMappingFactory extends Factory
{
    protected $model = FulfillmentMapping::class;

    /**
     * ⛔ `provider_service_id` is an obviously fake value, and disabled by
     * default. No real TheMostPanel service id appears anywhere in this
     * repository — one committed to Git is one that has to be treated as
     * public.
     */
    public function definition(): array
    {
        return [
            'service_variant_id' => ServiceVariant::factory(),
            'provider' => IntegrationProvider::TheMostPanel->value,
            'provider_service_id' => 'FAKE-SERVICE-0000',
            'payload_type' => FulfillmentPayloadType::LinkQuantity->value,
            'is_enabled' => false,
        ];
    }

    public function enabled(): static
    {
        return $this->state(fn () => ['is_enabled' => true]);
    }
}
