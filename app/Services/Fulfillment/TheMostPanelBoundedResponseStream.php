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

    /** 是否曾因超限而拒收;⛔ caller 用這個 state 辨認本站主動的 size abort。 */
    private bool $overflowed = false;

    /**
     * ⛔ Check before writing, not after — and abort by SHORT WRITE, not throw.
     *
     * Writing the chunk and then noticing the total is too large means the
     * bytes are already held — which is the thing the limit exists to prevent.
     * 跨過上限的那個 chunk **一個 byte 都不寫**。
     *
     * ⛔ R1(curl 7.68):改用 short write 中止,不再 throw。Guzzle 的
     * managed `CURLOPT_WRITEFUNCTION` 會把 `sink->write()` 的回傳值交還給
     * libcurl;回傳量小於收到量,libcurl 就以 write error(CURLE 23)中止
     * 傳輸——這在 libcurl 7.68 已支援,不需要 8.4 的
     * `CURLOPT_MAXFILESIZE_LARGE` 才能在傳輸途中停下。⛔ 也不自行再塞一個
     * raw `CURLOPT_WRITEFUNCTION`:那會與 Guzzle 的 sink 衝突。
     *
     * ⛔ 溢位一旦發生就鎖死:之後的每個 chunk 一律回 0,絕不恢復寫入——
     * 「拒收了一段之後又繼續存」會存下一個中間有洞、看起來卻完整的 body。
     */
    public function write($string): int
    {
        if ($this->overflowed) {
            return 0;
        }

        $incoming = strlen((string) $string);

        if ($this->written + $incoming > $this->limitBytes) {
            $this->overflowed = true;

            // ⛔ short write:告訴 libcurl「我拒收」,讓它立即中止傳輸。
            return 0;
        }

        $bytes = $this->stream->write($string);

        // 以實際寫入量累計；⛔ 不用預期值，兩者不一定相同。
        $this->written += $bytes;

        return $bytes;
    }

    /**
     * 這個 sink 是否因超限而主動中止過。
     *
     * ⛔ caller 以這個 state(而不是 provider／cURL 的錯誤文字)辨認
     * 「這是本站自己的 size abort」——錯誤文字是我們刻意不保存的東西。
     */
    public function overflowed(): bool
    {
        return $this->overflowed;
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
