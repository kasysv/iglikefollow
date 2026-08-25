<?php

namespace App\DTO;

use App\Enums\InvoiceFailureReason;
use Carbon\CarbonInterface;

/**
 * What a provider said when asked to issue an invoice.
 *
 * Three outcomes, and the third is the one that matters:
 *
 *  - issued: the invoice exists, and its number is known.
 *  - failed: the provider rejected it for a reason that will not change on a
 *    retry — a bad tax id, a closed account.
 *  - ambiguous: we do not know. A timeout, a dropped connection, a response
 *    that cannot be parsed. ⛔ The invoice may or may not exist on their side,
 *    so this must never be treated as a failure and retried: doing so risks
 *    issuing a second invoice for the same order, which is a tax problem the
 *    customer inherits.
 *
 * ⛔ There is no way to put provider text into this object.
 *
 * An earlier version accepted a free-form message and "sanitized" it by
 * stripping braces and truncating. That is not redaction — it removes
 * structure, not secrets, and "MerchantID=SECRET123 buyer@example.com" passed
 * through it unchanged and into the database. Guessing with regexes would fail
 * the same way, one provider phrasing later. So the constructor takes a reason
 * token from a closed set, and the stored message comes from our own enum. An
 * adapter that receives something it cannot classify gets the generic reason,
 * not a stored copy of what it was told.
 */
final class InvoiceIssueResult
{
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $invoiceNumber = null,
        public readonly ?string $randomCode = null,
        public readonly ?string $providerReference = null,
        public readonly ?InvoiceFailureReason $reason = null,
        public readonly ?CarbonInterface $issuedAt = null,
        /**
         * ⭐ 失敗階段與對方數字碼，例如 `ISSUE_RTN=10000001|QUERY_RTN=10000050`。
         *
         * ⛔ 仍然沒有任何路徑可以把 provider 的**文字**放進這個物件：
         * `InvoiceFailureCode` 只由本站固定 token 與通過整數驗證的數字組成。
         */
        public readonly ?InvoiceFailureCode $failureCode = null,
    ) {}

    /**
     * @param  CarbonInterface|null  $issuedAt  the provider's own issue
     *                                          time. ⛔ Preferred over our clock: the tax authority's record uses
     *                                          theirs, and a local timestamp would drift from it by however long the
     *                                          queue took.
     */
    public static function issued(
        string $invoiceNumber,
        ?string $randomCode = null,
        ?string $providerReference = null,
        ?CarbonInterface $issuedAt = null,
    ): self {
        return new self('issued', $invoiceNumber, $randomCode, $providerReference, issuedAt: $issuedAt);
    }

    /**
     * A rejection that will not change if we ask again.
     *
     * ⛔ Takes a reason, not a message. An unrecognised token becomes Unknown,
     * and an unknown *failure* is not a failure we can be sure about — it is
     * routed to ambiguous rather than closed off, because "we could not
     * classify the rejection" is not evidence that nothing was issued.
     */
    public static function failed(
        InvoiceFailureReason|string|null $reason,
        ?InvoiceFailureCode $code = null,
    ): self {
        $reason = $reason instanceof InvoiceFailureReason
            ? $reason
            : InvoiceFailureReason::classify($reason);

        if ($reason->isAmbiguous()) {
            return new self('ambiguous', reason: $reason, failureCode: $code);
        }

        return new self('failed', reason: $reason, failureCode: $code);
    }

    /** 結果不明；⛔ 呼叫端必須進人工對帳，不得自動重送。 */
    public static function ambiguous(
        InvoiceFailureReason|string|null $reason = null,
        ?InvoiceFailureCode $code = null,
    ): self {
        $reason = $reason instanceof InvoiceFailureReason
            ? $reason
            : InvoiceFailureReason::classify($reason);

        // 明確要求 ambiguous 時就是 ambiguous，即使 reason 本身可歸類。
        return new self('ambiguous', reason: $reason, failureCode: $code);
    }

    public function isIssued(): bool
    {
        return $this->outcome === 'issued';
    }

    public function isFailed(): bool
    {
        return $this->outcome === 'failed';
    }

    public function isAmbiguous(): bool
    {
        return $this->outcome === 'ambiguous';
    }

    /**
     * 落盤與顯示用的代碼。
     *
     * ⭐ 優先使用精確的階段／層級碼（`ISSUE_RTN=10000001`），⛔ 而不是舊版
     * 那個對所有失敗都一樣的 `UNKNOWN`——Owner 正是因為只看得到 UNKNOWN 而
     * 無法分辨憑證、傳輸、開立欄位或查詢解析問題。
     *
     * ⛔ 兩者都是本地 allowlist：`InvoiceFailureCode` 由固定 token 加通過整數
     * 驗證的數字組成，`InvoiceFailureReason` 是封閉 enum。provider 的任意字串
     * 沒有任何路徑可以到達這裡。
     */
    public function code(): ?string
    {
        return $this->failureCode?->toString() ?? $this->reason?->value;
    }

    /**
     * 落盤與顯示用的訊息；⛔ 由本地 allowlist 產生，不含 provider 任何字元。
     *
     * 有精確層級時給該層的固定中文說明，讓 Owner 不必記代碼；⛔ 說明裡不放
     * 數字——數字自己已經在 code 欄位，混進句子只會讓兩邊不一致。
     */
    public function message(): ?string
    {
        $layer = $this->failureCode?->primaryLayer();

        if ($layer !== null && $layer !== '') {
            $phase = $this->failureCode?->phase() === 'QUERY' ? '查詢' : '開立';

            /*
             * ⛔ R1 更正：初版誤以為 `failure_message` 是 64 bytes 並用
             * `mb_strcut(..., 60)` 截斷。實際上 migration 只對 `failure_code`
             * 指定 64；`failure_message` 用 Laravel 預設 `string`，即 255
             * 字元。那個截斷既不必要，也會無謂地砍掉給 Owner 看的說明。
             *
             * 說明本身維持短句即可，不再截斷。
             */
            return $phase.'發票時'.self::layerExplanation($layer);
        }

        return $this->reason?->message();
    }

    /**
     * ⛔ 固定中文說明，一個字都不來自 provider。
     *
     * 詳細的數字碼在 `failure_code` 欄位，這裡只說明「哪一層出問題」；
     * ⛔ 說明句子不放數字，避免與 code 欄位兩邊不一致。
     *
     * 未登記的層級退回一句通用說明，⛔ 而不是把 token 原樣印出來。
     */
    private static function layerExplanation(string $layer): string
    {
        return match ($layer) {
            'HTTP' => '連線或回應異常，結果未確認。',
            'JSON' => '回應格式無法解讀。',
            'IDENTITY' => '回應的商店或關聯編號與本站不符。',
            'TRANS' => '對方於外層拒絕本次請求。',
            'DECRYPT' => '回應無法解密，請確認金鑰設定。',
            'SHAPE' => '回應結構不符預期。',
            // ⭐ R1：欄位層級各自的說明，Owner 不必再猜是哪一欄。
            'NUMBER' => '回應的發票號碼格式不符官方規格。',
            'RANDOM' => '回應的隨機碼格式不符官方規格。',
            'DATE' => '回應的開立日期無法解析。',
            'RTN' => '對方回覆錯誤代碼。',
            'STATUS' => '發票未開立或已作廢。',
            'CONFIG' => '發票設定不完整或未啟用。',
            'PAYLOAD' => '本站發票資料不完整。',
            default => '發生未預期狀況，請人工確認。',
        };
    }
}
