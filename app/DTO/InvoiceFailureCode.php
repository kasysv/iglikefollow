<?php

namespace App\DTO;

/**
 * A short, closed-set code saying **where** invoicing failed and, when the
 * provider gave one, **which numeric code** they returned.
 *
 * ⭐ 為什麼需要這個型別：Owner 在 staging 看到的永遠只有 `UNKNOWN`。現行 client
 * 把 HTTP 失敗、outer `TransCode`、AES 解密失敗、inner `RtnCode`、回傳 shape 與
 * identity 不符**全部**折成同一個結果，於是後台無從分辨是憑證問題、傳輸問題、
 * 開立欄位問題還是查詢解析問題——只能靠再送一次真實 Issue 去猜，而那正是最不
 * 該做的事（每一次盲測都可能開出一張真的發票）。
 *
 * ⛔ 這個物件只由**程式自己的固定字串**與**整數**組成：
 *
 *   ISSUE_RTN=10000001
 *   ISSUE_TRANS=0
 *   ISSUE_RTN=10000001|QUERY_RTN=10000050
 *   ISSUE_HTTP
 *   QUERY_IDENTITY
 *
 * ⛔ provider 的任意字串永遠不會被拼進來。`RtnMsg`、`TransMsg`、raw body、
 * MerchantID、HashKey、HashIV、Email、手機、統編、抬頭、載具與 order target
 * 一個都不經過這裡——`numeric()` 只接受能通過整數驗證的值，其餘一律降級為
 * 該層的固定 local code。
 *
 * ⛔ 欄位長度：`invoices.failure_code` 與 `invoice_attempts.failure_code` 在
 * migration 中明確指定為 64 字元，且 code 全為 ASCII，所以字元數即位元組數。
 * 兩段組合的最壞情況遠短於此，`toString()` 仍會硬性截斷作為最後防線。
 *
 * ⛔ 這個上限只屬於 `failure_code`。`failure_message` 使用 Laravel 預設
 * `string`（255 字元），⛔ 不受此限——初版誤把兩者混為一談並無謂截短了說明。
 */
final class InvoiceFailureCode
{
    /** DB 欄位上限；⛔ 超過就會被靜默截斷或寫入失敗。 */
    public const MAX_LENGTH = 64;

    /** provider 數字碼的最大位數；⛔ 超長數字不是合法的 provider code。 */
    private const MAX_DIGITS = 12;

    /**
     * 允許出現在 code 中的層級 token。
     *
     * ⛔ 封閉集合。新增一個層級必須同時在這裡登記，否則 `local()` 會拒絕它，
     * 測試也會抓到——這比讓任意字串流進 code 安全得多。
     *
     * @var list<string>
     */
    private const LAYERS = [
        'HTTP',      // 連線失敗、逾時、非 2xx
        'JSON',      // body 不是 JSON 或不是 array
        'IDENTITY',  // MerchantID 或 RelateNumber 與我們的不符
        'TRANS',     // outer TransCode 非 1（有數字時帶數字）
        'DECRYPT',   // AES 解密失敗或解出來不是 array
        'SHAPE',     // 成功碼但整體結構不符（例如缺 Data 欄位）
        /*
         * ⭐ R1：把「成功碼但欄位異常」細分到欄位層級。
         *
         * ⛔ 初版只有一個 `SHAPE`，同時涵蓋發票號碼、隨機碼與日期。下一次 live
         * 若仍失敗，Owner 依然不知道是哪一欄被拒絕，等於還是要靠真實發票盲測
         * ——而那正是本輪要避免的事。
         *
         * ⛔ 只記「哪一欄不合格」，絕不記那一欄的值。
         */
        'NUMBER',    // 發票號碼不符官方 String(10) shape
        'RANDOM',    // 隨機碼不符官方 String(4) shape
        'DATE',      // 開立日期無法以官方格式解析
        'RTN',       // inner RtnCode 非 1（有數字時帶數字）
        'STATUS',    // 查詢結果為未開立或已作廢
        'CONFIG',    // 端點不在白名單、缺 credential、開關關閉
        'PAYLOAD',   // 本站資料組不出合法 payload
    ];

