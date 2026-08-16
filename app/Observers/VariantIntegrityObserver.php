<?php

namespace App\Observers;

use App\Models\ServiceVariant;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Keeps a variant's quantity bounds internally consistent.
 *
 * The admin form validates the same rules, but they are enforced here as well
 * because an inconsistent variant is not merely untidy: a default quantity
 * outside the allowed range means the checkout page opens in a state the
 * server will reject, so the customer cannot buy at all.
 */
class VariantIntegrityObserver
{
    public function saving(ServiceVariant $variant): void
    {
        $min = (int) $variant->min_quantity;
        $max = (int) $variant->max_quantity;
        $step = (int) $variant->step_quantity;
        $default = (int) $variant->default_quantity;

        if ($step < 1) {
            throw ValidationException::withMessages([
                'step_quantity' => '數量間隔必須至少為 1。',
            ]);
        }

        if ($min > $max) {
            throw ValidationException::withMessages([
                'min_quantity' => '最少買多少不能大於最多買多少。',
            ]);
        }

        if ($default < $min || $default > $max) {
            throw ValidationException::withMessages([
                'default_quantity' => "預設數量必須介於 {$min} 至 {$max} 之間。",
            ]);
        }

        if ($default % $step !== 0) {
            throw ValidationException::withMessages([
                'default_quantity' => "預設數量必須是數量間隔（{$step}）的倍數。",
            ]);
        }

        $this->assertEveryQuantityIsPayable($variant);
    }

    /**
     * Every quantity the customer may pick must come to whole NT dollars.
     *
     * A rate of 0.59 with a step of 100 is fine — every step lands on a whole
     * dollar. A rate of 0.59 with a step of 1 is not: 0.59 × 101 is NT$59.59,
     * which cannot be charged. Catching it here means the fault is reported to
     * whoever set the price, instead of surfacing later as a customer who
     * cannot check out or an order for a rounded amount.
     */
    private function assertEveryQuantityIsPayable(ServiceVariant $variant): void
    {
        $offending = $variant->firstNonIntegerQuantity();

        if ($offending === null) {
            return;
        }

        $amount = Money::format($variant->unitPriceMills() * $offending);

        throw ValidationException::withMessages([
            'unit_price' => "單價 {$variant->unit_price} × 數量 {$offending} = {$amount} 元，"
                .'不是整數新台幣，客人無法付款。請調整單價，或把數量間隔改成能整除的數字。',
        ]);
    }
}
