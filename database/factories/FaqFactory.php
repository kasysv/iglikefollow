<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class FaqFactory extends Factory
{
    public function definition(): array
    {
        return [
            'scope' => 'global',
            'question' => $this->faker->sentence().'?',
            'answer' => $this->faker->paragraph(),
            'status' => 'draft',
            'sort_order' => 0,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => 'published']);
    }
}
