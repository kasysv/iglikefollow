<?php

namespace App\Services\Fulfillment;

/**
 * What the transfer itself reported, recorded locally.
 *
 * ⛔ Holds a number, never a message. cURL's error string and the provider's
 * body are both text we do not want, and an errno is enough to tell "the
 * transfer was stopped for exceeding the size limit" from "something else went
 * wrong". Classifying on message text would mean storing that text long enough
 * to read it.
 *
 * One instance per request, filled from Guzzle's `on_stats` callback.
 */
class TheMostPanelTransferState
{
    /** libcurl `CURLE_FILESIZE_EXCEEDED`；⛔ 只有這個碼算「太大」。 */
    public const FILESIZE_EXCEEDED = 63;

    private ?int $errorCode = null;

    /**
     * ⛔ Only accepts an integer.
     *
     * Guzzle hands the handler's error data through `on_stats`; anything that
     * is not a plain int is discarded rather than coerced, because a coerced
     * value could turn an arbitrary string into a number that happens to mean
     * something.
     */
    public function record(mixed $errorCode): void
    {
        if (is_int($errorCode)) {
            $this->errorCode = $errorCode;
        }
    }

    public function exceededMaxFileSize(): bool
    {
        return $this->errorCode === self::FILESIZE_EXCEEDED;
    }

    public function errorCode(): ?int
    {
        return $this->errorCode;
    }
}
