<?php

namespace App\Support;

/**
 * Exact money arithmetic for catalogue prices and order amounts.
 *
 * Unit prices are decimal(12,4): a variant may legitimately cost NT$0.1234.
 * Multiplying that by a quantity in binary floating point loses precision at
 * exactly the scale that matters — 0.1 + 0.2 is famously not 0.3 — so every
 * calculation here works on integers.
 *
 * The internal unit is a "mill": one ten-thousandth of a NT dollar, which is
 * the full precision the price column can express. Totals are rounded to whole
 * NT dollars once, at the end, using half-up — the convention a customer
 * expects when a price lands on half a dollar.
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
     * quantity is an integer and unitPriceMills is an integer, so the product
     * is exact; only the final division rounds, and it rounds half up.
     */
    public static function total(int $unitPriceMills, int $quantity): int
    {
        $mills = $unitPriceMills * $quantity;

        return intdiv($mills + intdiv(self::SCALE, 2), self::SCALE);
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
