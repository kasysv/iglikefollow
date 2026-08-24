<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'sku' => $this->faker->unique()->slug(3),
            'label' => $this->faker->words(2, true),
            'quantity_unit' => 'x',
            'min_quantity' => 100,
            'max_quantity' => 10000,
            // ⛔ M3A:legacy 欄位(DB 仍 NOT NULL),已不影響購買規則。
            // 新資料一律 1,不再暗示任何倍數限制。
            'step_quantity' => 1,
            'default_quantity' => 1000,
            'unit_price' => 0.5,
            'currency' => 'TWD',
            'status' => 'draft',
            'sort_order' => 0,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => 'published',
            'first_published_at' => now(),
        ]);
    }
}
