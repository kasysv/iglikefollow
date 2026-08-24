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
 *
 * The rules come in two kinds, and the difference matters:
 *
 *  - *structural* rules describe a coherent record and apply in every state.
 *    A step of zero or a default outside the range is meaningless whether the
 *    variant is published or not.
 *  - *sellability* rules describe something a customer can actually buy, and
 *    ⛔ apply only when the variant will be published after this save.
 *
 * Applying sellability rules to drafts would trap broken data: a variant that
 * was published before these rules existed could not be taken down, because
 * the save that unpublishes it would be rejected for the very defect being
 * removed from sale. Unpublishing must always be possible — it is the fix.
 */
class VariantIntegrityObserver
{
    public function saving(ServiceVariant $variant): void
    {
        $this->assertStructureIsCoherent($variant);

        // ⛔ 只有「存檔後會是已發布」才需要通過可售性檢查。
        if ($variant->status === 'published') {
            $this->assertPriceIsSellable($variant);
            $this->assertRangeContainsAPurchasableQuantity($variant);
            $this->assertEveryQuantityIsPayable($variant);
        }
    }

    /** 結構規則：任何狀態都必須成立，草稿也不例外。 */
    private function assertStructureIsCoherent(ServiceVariant $variant): void
    {
        $min = (int) $variant->min_quantity;
        $max = (int) $variant->max_quantity;
        $default = (int) $variant->default_quantity;

        /*
         * ⛔ M3A:不再驗 `step_quantity`。它是 legacy 欄位,已不影響任何
         * 購買規則,因此把它做成儲存條件只會擋下本來完全正常的商品。
         */

        /*
         * ⛔ R1:0 永遠不是可購數量(checkout 根層拒絕 quantity <= 0),
         * 所以 min 0 是結構矛盾——UI 會顯示一個不存在的「最低可購 0」。
         * 草稿與已發布一律適用。
         */
        if ($min < 1) {
            throw ValidationException::withMessages([
                'min_quantity' => '最少買多少必須至少為 1。',
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

        // ⛔ M3A:預設數量不再需要是任何數字的倍數,只要落在範圍內。
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
     * ⛔ M3A: with every integer purchasable this can now only fail when the
     * range itself is empty. The check stays because `min <= max` is enforced
     * on the raw columns while this asks the model the same question the
     * storefront will — one answer, not two.
     */
    private function assertRangeContainsAPurchasableQuantity(ServiceVariant $variant): void
    {
        if ($variant->firstPurchasableQuantity() !== null) {
            return;
        }

        $min = (int) $variant->min_quantity;
        $max = (int) $variant->max_quantity;

        throw ValidationException::withMessages([
            'min_quantity' => "在 {$min} 到 {$max} 之間沒有任何可購買的數量，請調整最少／最多買多少。",
        ]);
    }

    /**
     * Every quantity the customer may pick must actually be chargeable.
     *
     * ⛔ M3A: this no longer means "lands on a whole dollar" — fractional
     * totals are rounded half-up now, so NT$0.59 × 101 = NT$59.59 → NT$60 is
     * fine. What is still a configuration fault is a range whose smallest
     * order rounds to less than NT$1 (a free order), or whose largest
     * overflows. Reporting it here means the price setter hears about it,
     * rather than a customer discovering it at checkout.
     */
    private function assertEveryQuantityIsPayable(ServiceVariant $variant): void
    {
        $offending = $variant->firstUnpayableQuantity();

        if ($offending === null) {
            return;
        }

        $amount = Money::format($variant->pendingUnitPriceMills() * $offending);

        throw ValidationException::withMessages([
            'unit_price' => "單價 {$variant->unit_price} × 數量 {$offending} = {$amount} 元，"
                .'四捨五入後不足 1 元或超出可計算範圍，客人無法付款。'
                .'請調高單價，或提高最少購買數量。',
        ]);
    }
}
