<?php

namespace App\Services\Invoices;

/**
 * Read one ECPay scalar field, accepting the official type plus a narrow,
 * deliberately-chosen compatibility form.
 *
 * ⭐ 這是一個**相容性修正與候選根因**，⛔ 不是已證實的 live 根因。
 *
 * ⛔ R1 撤回聲明：初版在這裡（以及 commit message 與結果文件）寫成「staging
 * 那兩次真實回應的 `TransCode`／`RtnCode` 就是字串 `"1"`」、「已在本機重現，
 * 非推測」。那個說法超出證據。本站當時**並沒有保存那兩次真實 response**，
 * 因此無法證明實際被拒絕的欄位就是型別問題。
 *
 * 目前能證明的只有兩件事，兩件都不等於根因：
 *
 *  1. 舊版的嚴格 `!== 1` 會拒絕字串 `"1"`（本機 fixture 可重現）；
 *  2. 公司既有且實際營運的 `cms-backend` 使用寬鬆 `== 1`，因此不會踩到 (1)。
 *
 * 精確的 live 拒絕欄位仍為 **`unknown`**，須待下一次真實嘗試由本輪新增的
 * `phase + layer[+ 數字碼]` 診斷留下可判讀證據。⛔ 不得再用真實發票盲測。
 *
 * ⛔ 所以這裡不做全域 loose comparison，而是一個**封閉的 normalizer**：
 *
 *  - 只接受官方型別（int）與一種等價表示（純數字字串，允許前後空白）。
 *  - ⛔ 拒絕 bool：PHP 的 `true == 1` 為真，寬鬆比較會把 `"TransCode": true`
 *    這種根本不是數字的回應當成成功。
 *  - ⛔ 拒絕 float：`1.0` 代表對方的 JSON 結構與我們以為的不同；在稅務憑證上
 *    「形狀不對但湊得出數字」不能當成「數字正確」。
 *  - ⛔ 拒絕 array／object／null／空字串／超長值／`1e0`／`0x1`／`+1` 等變體。
 *
 * 也就是說：放寬的**只有**成功碼 int 與純數字字串的差別。識別欄位（發票號碼、
 * 隨機碼）另有各自的 shape 驗證，⛔ 不共用這個寬鬆規則。
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
     * A status field, compared against its canonical string form.
     *
     * GetIssue 的 `IIS_Issue_Status`／`IIS_Invalid_Status` 官方型別是字串
     * `"1"`／`"0"`；整數 `1`／`0` 作為相容表示一併接受。
     *
     * ⛔ R1 收緊：只接受 **canonical** 表示。`"00"`、`"01"`、`"-0"`、`" 1 "`
     * 這類值即使數學上等於 0 或 1，也一律拒絕——它們代表對方送來的形狀與官方
     * 規格不同，而這一層決定的是「這張發票現在是不是活的」，不能靠寬鬆轉型猜。
     *
     * ⛔ bool／float／array 仍然拒絕。
     */
    public static function statusEquals(mixed $value, string $expected): bool
    {
        if (is_int($value)) {
            return (string) $value === $expected;
        }

        // ⛔ 字串必須逐字元等於官方值，不 trim、不正規化。
        return is_string($value) && $value === $expected;
    }

    /**
     * A merchant id, normalised for comparison only.
     *
     * ⭐ 2026-08-26 live 證據：Owner 在含新診斷碼的 staging 按「重拿發票」，
     * 後台得到 `ISSUE_IDENTITY|QUERY_IDENTITY`——Issue 與後續 GetIssue **都有
     * 回應**，但兩段都在 identity 檢查被拒。
     *
     * 本站的 MerchantID 設定存成字串（`integration_settings.identifier`），
     * 而綠界的 MerchantID 是純數字；若對方在 JSON 中以**數字**回傳，
     * `json_decode` 會給出 int，舊版的嚴格 `!==` 字串比較就必然不相等。
     * 這是目前**最強的候選子原因**，⛔ 但仍不是已證實的根因——現行 code 把
     * outer MerchantID、inner `IIS_Mer_ID` 與 `IIS_Relate_Number` 三個拒絕點
     * 折成同一個 `IDENTITY`，本輪拆開後才可能由下一次 live 確認。
     *
     * ⛔ 只做「用來比較」的正規化，規則刻意很窄：
     *
     *  - 接受 1–10 位純數字字串（綠界 MerchantID 的實際形狀）；
     *  - 相容接受**非負 int**，轉為十進位字串；
     *  - ⛔ 不 trim：`" 2000132 "` 代表對方送來的形狀有問題，不是同一個值；
     *  - ⛔ 拒絕正負號、科學記號、float、bool、array、object、null、空字串、
     *    超長值。
     *
     * ⛔ 前導零不補：若本站設定是 `"0012345"` 而 provider 回 int `12345`，
     * 前導零已在型別轉換中遺失且**無法無損還原**，此時必須維持不相等——
     * 猜測補零等於接受一個可能不是我們的商店代號。
     */
    public static function merchantId(mixed $value): ?string
    {
        // ⛔ bool 先擋：`is_int(true)` 為 false，但很多寬鬆路徑會放行它。
        if (is_bool($value)) {
            return null;
        }

        if (is_int($value)) {
            // ⛔ 負數不是合法的商店代號。
            return $value >= 0 && $value <= 9999999999 ? (string) $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        // ⛔ 不 trim：形狀不同就是不同。
        return preg_match('/\A[0-9]{1,10}\z/', $value) === 1 ? $value : null;
    }

    /**
     * Do a provider-supplied merchant id and our configured one match?
     *
     * ⛔ 兩邊都要通過驗證：本站設定本身若不是合法的數字字串，這裡一律回 false
     * ——設定壞掉時不得因為「兩邊都怪」而意外相等。
     */
    public static function merchantMatches(mixed $provided, string $configured): bool
    {
        $expected = self::merchantId($configured);

        if ($expected === null) {
            return false;
        }

        $actual = self::merchantId($provided);

        return $actual !== null && $actual === $expected;
    }

    /**
     * An invoice number, validated against the official `String(10)` shape.
     *
     * ⛔ R1 修正：初版用一個泛用的 `identifier()` 同時處理發票號碼與隨機碼，
     * 而它接受**任意非空字串與任意整數**。後果是 `InvoiceNo=1234` 會被當成
     * 合法發票號碼寫進稅務紀錄——一個根本不存在於國稅局的號碼，事後無法對帳。
     *
     * 官方格式為 2 碼大寫英文字軌 + 8 碼數字（例如 `AB12345678`），總長 10。
     *
     * ⛔ 一律拒絕 int：發票號碼永遠含英文字軌，能被表示成整數就代表它不是
     * 合法的發票號碼。
     */
    public static function invoiceNumber(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        return preg_match('/\A[A-Z]{2}[0-9]{8}\z/', $text) === 1 ? $text : null;
    }

    /**
     * A random code, validated against the official `String(4)` shape.
     *
     * 官方格式是 4 位數字字串，且**可能有前導零**（`"0123"`）。
     *
     * ⛔ R1 修正：初版接受 int，理由是「全數字值可能以整數抵達」——那是**沒有
     * live 證據的推測**，而且危險：整數 `123` 會被存成 `"123"`（少一碼），
     * 整數 `0123` 在 JSON 裡根本不合法，而 `"0123"` 一旦變成整數 `123` 就
     * **永久失去前導零**，無法無損還原。隨機碼是對發票的驗證資料，錯一碼就
     * 對不上。
     *
     * ⛔ 因此只接受 4 位數字**字串**，原值完整保存。若日後有官方或 live 證據
     * 顯示確實會回傳整數，需另案提出證據再放寬，⛔ 不得自行推測。
     */
    public static function randomCode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        return preg_match('/\A[0-9]{4}\z/', $text) === 1 ? $text : null;
    }
}
