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

        $this->assertPriceIsSellable($variant);
        $this->assertRangeContainsAPurchasableQuantity($variant);
        $this->assertEveryQuantityIsPayable($variant);
    }

    /**
     * A sellable service costs more than nothing.
     *
     * ⛔ The admin form's minValue(0) is only form validation and allows zero
     * anyway; this is the server-side rule. A zero rate would create orders for
     * NT$0 and a negative one would owe the customer money — neither is a
     * discount, both are broken configuration.
     */
    private function assertPriceIsSellable(ServiceVariant $variant): void
    {
        $rate = $variant->pendingUnitPriceMills();

        if ($rate === null || $rate > 0) {
            return; // 沒有價格或格式錯誤由欄位驗證負責。
        }

        throw ValidationException::withMessages([
            'unit_price' => '單價必須大於 0。免費或負價的服務項目不可販售。',
        ]);
    }

    /**
     * The allowed range must contain at least one quantity someone can buy.
     *
     * Customers may only buy multiples of the step, so min 101 with step 100
     * and max 199 offers nothing at all: 100 is below the minimum and 200 is
     * above the maximum. ⛔ Saving that would publish a variant with no valid
     * purchase, which fails at checkout instead of at configuration time.
     */
    private function assertRangeContainsAPurchasableQuantity(ServiceVariant $variant): void
    {
        if ($variant->firstPurchasableQuantity() !== null) {
            return;
        }

        $min = (int) $variant->min_quantity;
        $max = (int) $variant->max_quantity;
        $step = (int) $variant->step_quantity;

        throw ValidationException::withMessages([
            'step_quantity' => "在 {$min} 到 {$max} 之間沒有任何 {$step} 的倍數，客人買不到任何數量。"
                .'請調整最少／最多買多少，或改小數量間隔。',
        ]);
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

        $amount = Money::format($variant->pendingUnitPriceMills() * $offending);

        throw ValidationException::withMessages([
            'unit_price' => "單價 {$variant->unit_price} × 數量 {$offending} = {$amount} 元，"
                .'不是整數新台幣，客人無法付款。請調整單價，或把數量間隔改成能整除的數字。',
        ]);
    }
}
