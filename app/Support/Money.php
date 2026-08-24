<?php

namespace App\Support;

use App\Exceptions\UnsellablePriceException;

/**
 * Exact money arithmetic for catalogue prices and order amounts.
 *
 * Two different quantities are involved here and conflating them is what this
 * class exists to prevent:
 *
 *  - a *unit rate*, stored as decimal(12,4), which may legitimately be
 *    NT$0.59 or even NT$0.1234 per follower. It is a rate, not a price anyone
 *    is ever charged.
 *  - a *payable amount*, which is always a whole number of NT dollars. TWD has
 *    no sub-dollar denomination and no payment provider accepts one.
 *
 * Multiplying a rate by a quantity in binary floating point loses precision at
 * exactly the scale that matters — 0.1 + 0.2 is famously not 0.3 — so every
 * calculation here works on integers. The internal unit is a "mill": one
 * ten-thousandth of a NT dollar, the full precision the price column can hold.
 *
 * M3A: a product that is not a whole number of dollars is now rounded half-up
 * to whole TWD rather than refused. Owner decided customers may buy any integer
 * quantity, which makes fractional totals ordinary rather than a symptom of
 * misconfiguration — NT$0.59 × 101 is NT$59.59, and the customer pays NT$60.
 *
 * ⛔ The rounding is integer arithmetic (`remainder * 2 >= SCALE`), never
 * `round()` or a formatted string: `round()` takes a float, and a float is
 * exactly what cannot be trusted at the .5 boundary this rule turns on.
 * ⛔ Half-up, not banker's rounding — a total ending in .5 always goes up.
 * ⛔ Rounding is not permission to charge nothing: a total that rounds down to
 * zero is still refused, and there is deliberately no hidden NT$1 minimum.
 */
class Money
{
    /** 每元的最小單位數；unit_price 為 decimal(12,4)，故為 10000。 */
    public const SCALE = 10_000;

    /**
     * A decimal price string to whole mills, without ever touching a float.
     *
     * "0.1234" becomes 1234. A value with more than four decimals is rejected
     * rather than silently truncated: that would mean the column and this
     * helper disagree, which is a bug worth surfacing.
     */
    public static function toMills(string $price): int
    {
        $price = trim($price);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $price)) {
            throw new \InvalidArgumentException("無法解析的價格：{$price}");
        }

        $negative = str_starts_with($price, '-');
        $price = ltrim($price, '-');

        [$whole, $fraction] = array_pad(explode('.', $price, 2), 2, '');

        if (strlen($fraction) > 4) {
            throw new \InvalidArgumentException("價格精度超過 4 位小數：{$price}");
        }

        $mills = (int) $whole * self::SCALE + (int) str_pad($fraction, 4, '0');

        return $negative ? -$mills : $mills;
    }

    /**
     * Total payable in whole NT dollars, rounded half-up.
     *
     * Both operands are integers, so the product is exact; only the final
     * conversion to dollars rounds. ⛔ A total that rounds down to zero is
     * refused rather than quietly becoming a free order or being nudged up to
     * NT$1 — both would charge an amount the catalogue never advertised.
     *
     * @throws UnsellablePriceException
     */
    public static function total(int $unitPriceMills, int $quantity): int
    {
        // ⛔ 免費或負價不是折扣，是收不到錢或倒貼；金額欄位不得出現 0 或負數。
        if ($unitPriceMills <= 0) {
            throw new UnsellablePriceException(
                "單價必須大於 0，收到 {$unitPriceMills} 毫。免費或負價的服務項目不可販售。"
            );
        }

        if ($quantity <= 0) {
            throw new UnsellablePriceException("數量必須大於 0，收到 {$quantity}。");
        }

        $mills = self::multiply($unitPriceMills, $quantity);
        $dollars = self::roundMillsToDollars($mills);

        if ($dollars < 1) {
            // ⛔ 四捨五入後不足 1 元:拒絕,不建 NT$0 訂單、也不暗自墊高到 1。
            throw new UnsellablePriceException(
                "四捨五入後的付款金額為 {$dollars} 元，低於可收款的最小金額。請調整單價或最低數量。"
            );
        }

        return $dollars;
    }

    /**
     * Mills → whole dollars, half-up, using only integer arithmetic.
     *
     * ⛔ `remainder * 2 >= SCALE` rather than `remainder >= SCALE / 2`: SCALE is
     * even today, but comparing doubled remainders keeps the rule exact even if
     * it ever stops being, and never introduces a division that could truncate.
     * ⛔ Never `round()`: it takes a float, and the .5 boundary is precisely
     * where a float stops being trustworthy.
     *
     * Operands are positive everywhere this is reached (total() rejects
     * non-positive input first), so no negative-half-up case arises.
     */
    private static function roundMillsToDollars(int $mills): int
    {
        $whole = intdiv($mills, self::SCALE);
        $remainder = $mills % self::SCALE;

        return $remainder * 2 >= self::SCALE ? $whole + 1 : $whole;
    }

    /**
     * Does this rate and quantity land exactly on whole dollars?
     *
     * ⛔ M3A: this no longer decides whether something may be bought — a
     * fractional total is now rounded, not refused. It is kept only for callers
     * that genuinely want to know about exactness (diagnostics and the
     * catalogue's own reporting), so that "is it exact" and "may it be sold"
     * stay two separate questions. `isPayable()` answers the second.
     */
    public static function divides(int $unitPriceMills, int $quantity): bool
    {
        if ($unitPriceMills <= 0 || $quantity <= 0) {
            return false;
        }

        return self::multiply($unitPriceMills, $quantity) % self::SCALE === 0;
    }

    /**
     * Can this rate and quantity actually be charged?
     *
     * ⛔ M3A: "payable" now means the rounded total is at least NT$1 and does
     * not overflow — exactness is no longer part of it. Callers that must not
     * throw (the catalogue validator, checkout) ask this.
     */
    public static function isPayable(int $unitPriceMills, int $quantity): bool
    {
        try {
            return self::total($unitPriceMills, $quantity) > 0;
        } catch (UnsellablePriceException) {
            return false;
        }
    }

    /**
     * rate × quantity, refusing to overflow into a float.
     *
     * PHP silently promotes an integer overflow to a float, which would defeat
     * the entire point of this class: the result would come back as something
     * like 9.2E+18 and every exactness guarantee below it would be void. Both
     * operands are known positive here, so a product that has stopped being an
     * int, or that does not divide back, has overflowed.
     */
    private static function multiply(int $a, int $b): int
    {
        $product = $a * $b;

        if (! is_int($product) || intdiv($product, $b) !== $a) {
            throw new UnsellablePriceException(
                '金額超出可計算範圍，請調低單價或數量上限。'
            );
        }

        return $product;
    }

    /** Mills back to a display string, e.g. 1234 → "0.1234". */
    public static function format(int $mills): string
    {
        $sign = $mills < 0 ? '-' : '';
        $mills = abs($mills);

        $whole = intdiv($mills, self::SCALE);
        $fraction = rtrim(str_pad((string) ($mills % self::SCALE), 4, '0', STR_PAD_LEFT), '0');

        return $sign.$whole.($fraction === '' ? '' : '.'.$fraction);
    }
}
