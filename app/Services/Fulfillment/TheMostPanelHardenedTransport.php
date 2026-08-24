<?php

namespace App\Services\Fulfillment;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The one hardened request chain every TheMostPanel call shares.
 *
 * ⛔ Extracted from the RO-A probe verbatim so the dispatch adapter cannot
 * grow a second, weaker copy: exactly one POST, no retry, no redirect, TLS
 * peer + host verification on, identity encoding with decoding disabled,
 * native 2 MiB transport cap plus the bounded sink, errno-only stats.
 * Copying this chain anywhere else is forbidden — two copies of these
 * options will drift, and the drifted one still carries the API key.
 */
class TheMostPanelHardenedTransport
{
    public const CONNECT_TIMEOUT = 5;

    public const TOTAL_TIMEOUT = 15;

    /** @param array<string, mixed> $payload */
    public function postExactlyOnce(
        string $endpoint,
        array $payload,
        TheMostPanelBoundedResponseStream $sink,
        TheMostPanelTransferState $transfer,
    ): Response {
        return Http::asForm()
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TOTAL_TIMEOUT)
            // ⛔ 不自動重試：rate limit 未知。
            ->withoutRedirecting()
            /*
             * ⛔ 明確要求不壓縮，並關閉自動解壓。
             *
             * 否則「線路上 2 KB、解壓後 2 GB」就能繞過整套大小限制：cURL
             * 的上限看的是 wire bytes，而我們解析的是解壓後的內容。
             */
            ->withHeaders(['Accept-Encoding' => 'identity'])
            ->withOptions([
                // ⛔ TLS 驗證維持開啟；verify=false 永久禁止。
                'verify' => true,
                'decode_content' => false,
                /*
                 * ⛔ R1(curl 7.68):額外的保險層,不再是主要防線。
                 *
                 * 傳輸中的主要上限現在由 bounded sink 的 short write 執行
                 * (拒收超限 chunk → libcurl write error 中止,任何版本都
                 * 支援)。這個 max-filesize 只在 8.4.0 起套用到進行中的
                 * 傳輸、且僅對已宣告長度可靠,留著是多一層,⛔ 不作能力閘。
                 */
                'curl' => [
                    CURLOPT_MAXFILESIZE_LARGE => TheMostPanelResponseSizeGuard::MAX_BODY_BYTES,
                ],
                // handler 無關的第二層：限制實際保存的位元組數。
                'sink' => $sink,
                // 已宣告長度就超限、或宣告了壓縮編碼時，連第一個 byte 都不收。
                'on_headers' => function ($response) {
                    TheMostPanelResponseSizeGuard::assertContentLength($response->getHeaders());
                    TheMostPanelResponseSizeGuard::assertIdentityEncoding($response->getHeaders());
                },
                // ⛔ 只取 errno，不取任何訊息。
                'on_stats' => function ($stats) use ($transfer) {
                    $transfer->record($stats->getHandlerErrorData());
                },
                /*
                 * 額外一層，⛔ 但不是 hard cap：它何時觸發由 handler 決定，
                 * 不由我們決定。
                 */
                'progress' => function ($downloadTotal, $downloaded) {
                    TheMostPanelResponseSizeGuard::assertProgress((int) $downloaded);
                },
            ])
            ->post($endpoint, $payload);
    }
}
