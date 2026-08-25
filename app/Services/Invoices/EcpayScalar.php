<?php

namespace App\Services\Invoices;

/**
 * Read one ECPay scalar field the way it actually arrives on the wire.
 *
 * ⭐ 本輪事故的根因就在這裡。
 *
 * 綠界官方文件把 `TransCode` 與 `RtnCode` 標為 `Int`，本站於是用嚴格比較
 * `($json['TransCode'] ?? null) !== 1`。但實際 live 回應（AES 解密 →
 * `json_decode` 之後）這些欄位常常是**字串** `"1"`。嚴格比較把一個真正成功的
 * 回應判成失敗——Owner 連續兩張 LINE Pay 訂單都出現「綠界端實際已開立、本站
 * 顯示開立失敗」，就是這個 false negative。
 *
 * 公司既有、且實際在營運的 `cms-backend` 模組用的是寬鬆比較 `== 1`，這正是它
 * 沒有踩到這個坑的原因；那份程式因此是有力的反證，⛔ 但不是「全面改用寬鬆比較」
 * 的授權。
 *
 * ⛔ 所以這裡不做全域 loose comparison，而是一個**封閉的 normalizer**：
 *
 *  - 只接受官方型別（int）與有實據的等價表示（純數字字串，允許前後空白）。
 *  - ⛔ 拒絕 bool：PHP 的 `true == 1` 為真，寬鬆比較會把 `"TransCode": true`
 *    這種根本不是數字的回應當成成功。
 *  - ⛔ 拒絕 float：`1.0` 代表對方的 JSON 結構與我們以為的不同；在稅務憑證上
 *    「形狀不對但湊得出數字」不能當成「數字正確」。
 *  - ⛔ 拒絕 array／object／null／空字串／超長值／`1e0`／`0x1`／`+1` 等變體。
 *
 * 也就是說：放寬的**只有** int 與純數字字串的差別，其餘每一道型別防線都原樣
 * 保留。
 */
final class EcpayScalar
{
    /** ⛔ 上限：真正的 provider code 不會有這麼多位數。 */
    private const MAX_DIGITS = 12;

    /**
     * The field as an integer, or null when it is not a number we accept.
     *
     * ⛔ 回傳 null 代表「這不是一個我們認得的數字」，呼叫端必須當成失敗處理，
     * ⛔ 不得退回預設值繼續往下走。
     */
    public static function int(mixed $value): ?int
    {
        // ⛔ bool 必須先擋：`is_int(true)` 為 false，但很多寬鬆路徑會放行它。
        if (is_bool($value)) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value)) {
            // ⛔ float、array、object、null 一律拒絕。
            return null;
        }

        $text = trim($value);

        // ⛔ 只接受純數字（可帶負號）；`1e0`、`0x1`、`+1`、`1.0`、`` 全部拒絕。
        if (preg_match('/\A-?[0-9]{1,'.self::MAX_DIGITS.'}\z/', $text) !== 1) {
            return null;
        }

        return (int) $text;
    }

    /**
     * Does this field equal the expected integer?
     *
     * ⛔ 用途明確：`RtnCode`／`TransCode` 這種「等於 1 才是成功」的判斷。
     * 無法解讀為數字時回 false，⛔ 不是回 true——看不懂就不算成功。
     */
    public static function equalsInt(mixed $value, int $expected): bool
    {
        return self::int($value) === $expected;
    }

    /**
     * A status field as its canonical string form.
     *
     * GetIssue 的 `IIS_Issue_Status`／`IIS_Invalid_Status` 官方型別是字串
     * `"1"`／`"0"`，但同樣可能以整數 `1`／`0` 到達。⛔ 同樣只放寬 int 與純
     * 數字字串，bool／float／array 仍然拒絕。
     */
    public static function statusEquals(mixed $value, string $expected): bool
    {
        $number = self::int($value);

        return $number !== null && (string) $number === $expected;
    }

    /**
     * An identifier-like field (invoice number, random code) as a string.
     *
     * ⭐ 第二個同類型的 false negative：`RandomNumber` 官方型別是 String(4)，
     * 但 `1234` 這種全數字值在 JSON 裡可能以**整數**到達。舊版的讀取只接受
     * string，於是一個真正開立成功的回應會因為「缺隨機碼」被判成不成功——
     * 與 `RtnCode` 那個是同一類問題。
     *
     * ⛔ 只放寬 int：`1234` → `"1234"`。bool／float／array／object／null／
     * 空字串仍然拒絕。
     *
     * ⛔ 刻意**不**接受 float：`1234.0` 轉成字串會變 `"1234"` 看似正確，但
     * 那代表對方的 JSON 結構與我們以為的不同；而且真正的隨機碼可能有前導 0
     * （`"0123"`），一旦被當成數字就永久失真——所以 int 來源本身也標記為
     * 「可用但不理想」，字串永遠優先。
     */
    public static function identifier(mixed $value): ?string
    {
        if (is_bool($value) || is_float($value)) {
            return null;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
