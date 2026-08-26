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

    /**
     * ⭐ v2：手機正規化語意升版（見 `normalizePhone()`）。
     *
     * ⛔ 必須換 domain。v1 的 hash 是用「原樣數字序列」算的，v2 用的是
     * canonical form——同一支號碼在兩版會得到不同的 hash。沿用 v1 domain 會
     * 讓新舊值混在同一個欄位裡而無法分辨，backfill 也就無從判斷哪些需要更新。
     */
    private const PHONE_DOMAIN = 'iglikefollow.order-lookup.phone.v2';

    /**
     * ⛔ 與 `CheckoutRequest` 完全一致的輸入邊界。
     *
     * `customer_email => ['required', 'email', 'max:80']`
     * `customer_phone => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-() ]{6,20}$/']`
     *
     * ⭐ 兩邊必須一致：下單時**存不進去**的值，查詢時就不該被當成有效輸入。
     * 查詢比下單寬鬆的話，等於開了一條下單流程擋得住、查詢流程擋不住的路。
     */
    private const EMAIL_MAX_LENGTH = 80;

    private const PHONE_MIN_LENGTH = 6;

    private const PHONE_MAX_LENGTH = 20;

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
     * ⭐ 正規化規則見 `normalizePhone()`。Owner 後補了台灣國碼等價需求，
     * 因此 v2 把同一支台灣手機的三種寫法收斂為同一個值。
     */
    public static function forPhone(?string $phone): ?string
    {
        $normalized = self::normalizePhone($phone);

        return $normalized === null ? null : self::hash(self::PHONE_DOMAIN, $normalized);
    }

    /**
     * ⛔ 與 checkout 相同的邊界：`email` 語意 ＋ 最長 80。
     *
     * ⭐ R2：加上 shape 與長度驗證。
     *
     * ⛔ 沒有這道驗證時，`not-an-email` 會被原樣算成 HMAC——一個絕不可能存在於
     * `orders` 的值。它永遠查不到東西，所以看起來「沒壞」，但那是把不可能的輸入
     * 一路帶到 DB 查詢才失敗，而不是在邊界擋掉。
     *
     * ⛔ 邊界必須與 `CheckoutRequest`（`email`、`max:80`）一致：下單時存不進去
     * 的值，查詢時就不該被當成有效輸入。兩邊寬緊不同會讓「查得到什麼」與
     * 「存得進什麼」出現落差。
     *
     * ⛔ 長度先於 `filter_var` 檢查：超長字串不必送進 validator。
     */
    public static function normalizeEmail(?string $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }

        $email = mb_strtolower(trim($email));

        if ($email === '' || mb_strlen($email) > self::EMAIL_MAX_LENGTH) {
            return null;
        }

        // ⛔ 與 Laravel `email` rule 同樣採 `FILTER_VALIDATE_EMAIL`。
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    /**
     * A phone number in canonical form, or null when it is not usable.
     *
     * ⭐ Owner 後補的等價規則。回傳值一定帶前綴，⛔ 讓三類語意永遠不會互撞：
     *
     *  - `TW:09XXXXXXXX` —— 台灣手機。`09XXXXXXXX`、`+8869XXXXXXXX`、
     *    `008869XXXXXXXX` 三種寫法收斂為同一個值。
     *  - `INT:+<digits>` —— 明確帶國際前綴（`+` 或 `00`）的其他號碼。
     *    因此 `+14155552671` 與 `0014155552671` 等價。
     *  - `RAW:<digits>` —— 沒有明確國際前綴、也不是台灣手機的號碼。
     *    ⛔ 只做精確比對，**不猜國家**。
     *
     * ⛔ 前綴是必要的，不是裝飾：少了它，台灣的 `0912345678` 與某個國家的
     * 本地號碼 `0912345678` 會產生相同 hash，讓兩個不相干的人互相看到訂單。
     *
     * ⛔ 裸 `886912345678`（沒有 `+` 或 `00`）**不**視為國際格式——那是一段
     * 我們無法確定意圖的數字，可能是別國的本地號碼。它走 `RAW:`。
     *
     * ⛔ 不完整、超長、市話或其他模糊形狀一律走 `RAW:` 精確比對，⛔ 不猜、
     * 不補、不誤撞。
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if (! is_string($phone)) {
            return null;
        }

        $trimmed = trim($phone);

        /*
         * ⭐ R2：先套用與 checkout 完全相同的輸入邊界，再談語意。
         *
         * ⛔ 字元集合只有數字與 `+ - ( ) 空白`——⛔ 不含 tab。R1 的
         * `preg_replace` 多刪了 `\t`，比 checkout 寬；那代表存不進 DB 的值
         * 在查詢端卻被接受。兩邊的允許集合必須逐字元相同。
         *
         * ⛔ 長度 6–20 以**原輸入**計算，與 checkout 的 regex 一致，
         * ⛔ 不是移除格式字元後才算——否則 `(((((0912345678)))))` 這種
         * 20 字以上的輸入會在查詢端通過、在下單端被拒。
         */
        if (preg_match('/\A[0-9+\-() ]{'.self::PHONE_MIN_LENGTH.','.self::PHONE_MAX_LENGTH.'}\z/', $trimmed) !== 1) {
            return null;
        }

        $digits = preg_replace('/[+\-() ]/', '', $trimmed) ?? '';

        if ($digits === '' || preg_match('/\A[0-9]+\z/', $digits) !== 1) {
            return null;
        }

        /*
         * ⛔ 是否帶「明確的」國際前綴，只看**原字串**的開頭。
         *
         * 必須在移除格式字元之前判斷：`+` 會被上面那行刪掉，之後就分不出
         * `+886…` 與裸 `886…` 了——而那兩者的語意完全不同。
         */
        $hasPlus = str_starts_with($trimmed, '+');
        $hasZeroZero = str_starts_with($digits, '00');

        // 明確國際前綴後的實際國碼＋號碼。
        $international = null;

        if ($hasPlus) {
            $international = $digits;
        } elseif ($hasZeroZero) {
            $international = substr($digits, 2);
        }

        /*
         * ⭐ 台灣手機：三種寫法收斂為 `TW:09XXXXXXXX`。
         *
         * 台灣手機的本地形式固定是 `09` ＋ 8 碼，國際形式是國碼 `886` ＋
         * 去掉開頭 `0` 的 `9XXXXXXXX`。
         */
        if ($international !== null && preg_match('/\A886(9[0-9]{8})\z/', $international, $m) === 1) {
            return 'TW:0'.$m[1];
        }

        if ($international === null && preg_match('/\A09[0-9]{8}\z/', $digits) === 1) {
            return 'TW:'.$digits;
        }

        /*
         * ⭐ R2 的關鍵修正：**明確國際前綴一旦出現，就只有兩種結局——
         * 有效的 TW／INT，或 null。⛔ 絕不降級成 `RAW:`。**
         *
         * ⛔ R1 的 bug：`+01234567` 形狀不合格後掉到最後一行，變成
         * `RAW:01234567`——而本地輸入 `01234567` 也是 `RAW:01234567`。
         * 兩者 hash 相同，於是一個**無效**的國際號碼可以撞上一個**有效**的
         * 本地號碼，讓甲查到乙的訂單。
         *
         * ⛔ 這正是降級的危險：使用者明確表示了「這是國際號碼」（打了 `+`
         * 或 `00`），我們卻在看不懂時把那個意圖丟掉、改用另一套語意重新
         * 解釋同一串數字。看不懂就該拒絕，不是換個方式猜。
         */
        if ($international !== null) {
            /*
             * ⛔ 7–15 位（E.164 上限 15）；首碼非 0——國碼不會以 0 開頭，
             * 因此 `+0…`／`000…` 不是我們認得的國際號碼。
             */
            if (preg_match('/\A[1-9][0-9]{6,14}\z/', $international) === 1) {
                return 'INT:+'.$international;
            }

            return null;
        }

        /*
         * ⛔ 只有**沒有**明確國際前綴時才走 `RAW:` 精確比對。
         *
         * 包含：裸 `886…`、市話、外國本地號碼。⛔ 不猜國家、不補國碼——
         * 精確相同才算相同。長度／字元邊界在函式開頭已經套過。
         */
        return 'RAW:'.$digits;
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
