<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentAttempt> */
class PaymentAttemptFactory extends Factory
{
    protected $model = PaymentAttempt::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'provider' => 'line-pay',
            'reference' => PaymentAttempt::newReference(),
            'status' => PaymentStatus::Initiated,
            'amount' => 590,
            'currency' => 'TWD',
        ];
    }
}
