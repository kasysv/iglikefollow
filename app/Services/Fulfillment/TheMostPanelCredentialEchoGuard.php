<?php

namespace App\Services\Fulfillment;

use stdClass;

/**
 * Does a response body carry the key we just sent, in any form?
 *
 * ⛔ Extracted from the RO-A probe verbatim (B1-R2 accepted logic) so the
 * dispatch adapter shares the exact same guard. Two passes on purpose: the
 * raw scan catches the key anywhere at all, including invalid JSON; but JSON
 * may hide it behind `\uXXXX` escapes the raw scan cannot see, so the decoded
 * structure is walked too — every object key and every string value,
 * recursively.
 */
class TheMostPanelCredentialEchoGuard
{
    public static function echoes(string $body, string $key): bool
    {
        if (str_contains($body, $key)) {
            return true;
        }

        /*
         * ⛔ 不用 assoc decode。assoc 模式會把純數字 object property name
         * canonical 化成 integer array key——一把 Unicode-escaped 的純數字
         * API Key 就這樣逃過了字串檢查（GPT R1 反例）。stdClass 的 property
         * name 永遠是字串，foreach 也原樣保留，型別差異不會被抹掉。
         */
        return self::structureContains(json_decode($body), $key);
    }

    /** 遞迴檢查 decode 後的每個 object property name 與 string value。 */
    private static function structureContains(mixed $value, string $key): bool
    {
        if (is_string($value)) {
            return str_contains($value, $key);
        }

        if ($value instanceof stdClass) {
            foreach ($value as $name => $item) {
                // ⛔ (string) 是雙保險：object property name 本來就是字串。
                if (str_contains((string) $name, $key)) {
                    return true;
                }

                if (self::structureContains($item, $key)) {
                    return true;
                }
            }

            return false;
        }

        if (is_array($value)) {
            // JSON list：index 不可能載 key，只遞迴 value。
            foreach ($value as $item) {
                if (self::structureContains($item, $key)) {
                    return true;
                }
            }
        }

        return false;
    }
}