    /** @param list<string> $segments 已驗證的片段，如 `ISSUE_RTN=10000001` */
    private function __construct(private readonly array $segments) {}

    /**
     * A code carrying the provider's own numeric value.
     *
     * ⛔ `$value` 必須是整數或純數字字串。array、bool、float、超長數字或任何
     * 含非數字字元的值都會被拒絕，改回同層的固定 local code——⛔ 絕不把無法
     * 驗證的內容拼進 code。
     */
    public static function numeric(string $phase, string $layer, mixed $value): self
    {
        $digits = self::digits($value);

        if ($digits === null) {
            return self::local($phase, $layer);
        }

        return new self([self::token($phase, $layer).'='.$digits]);
    }

    /** A code with no provider number: just where it failed. */
    public static function local(string $phase, string $layer): self
    {
        return new self([self::token($phase, $layer)]);
    }

    /**
     * Combine the Issue code with the follow-up Query code.
     *
     * ⛔ 兩段都保留：Owner 需要同時看到「開立時對方說什麼」與「事後查詢時對方
     * 說什麼」。只留一段會讓其中一個問題永遠看不見。
     */
    public function withQuery(?self $query): self
    {
        if ($query === null) {
            return $this;
        }

        return new self([...$this->segments, ...$query->segments]);
    }

    /**
     * The value written to `failure_code`.
     *
     * ⛔ 硬性截斷到 64 字元作為最後防線。正常情況遠短於此；真的超長時，
     * 寧可留下前半段可讀資訊，也不要讓寫入失敗或被 DB 靜默切掉。
     */
    public function toString(): string
    {
        return mb_substr(implode('|', $this->segments), 0, self::MAX_LENGTH);
    }

    /**
     * 這個 code 的第一段層級，用來產生本地說明文字。
     *
     * ⛔ 只回傳層級 token，不回傳數字：說明文字是給人看的固定中文，
     * 不該把 provider 的數字混進句子裡（數字自己已經在 code 欄位）。
     */
    public function primaryLayer(): string
    {
        $first = $this->segments[0] ?? '';
        $first = explode('=', $first)[0];
        $parts = explode('_', $first, 2);

        return $parts[1] ?? '';
    }

    public function phase(): string
    {
        $first = $this->segments[0] ?? '';

        return explode('_', $first, 2)[0] ?? '';
    }

    /**
     * ⛔ phase 與 layer 都必須是登記過的固定值。
     *
     * 不在集合內時退回 `UNKNOWN`，⛔ 而不是把傳進來的字串照原樣用——那正是
     * 讓 provider 內容漏進 code 的路徑。
     */
    private static function token(string $phase, string $layer): string
    {
        $phase = in_array($phase, ['ISSUE', 'QUERY'], true) ? $phase : 'ISSUE';
        $layer = in_array($layer, self::LAYERS, true) ? $layer : 'SHAPE';

        return $phase.'_'.$layer;
    }

    /**
     * The value as plain digits, or null when it is not a provider number.
     *
     * ⛔ 只接受 int 或純數字字串（可帶負號，綠界確實有負數 TransCode 情境）。
     * `is_numeric()` 不夠嚴格：它會放行 `1e5`、`0x1A`、` 12 ` 與浮點數。
     */
    private static function digits(mixed $value): ?string
    {
        if (is_int($value)) {
            $text = (string) $value;
        } elseif (is_string($value)) {
            $text = trim($value);
        } else {
            return null;
        }

        if (preg_match('/\A-?[0-9]{1,'.self::MAX_DIGITS.'}\z/', $text) !== 1) {
            return null;
        }

        return $text;
    }
}
