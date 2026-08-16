<?php

namespace App\Services\Payments;

/**
 * ECPay's CheckMacValue, per the AioCheckOut V5 specification.
 *
 * The algorithm is exact and every step matters:
 *
 *  1. drop CheckMacValue itself, sort the remaining fields by key, case-insensitively;
 *  2. wrap the query string with HashKey at the front and HashIV at the back;
 *  3. URL-encode, lowercase, then apply ECPay's .NET-compatibility replacements;
 *  4. SHA-256, uppercase.
 *
 * ⛔ Step 3 is where implementations usually go wrong. PHP's urlencode and
 * .NET's UrlEncode disagree on a handful of characters, and ECPay hashes what
 * .NET would produce. Get one substitution wrong and every value is rejected —
 * or worse, accepted here and rejected there, so payments silently stop working
 * for the subset of orders containing those characters.
 */
class EcpayCheckMac
{
    /**
     * @param  array<string, scalar>  $fields
     */
    public static function generate(array $fields, string $hashKey, string $hashIv): string
    {
        unset($fields['CheckMacValue']);

        // ⛔ 不分大小寫排序：ECPay 規格如此，用 ksort() 會得到不同順序。
        uksort($fields, fn ($a, $b) => strcasecmp((string) $a, (string) $b));

        $pairs = [];
        foreach ($fields as $key => $value) {
            $pairs[] = $key.'='.$value;
        }

        $raw = 'HashKey='.$hashKey.'&'.implode('&', $pairs).'&HashIV='.$hashIv;

        return strtoupper(hash('sha256', self::dotNetUrlEncode($raw)));
    }

    /**
     * Verify a value we received, without leaking timing information.
     *
     * @param  array<string, scalar>  $fields
     */
    public static function matches(array $fields, string $hashKey, string $hashIv, ?string $received): bool
    {
        if (! is_string($received) || $received === '') {
            return false;
        }

        // ⛔ hash_equals，不是 ===：字串比較會在第一個不同的位元組短路，
        // 洩漏「猜對了幾個字元」，讓簽章可以被逐位猜出來。
        return hash_equals(self::generate($fields, $hashKey, $hashIv), strtoupper($received));
    }

    /**
     * PHP urlencode, adjusted to match .NET's HttpUtility.UrlEncode.
     *
     * ⛔ These substitutions are the specification, not cosmetic tidying.
     */
    private static function dotNetUrlEncode(string $value): string
    {
        $encoded = strtolower(urlencode($value));

        return strtr($encoded, [
            '%2d' => '-',
            '%5f' => '_',
            '%2e' => '.',
            '%21' => '!',
            '%2a' => '*',
            '%28' => '(',
            '%29' => ')',
            '%20' => '+',
        ]);
    }
}
