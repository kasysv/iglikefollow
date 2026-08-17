<?php

namespace App\Services\Fulfillment;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * A response sink that refuses to grow past the cap.
 *
 * ⛔ This is the actual hard cap. The earlier attempt used Guzzle's `progress`
 * callback, which with the cURL handler fires around transfer boundaries rather
 * than continuously — against a chunked endless response it never fired in time
 * and the request simply ran to the 15s timeout. A cap that only stops things
 * once they have already arrived is not a cap.
 *
 * The sink sits where every byte must pass: cURL calls `write()` on it as data
 * arrives, so refusing there stops the transfer at the point of the offending
 * chunk, before it is stored.
 *
 * ⛔ One instance per request, never shared. A counter reused across requests
 * would let an earlier small response consume the budget of a later one, and
 * two probes running together would corrupt each other's totals.
 */
class TheMostPanelBoundedResponseStream implements StreamInterface
{
    use StreamDecoratorTrait;

    /**
     * ⛔ 明確宣告，不靠 trait 動態建立。
     *
     * PHP 8.2 起動態屬性已 deprecated，留著會在每次建立 sink 時噴警告——
     * 而這條路徑的輸出必須乾淨，任何雜訊都可能蓋掉真正的安全訊息。
     */
    private StreamInterface $stream;

    private int $written = 0;

    public function __construct(
        private readonly int $limitBytes,
        ?StreamInterface $stream = null,
    ) {
        // ⛔ 預設 php://temp：小回應留在記憶體，較大的自動落到暫存檔。
        $this->stream = $stream ?? Utils::streamFor(fopen('php://temp', 'w+'));
    }

    /**
     * ⛔ Check before writing, not after.
     *
     * Writing the chunk and then noticing the total is too large means the
     * bytes are already held — which is the thing the limit exists to prevent.
     */
    public function write($string): int
    {
        $incoming = strlen((string) $string);

        if ($this->written + $incoming > $this->limitBytes) {
            throw new TheMostPanelResponseTooLarge($this->limitBytes);
        }

        $bytes = $this->stream->write($string);

        // 以實際寫入量累計；⛔ 不用預期值，兩者不一定相同。
        $this->written += $bytes;

        return $bytes;
    }

    /** 目前已接收的位元組數；只給測試與診斷使用。 */
    public function bytesWritten(): int
    {
        return $this->written;
    }

    public function limitBytes(): int
    {
        return $this->limitBytes;
    }
}
