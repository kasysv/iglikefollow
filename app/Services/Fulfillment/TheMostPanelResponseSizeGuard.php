<?php

namespace App\Services\Fulfillment;

use RuntimeException;

/**
 * Stop an oversized response during transfer, not after it.
 *
 * ⛔ Checking `strlen()` on a body Laravel has already buffered prevents us
 * *parsing* something huge; it does not prevent it reaching memory. A provider
 * that returns a multi-gigabyte body would exhaust the process before our check
 * ever ran, which is the failure the limit exists to avoid.
 *
 * So the cap is applied twice, at two different moments:
 *
 *  - `assertContentLength()` runs the instant response headers arrive. When the
 *    provider declares a length over the cap, the download is abandoned before
 *    the body is read at all.
 *  - `assertProgress()` runs as bytes arrive, for chunked or unknown-length
 *    responses where a declared length either does not exist or cannot be
 *    trusted.
 *
 * ⛔ Both throw rather than returning false. These run inside Guzzle callbacks,
 * where a return value has nowhere to go — throwing is what actually aborts the
 * transfer.
 *
 * ⛔ 2 MiB is *our* conservative choice, not a provider guarantee. Nobody here
 * has seen a real `services` response, so any number is a guess; this one is
 * chosen to be safely abortable rather than generous.
 */
class TheMostPanelResponseSizeGuard
{
    public const MAX_BODY_BYTES = 2_097_152;

    /** ⛔ 這個訊息不會外流到觀察結果；只是讓中止的原因在程式內可辨識。 */
    public const REASON = 'themostpanel_body_too_large';

    public const ENCODING_REASON = 'themostpanel_unsupported_encoding';

    /**
     * Refuse a compressed response before reading it.
     *
     * ⛔ Every size limit here counts bytes on the wire. A gzip body that
     * expands to gigabytes passes all of them: cURL sees a small transfer, and
     * the huge version only exists after decoding — which is the thing that
     * would then be parsed. We ask for `identity` and refuse anything else
     * rather than trusting a declared ratio.
     *
     * An absent header means identity, which is the normal case.
     *
     * @param  array<string, array<int, string>>  $headers
     */
    public static function assertIdentityEncoding(array $headers): void
    {
        foreach ($headers as $name => $values) {
            if (strtolower((string) $name) !== 'content-encoding') {
                continue;
            }

            foreach ((is_array($values) ? $values : [$values]) as $value) {
                $value = strtolower(trim((string) $value));

                // 空字串或 identity 都代表沒有壓縮。
                if ($value === '' || $value === 'identity') {
                    continue;
                }

                throw new RuntimeException(self::ENCODING_REASON);
            }
        }
    }

    /** 這個例外是不是我們因為壓縮編碼而拒絕的？ */
    public static function isEncodingRefusal(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (str_contains($current->getMessage(), self::ENCODING_REASON)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Header-time check: refuse before a single body byte is read.
     *
     * @param  array<string, array<int, string>>  $headers
     */
    public static function assertContentLength(array $headers): void
    {
        $declared = self::contentLength($headers);

        // 未宣告長度（chunked）時交給 assertProgress() 處理。
        if ($declared === null) {
            return;
        }

        if ($declared > self::MAX_BODY_BYTES) {
            throw new RuntimeException(self::REASON);
        }
    }

    /**
     * Download-time check, for responses whose size we cannot know in advance.
     *
     * ⛔ Only `$downloaded` matters. Guzzle also reports a total, but a provider
     * controls that number too — trusting it would let a declared-small,
     * actually-huge response through the one check meant to catch exactly that.
     *
     * ⛔ Backstop only. R1(curl 7.68)之後,傳輸中的主要防線是 bounded sink
     * 的 short write:超限的 chunk 拒收、回傳量小於收到量,libcurl 以 write
     * error 立即中止——這已由 localhost fixture 的真實 cURL 整合測試證明,
     * 在完整 body 下載完成前就停下,任何 libcurl 版本都支援。這個 progress
     * callback 與新版 libcurl 的 `CURLOPT_MAXFILESIZE_LARGE` 都只是額外的
     * 保險層,何時觸發由 handler 決定,不由我們決定。
     */
    public static function assertProgress(int $downloaded): void
    {
        if ($downloaded > self::MAX_BODY_BYTES) {
            throw new RuntimeException(self::REASON);
        }
    }

    /**
     * ⛔ Header lookup is case-insensitive and refuses ambiguity.
     *
     * A response carrying two different `Content-Length` values is malformed;
     * picking one would be guessing at which the sender meant.
     *
     * @param  array<string, array<int, string>>  $headers
     */
    private static function contentLength(array $headers): ?int
    {
        foreach ($headers as $name => $values) {
            if (strtolower((string) $name) !== 'content-length') {
                continue;
            }

            $values = is_array($values) ? $values : [$values];

            if (count($values) !== 1) {
                // ⛔ 互相矛盾的長度宣告：當作未知，交給下載中的檢查。
                return null;
            }

            $value = trim((string) $values[0]);

            return ctype_digit($value) ? (int) $value : null;
        }

        return null;
    }

    /**
     * Was this our own size abort, anywhere in the chain?
     *
     * ⛔ Must walk `getPrevious()`. Guzzle catches whatever a stream or an
     * `on_headers` callback throws and re-throws its own message, which Laravel
     * then wraps again as a `ConnectionException`. Ours survives only as the
     * innermost cause — checking just the outer exception would report a size
     * abort as a generic transport failure, and the two mean different things
     * to whoever reads the result.
     *
     * ⛔ R1(curl 7.68):sink 已改用 short write 中止,不再拋出型別化例外;
     * 「本站主動的 size abort」的第一手事實改由 sink 的 `overflowed()` state
     * 回答,caller 先問它。這裡只剩 header 階段(宣告長度超限)的拒絕——
     * 那是在 `on_headers` callback 裡以固定 REASON 字串拋出的,逐層
     * `getPrevious()` 尋找;⛔ 比對的是我們自己的固定 token,不是 provider
     * 或 cURL 的錯誤文字。
     */
    public static function isSizeAbort(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (str_contains($current->getMessage(), self::REASON)) {
                return true;
            }
        }

        return false;
    }
}
