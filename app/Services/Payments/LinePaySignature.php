<?php

namespace App\Services\Payments;

/**
 * LINE Pay Online API v4 request signing.
 *
 * The signature covers channel secret + request URI + body + nonce, HMAC-SHA256,
 * base64. Including the URI is what stops a signed body for one endpoint being
 * replayed against another, and the nonce is what stops the same signed request
 * being replayed at all — so ⛔ a fresh nonce per request is required, not
 * merely advisable.
 */
class LinePaySignature
{
    /**
     * @param  array<string, mixed>  $body
     * @return array{'X-LINE-ChannelId': string, 'X-LINE-Authorization-Nonce': string, 'X-LINE-Authorization': string}
     */
    public static function headers(
        string $channelId,
        string $channelSecret,
        string $requestUri,
        array $body,
        string $nonce,
    ): array {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'X-LINE-ChannelId' => $channelId,
            'X-LINE-Authorization-Nonce' => $nonce,
            'X-LINE-Authorization' => self::sign($channelSecret, $requestUri, (string) $payload, $nonce),
        ];
    }

    /** ⛔ 簽章內容包含 URI：否則同一份簽章可以被轉送到別的端點。 */
    public static function sign(string $channelSecret, string $requestUri, string $body, string $nonce): string
    {
        return base64_encode(
            hash_hmac('sha256', $channelSecret.$requestUri.$body.$nonce, $channelSecret, true)
        );
    }

    /** 每次請求都要新的；⛔ 重複使用等於允許重放。 */
    public static function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }
}
