<?php

namespace App\Support;

/**
 * Is this a real service name, or a decorative/category-header row the
 * provider mixes into its catalog listing?
 *
 * ⛔ Pure string → bool, no DB. The two mapping dropdowns
 * (`ConfigureSmmMappingAction`, `FulfillmentMappingForm`) and the submit-time
 * `AvailableProviderService` rule all call the same method here, so the
 * definition of "decorative" cannot drift between what a picker hides and
 * what the backend refuses to save.
 *
 * ⛔ Detects only rows that are *entirely* decoration — a long run of
 * separator characters, optionally wrapping a short title, with nothing else
 * in the string. A normal name that merely contains a hyphen or an em dash
 * (`Instagram 台灣頂級粉絲（男性） - 30天補粉`) must never match: the rule
 * is "the whole visible name is a divider", not "the name contains a dash".
 */
final class DecorativeProviderServiceName
{
    /**
     * Characters providers use to draw a divider line: various dash/hyphen
     * forms, underscore, equals, tilde, middle dot, box-drawing dashes, and
     * plain whitespace holding them together.
     */
    private const DECORATIVE_CHARS = '\-\x{2010}-\x{2015}_=~\x{00B7}\x{2500}-\x{257F}\s';

    public static function matches(?string $name): bool
    {
        if ($name === null) {
            return false;
        }

        $trimmed = trim($name);

        if ($trimmed === '') {
            return false;
        }

        // 案例一：整串只有裝飾字元(純橫線列)。
        if (preg_match('/\A['.self::DECORATIVE_CHARS.']+\z/u', $trimmed) === 1) {
            return true;
        }

        /*
         * 案例二：頭尾都是一長串裝飾字元(至少 3 個),中間夾一小段標題。
         *
         * ⛔ 至少 3 個字元才算「一長串」：一般名稱裡合法出現的單一連字號
         * 或全形破折號(如「30天補粉 - 加購」)不能被這個門檻誤判。
         */
        $pattern = '/\A['.self::DECORATIVE_CHARS.']{3,}\S.*\S['.self::DECORATIVE_CHARS.']{3,}\z/u';

        if (preg_match($pattern, $trimmed) === 1) {
            return true;
        }

        return false;
    }
}
