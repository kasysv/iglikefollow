<?php

namespace Database\Factories;

use App\Models\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ServiceFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'platform_id' => Platform::factory(),
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'summary' => $this->faker->sentence(),
            'input_kind' => 'account',
            'input_label' => 'Account',
            'delivery_summary' => $this->faker->sentence(),
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
