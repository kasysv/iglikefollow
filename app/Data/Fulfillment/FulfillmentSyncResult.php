<?php

namespace App\Data\Fulfillment;

use App\Enums\FulfillmentStatus;

/**
 * What the provider says an existing order is doing now.
 *
 * ⛔ `unrecognised()` exists so that "we do not understand this status" can
 * never be rounded to `completed`. Providers add status values over time, and
 * treating an unknown one as finished would close an order that is still
 * running — or one that failed.
 */
final class FulfillmentSyncResult
{
    private function __construct(
        public readonly ?FulfillmentStatus $status,
        /**
         * ⭐ provider 回傳的**原文** status token，例如 `In progress`。
         *
         * Owner 要求後台顯示對方實際回報的字串，而不是本站翻譯過的「處理中」。
         *
         * ⛔ 只可能是 gateway allowlist 中的 exact token——`unrecognised()` 不
         * 帶原文，所以任意 provider 文字沒有任何路徑可以到達這裡。內部
         * `$status` 仍然是唯一控制狀態機的東西；這個欄位純粹是給人看的。
         */
        public readonly ?string $providerStatusToken = null,
        /**
         * ⭐ provider 回報的剩餘數量。
         *
         * ⛔ 已由 gateway 驗證為非負整數；`null` 代表「這次沒拿到合法的值」，
         * 呼叫端必須保留上一次已保存的值，⛔ 不得清空。
         *
         * ⛔ `0` 與 `null` 是兩件不同的事：前者是「補完了」，後者是「還不知道」。
         */
        public readonly ?int $remains = null,
    ) {}

    /**
     * @param  string|null  $providerStatusToken  gateway allowlist 中的 exact token
     * @param  int|null  $remains  已驗證的非負整數，或 null
     */
    public static function status(
        FulfillmentStatus $status,
        ?string $providerStatusToken = null,
        ?int $remains = null,
    ): self {
        return new self($status, $providerStatusToken, $remains);
    }

    /**
     * ⛔ 讀不懂：維持原狀，只留下一筆本地事件。
     *
     * ⛔ 刻意不接受原文與 remains。一個我們讀不懂的回應，其中的任何欄位都
     * 同樣不可信；把它們保存下來等於用未知內容覆蓋已知良好的資料。
     */
    public static function unrecognised(): self
    {
        return new self(null);
    }

    public function isRecognised(): bool
    {
        return $this->status !== null;
    }
}
