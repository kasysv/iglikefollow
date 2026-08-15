<?php

namespace App\Observers;

use App\Models\ServiceVariant;
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
    }
}
