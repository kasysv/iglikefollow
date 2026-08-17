<?php

namespace App\Services\Fulfillment;

use RuntimeException;

/**
 * Thrown the moment a response would exceed the size cap.
 *
 * ⛔ A dedicated class rather than a string marker in a generic exception.
 * Guzzle and Laravel each wrap whatever a stream or callback throws, so by the
 * time the probe sees it the original is several layers down. Matching on a
 * type survives that; matching on message text is one reworded wrapper away
 * from silently reclassifying "too large" as a generic transport failure.
 *
 * ⛔ Carries no provider text. The bytes that triggered it are exactly the
 * bytes we refuse to keep.
 */
class TheMostPanelResponseTooLarge extends RuntimeException
{
    public function __construct(public readonly int $limitBytes)
    {
        // ⛔ 訊息只描述我們自己的限制，不含任何回應內容。
        parent::__construct("themostpanel response exceeded {$limitBytes} bytes");
    }
}
