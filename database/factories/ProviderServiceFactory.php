<?php

namespace Database\Factories;

use App\Enums\IntegrationProvider;
use App\Models\ProviderService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderService>
 *
 * ⛔ Public-doc-derived fictional fixture. Every value is invented to match the
 * *shape* of the documented `services` example — no real TheMostPanel service
 * id, name or rate appears anywhere in this repository, and nothing here may
 * be presented as the user's account catalog or seeded into a live database.
 */
class ProviderServiceFactory extends Factory
{
    protected $model = ProviderService::class;

    public function definition(): array
    {
        return [
            'provider' => IntegrationProvider::TheMostPanel->value,
            'provider_service_id' => (string) $this->faker->unique()->numberBetween(90001, 99999),
            'name' => '虛構測試服務 '.$this->faker->unique()->numberBetween(1, 9999),
            'service_type' => 'Default',
            'category' => '虛構分類',
            'rate_raw' => '0.90',
            'minimum_quantity_raw' => '10',
            'maximum_quantity_raw' => '10000',
            'supports_refill' => false,
            'supports_cancel' => false,
            // ⛔ 預設不可用：只有完整成功的真實 snapshot 才可標 true。
            'is_available' => false,
            'first_seen_at' => null,
            'last_seen_at' => null,
        ];
    }

    public function available(): static
    {
        return $this->state(fn () => ['is_available' => true]);
    }
}
