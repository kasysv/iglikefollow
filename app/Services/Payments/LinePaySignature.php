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
     * Headers for a body that has **already been serialised**.
     *
     * ⭐ 這個方法收的是 raw JSON 字串，不是 array——而那正是本輪修正的核心。
     *
     * ⛔ 舊版收 array、在這裡 `json_encode()` 一次算出簽章，呼叫端卻把**同一個
     * array** 交給 Laravel／Guzzle 的 `asJson()` 再編碼一次。兩次編碼的旗標不
     * 同：簽章用 `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE`，而 Guzzle 用
     * PHP 預設值，於是 `https://…` 變成 `https:\/\/…`、中文變成 `行…`。
     * 簽章涵蓋的 bytes 與實際上線的 bytes 因此不同，LINE Pay 一律拒絕。
     *
     * 只要 body 含 redirect URL（一定含 `https://`）就必然發生，這也就是 Owner
     * 在 staging 連付款頁都看不到就被擋下的原因。
     *
     * ⛔ 型別就是保證：這裡拿不到 array，也就無法在此重新編碼一次。呼叫端只能
     * 先序列化、再把同一份 bytes 同時用於簽章與送出。
     *
     * @return array{'X-LINE-ChannelId': string, 'X-LINE-Authorization-Nonce': string, 'X-LINE-Authorization': string}
     */
    public static function headers(
        string $channelId,
        string $channelSecret,
        string $requestUri,
        string $rawBody,
        string $nonce,
    ): array {
        return [
            'X-LINE-ChannelId' => $channelId,
            'X-LINE-Authorization-Nonce' => $nonce,
            'X-LINE-Authorization' => self::sign($channelSecret, $requestUri, $rawBody, $nonce),
        ];
    }

    /**
     * Serialise a body exactly once, for both signing and sending.
     *
     * ⛔ 回傳 null 代表編碼失敗（例如無效 UTF-8）。呼叫端必須 fail closed，
     * ⛔ 不得以空字串或部分 body 繼續——那會送出一份與簽章不符、內容也不完整
     * 的請求。
     *
     * 旗標的選擇本身不重要，重要的是**只編碼這一次**：簽章與 wire 用的是同一
     * 個回傳值。這裡維持既有的 `UNESCAPED_SLASHES|UNESCAPED_UNICODE`，因為它
     * 產生的 bytes 較短且可讀，且官方對 body 的編碼形式沒有額外要求。
     *
     * @param  array<string, mixed>  $body
     */
    public static function encodeBody(array $body): ?string
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : $json;
    }

    /** ⛔ 簽章內容包含 URI：否則同一份簽章可以被轉送到別的端點。 */
    public static function sign(string $channelSecret, string $requestUri, string $body, string $nonce): string
    {
        return base64_encode(
            hash_hmac('sha256', $channelSecret.$requestUri.$body.$nonce, $channelSecret, true)
        );
    }

    /**
     * A fresh RFC 4122 version 4 UUID.
     *
     * ⭐ 官方規定 nonce 必須是 UUID v1／v4 或 timestamp。舊版回傳
     * `bin2hex(random_bytes(16))`——32 個十六進位字元、沒有連字號、沒有 version
     * 與 variant 位元，⛔ 不是任何一種官方接受的格式。
     *
     * 隨機性本身沒問題（同樣是 128 bits CSPRNG），問題在於**格式**：對方按規格
     * 驗 header，格式不符就整個請求被拒。
     *
     * ⛔ 每次呼叫都必須是新值：重複使用等於允許重放同一筆已簽章的請求。
     */
    public static function nonce(): string
    {
        $bytes = random_bytes(16);

        // version 4：第 7 個 byte 的高 4 位固定為 0100。
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        // variant RFC 4122：第 9 個 byte 的高 2 位固定為 10。
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
