<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'reference' => Order::newReference(),
            'checkout_token' => (string) Str::uuid(),
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Initiated,
            'total_amount' => 590,
            'currency' => 'TWD',
            'customer_email' => 'buyer@example.com',
            'customer_phone' => null,
            'invoice_kind' => 'personal',
            'personal_invoice_mode' => 'email',
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'order_status' => OrderStatus::Paid,
            'payment_status' => PaymentStatus::Succeeded,
            'paid_at' => now(),
        ]);
    }
}
