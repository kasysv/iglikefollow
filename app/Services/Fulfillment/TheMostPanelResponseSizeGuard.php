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
     * ⛔ Measured limitation, not a promise. With the default cURL handler this
     * callback is invoked around transfer boundaries rather than continuously,
     * so against a chunked endless response it did not abort mid-stream in
     * local testing — the request ended on the 15s timeout instead. It is
     * therefore a backstop, not the primary defence: `assertContentLength()`
     * covers declared sizes, the timeout bounds the rest, and the post-read
     * `strlen` check in the probe stops anything oversized from being parsed.
     * The result document records this as NOT VERIFIED rather than claiming a
     * hard streaming cap that does not exist here.
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
     * ⛔ Type first, message second. `TheMostPanelResponseTooLarge` is matched
     * by class so a reworded wrapper cannot break detection; the string check
     * remains only for the header-stage abort, which throws before any typed
     * exception is available.
     */
    public static function isSizeAbort(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof TheMostPanelResponseTooLarge) {
                return true;
            }

            if (str_contains($current->getMessage(), self::REASON)) {
                return true;
            }
        }

        return false;
    }
}
