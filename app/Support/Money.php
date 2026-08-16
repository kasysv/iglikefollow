<?php

namespace App\Support;

use App\Exceptions\NonIntegerAmountException;

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
 * ⛔ A product of rate × quantity that is not a whole number of dollars is NOT
 * rounded. Rounding would quietly turn a misconfigured product into a payable
 * order and charge the customer something the catalogue never advertised; the
 * fault belongs to the price/step configuration, so it is raised there.
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
     * Total payable in whole NT dollars.
     *
     * Both operands are integers, so the product is exact. If it does not
     * divide into whole dollars the result is not payable in TWD at all, and
     * ⛔ it is rejected rather than rounded — see the class comment.
     *
     * @throws NonIntegerAmountException
     */
    public static function total(int $unitPriceMills, int $quantity): int
    {
        $mills = $unitPriceMills * $quantity;

        if ($mills % self::SCALE !== 0) {
            throw new NonIntegerAmountException($unitPriceMills, $quantity, $mills);
        }

        return intdiv($mills, self::SCALE);
    }

    /**
     * Would this rate and quantity produce a payable whole-dollar amount?
     *
     * Callers that must not throw — a catalogue validator checking every step
     * in a range, say — ask this first.
     */
    public static function divides(int $unitPriceMills, int $quantity): bool
    {
        return ($unitPriceMills * $quantity) % self::SCALE === 0;
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
