<?php

namespace Database\Factories;

use App\Enums\IntegrationProvider;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invoice> */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'provider' => IntegrationProvider::EcpayInvoice->value,
            'status' => InvoiceStatus::Pending,
            'amount' => 590,
            'currency' => 'TWD',
        ];
    }

    public function pendingConfiguration(): static
    {
        return $this->state(fn () => ['status' => InvoiceStatus::PendingConfiguration]);
    }

    public function issued(): static
    {
        return $this->state(fn () => [
            'status' => InvoiceStatus::Issued,
            'invoice_number' => 'AB12345678',
            'random_code' => '1234',
            'issued_at' => now(),
        ]);
    }
}
