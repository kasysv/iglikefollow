<?php

namespace App\Support;

/**
 * Keyed, domain-separated lookup hashes for contact details.
 *
 * ⭐ 為什麼需要它：`orders.customer_email` 與 `customer_phone` 是 Laravel
 * `encrypted` cast。加密欄位每次寫入的密文都不同，⛔ 無法用 `where()` 查詢——
 * 而免會員訂單查詢必須靠 Email／手機找到訂單。
 *
 * ⛔ 三條不能踩的線：
 *
 *  1. **不另存明文副本。** 那等於把加密欄位的保護整個抵銷掉：DB 外流時
 *     攻擊者直接拿到所有客人的 Email 與手機。
 *  2. **不用無 key 的普通 hash。** `sha256(email)` 可以離線暴力破解——Email
 *     與手機的取值空間小到可以用字典跑完，加密欄位形同虛設。
 *  3. **不用可逆的 deterministic encryption。** 那只是把明文換個形式存著。
 *
 * 所以用 `APP_KEY` 的 **HMAC-SHA256**：沒有 server secret 就算不出這個值，
 * 因此無法從 hash 反推或字典比對。
 *
 * ⛔ Email 與手機使用**不同的 domain 常數**。少了 domain separation，同一個
 * 字串在兩個欄位會產生相同的 hash——一個人若拿 Email 當使用者名稱、又剛好與
 * 另一人的手機字串相同，兩筆不相干的資料就會互相匹配。
 */
final class ContactLookupHash
{
    /** ⛔ 固定字串，兩者必須不同。改動它會使既有 hash 全部失效。 */
    private const EMAIL_DOMAIN = 'iglikefollow.order-lookup.email.v1';

    private const PHONE_DOMAIN = 'iglikefollow.order-lookup.phone.v1';

    /**
     * The lookup hash for an email address, or null when there is nothing to hash.
     *
     * ⛔ 正規化只做 trim ＋ 轉小寫：Email 的 local part 理論上區分大小寫，
     * 但實務上沒有供應商這樣做，而客人重打時大小寫幾乎一定不同。
     * ⛔ 不做其他正規化（例如移除 Gmail 的點或 `+` 標籤）——那會讓兩個
     * **不同的** Email 位址被視為同一個人。
     */
    public static function forEmail(?string $email): ?string
    {
        $normalized = self::normalizeEmail($email);

        return $normalized === null ? null : self::hash(self::EMAIL_DOMAIN, $normalized);
    }

    /**
     * The lookup hash for a phone number, or null.
     *
     * ⛔ 只移除 `CheckoutRequest` 已允許的格式字元（`+ - ( ) 空白`），保留其餘
     * 的數字序列原樣。
     *
     * ⛔ **不自行把 `+886` 與 `09` 推定為同一支號碼。** 那是一個關於台灣號碼
     * 格式的假設，而這個 hash 決定「誰能看到哪一張訂單」——猜錯的方向是讓
     * 甲看到乙的訂單。客人用哪種寫法下單，就用同一種寫法查詢。
     */
    public static function forPhone(?string $phone): ?string
    {
        $normalized = self::normalizePhone($phone);

        return $normalized === null ? null : self::hash(self::PHONE_DOMAIN, $normalized);
    }

    /** ⛔ trim ＋ 小寫；空字串視為沒有值。 */
    public static function normalizeEmail(?string $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }

        $email = mb_strtolower(trim($email));

        return $email === '' ? null : $email;
    }

    /** ⛔ 只移除允許的格式字元，不改動數字本身。 */
    public static function normalizePhone(?string $phone): ?string
    {
        if (! is_string($phone)) {
            return null;
        }

        // ⛔ 與 CheckoutRequest 的 regex 允許集合一致：`+ - ( ) 空白`。
        $digits = preg_replace('/[+\-() \t]/', '', trim($phone)) ?? '';

        return $digits === '' ? null : $digits;
    }

    /**
     * ⛔ HMAC-SHA256，key 為 `APP_KEY`；回傳 64 個十六進位字元。
     *
     * ⛔ 沒有 `APP_KEY` 時回 null 而不是退回無 key 的 hash——那會靜默地把
     * 保護等級降到可暴力破解，而呼叫端完全不會察覺。
     */
    private static function hash(string $domain, string $value): ?string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            return null;
        }

        return hash_hmac('sha256', $domain.'|'.$value, $key);
    }
}
