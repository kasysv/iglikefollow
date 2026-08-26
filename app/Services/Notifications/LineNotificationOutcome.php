<?php

namespace App\Services\Notifications;

/**
 * The result of one LINE push attempt — a closed set of local tokens.
 *
 * ⛔⛔ 這個物件是「可以被保存／記錄的東西」的**唯一出口**。它只帶三樣資訊：
 * 本站自己的 failure token、HTTP 狀態**類別**，以及要不要重試。
 *
 * ⛔ 絕不攜帶：Channel Access Token、完整接收 ID、客戶 Email／電話、
 * 訊息全文，或 LINE 回傳的 response body。那些東西一旦進了 DB、audit、
 * exception message 或 failed job payload，就會出現在備份、log 匯出與
 * 錯誤追蹤服務裡——而那些地方的存取控制通常比資料庫寬鬆得多。
 *
 * ⛔ 也刻意不保存 LINE 的錯誤訊息原文：那是自由文字，我們無法保證它不含
 * 我們送出去的內容片段。
 */
final class LineNotificationOutcome
{
    /**
     * ⛔ 封閉的 reason allowlist。
     *
     * 任何不在這裡的字串都會被 `normalise()` 收斂成 `unknown`——⛔ 讓一個
     * 沒人設計過的值靜靜地流進 log，就是這個 allowlist 存在的理由。
     */
    private const REASONS = [
        // 送出前就停下來的本地原因。
        'disabled',            // Owner 開關關閉
        'not_configured',      // credential／接收 ID 不完整
        'outbound_blocked',    // 目前環境不允許外呼（local／testing）
        'endpoint_mismatch',   // 端點與版本控制常數不符
        'invalid_target',      // 接收 ID 形狀不合法
        'empty_message',       // 沒有可送的內容

        // 送出後的結果。
        'sent',
        'rate_limited',        // 429
        'server_error',        // 5xx
        'rejected',            // 永久 4xx
        'transport_error',     // 連線／逾時
        'unknown',
    ];

    private function __construct(
        public readonly string $reason,
        public readonly ?int $statusClass,
        public readonly bool $retryable,
    ) {}

    public function successful(): bool
    {
        return $this->reason === 'sent';
    }

    public static function sent(): self
    {
        return new self('sent', 2, false);
    }

    /**
     * A local stop — nothing was sent, and retrying will not help.
     *
     * ⛔ `retryable = false`：這些是設定問題，不是暫時性故障。重試一個
     * 「Owner 把開關關掉」的工作只會讓 queue 一直轉。
     */
    public static function blocked(string $reason): self
    {
        return new self(self::normalise($reason), null, false);
    }

    /**
     * The provider answered, or the transport failed.
     *
     * ⛔ 只保存 status **類別**（2／4／5），不保存精確 status code——
     * 精確碼對排錯的幫助有限，卻可能隨著 LINE 的錯誤分類洩漏我們送了什麼。
     */
    public static function fromStatus(int $status): self
    {
        if ($status === 429) {
            // ⛔ 可重試：這是「太快」，不是「不可以」。
            return new self('rate_limited', 4, true);
        }

        if ($status >= 500) {
            return new self('server_error', 5, true);
        }

        if ($status >= 400) {
            /*
             * ⛔ 永久 4xx 不重試。
             *
             * 401／403 是 token 錯，400 是 payload 或接收 ID 錯——重試同一份
             * 內容只會得到同一個答案，還會消耗 LINE 的配額。這種情況需要
             * Owner 去後台改設定，不是等 queue 再試一次。
             */
            return new self('rejected', 4, false);
        }

        if ($status >= 200 && $status < 300) {
            return self::sent();
        }

        return new self('unknown', intdiv($status, 100), false);
    }

    /** ⛔ 連線失敗／逾時：結果**未知**，可以有限重試。 */
    public static function transportError(): self
    {
        return new self('transport_error', null, true);
    }

    /**
     * ⛔ 可以安全寫進 log 的三個欄位。
     *
     * @return array{reason: string, status_class: int|null, retryable: bool}
     */
    public function toLogContext(): array
    {
        return [
            'reason' => $this->reason,
            'status_class' => $this->statusClass,
            'retryable' => $this->retryable,
        ];
    }

    private static function normalise(string $reason): string
    {
        return in_array($reason, self::REASONS, true) ? $reason : 'unknown';
    }
}
