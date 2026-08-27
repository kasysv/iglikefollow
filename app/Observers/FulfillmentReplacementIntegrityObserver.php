<?php

namespace App\Observers;

use App\Models\FulfillmentOrder;
use RuntimeException;

/**
 * The cross-row invariants of a replacement chain, enforced at the model layer.
 *
 * ⛔⛔ 為什麼不是只在 `CreateFulfillmentReplacement` 裡檢查：
 *
 * 施工單早已要求「不得只靠 UI」，而 GPT 複審實證了更深一層的問題——
 * **direct model／factory 寫入**（`FulfillmentOrder::create([...])`）完全繞過
 * 那個 action。GPT 用它建立了一筆跨商品的 child：A 商品的更換批次掛在
 * B 商品底下，公開頁與後台都會顯示錯誤的鏈。
 *
 * ⛔ 單列的 DB guard 擋不住這些，因為它們是**跨列**的規則：要知道
 * child 合不合法，必須去看 parent。SQLite 的 CHECK 不能做子查詢，而為此
 * 寫一組跨 driver 的 trigger，維護成本遠高於它擋下的風險。
 *
 * ⭐ 因此這裡是**單一的 model 寫入防線**：任何路徑（action、factory、
 * tinker、未來的新程式碼）建立 replacement 列時都會經過它。
 *
 * ⛔ 這一層**不取代** DB 的 unique／FK／shape guard，它們各自擋不同的東西：
 *  - DB unique：併發下的最終防線（兩個 transaction 同時通過應用層檢查）；
 *  - DB shape guard：單列的欄位組合；
 *  - 這一層：需要讀 parent 才能判斷的跨列一致性。
 */
class FulfillmentReplacementIntegrityObserver
{
    /**
     * ⛔ 必須與 parent 逐值一致的 provider 快照欄位。
     *
     * ⭐ 這是整個功能最危險的一條：若 child 可以帶著**不同的** service ID，
     * Owner 以為只是換個連結重送，實際上卻把訂單送去了另一個服務。
     */
    private const SNAPSHOT_COLUMNS = [
        'provider',
        'fulfillment_mapping_id',
        'provider_service_id_snapshot',
        'provider_service_name_snapshot',
        'payload_type_snapshot',
    ];

    public function creating(FulfillmentOrder $fulfillment): void
    {
        /*
         * ⭐ 明確補上 `sequence_no = 1`。
         *
         * ⛔ DB 的 default 只在 SQL 層生效——沒有明寫時，**記憶體中的 model**
         * 那個屬性是 null，直到有人 `refresh()`。而 `isReplacement()` 讀的
         * 正是它：一個剛建立、還沒 refresh 的初始列會讓 `sequence_no` 為
         * null，`effectiveTarget()`／`effectiveQuantity()` 的判斷因此落在
         * 一個未定義的狀態上。
         *
         * ⛔ 這裡只在**完全沒有指定**時補；有指定就尊重呼叫端（那才是
         * replacement 的路徑）。
         */
        if ($fulfillment->sequence_no === null) {
            $fulfillment->sequence_no = 1;
        }

        $this->assertShapeIsConsistent($fulfillment);
    }

    /**
     * ⛔ 更新時同樣檢查。
     *
     * 一筆合法建立的 child 之後被改成指向別的 parent，與一開始就建錯一樣糟。
     */
    public function updating(FulfillmentOrder $fulfillment): void
    {
        $this->assertShapeIsConsistent($fulfillment);
    }

    private function assertShapeIsConsistent(FulfillmentOrder $fulfillment): void
    {
        $sequence = (int) ($fulfillment->sequence_no ?? 1);
        $parentId = $fulfillment->replaces_fulfillment_order_id;

        /*
         * ⛔ sequence 必須至少是 1。
         *
         * DB 的 shape guard 也擋這件事；這裡再擋一次，是為了讓錯誤在**寫入
         * 之前**就以一個看得懂的訊息出現，而不是變成一個 SQL 例外。
         */
        if ($sequence < 1) {
            throw new RuntimeException('⛔ 履約批次的 sequence 必須至少為 1。');
        }

        // 初始批次：沒有 parent，也不該有任何更換欄位（DB guard 已擋）。
        if ($sequence === 1) {
            if ($parentId !== null) {
                throw new RuntimeException('⛔ 第 1 批履約不得指向 parent。');
            }

            return;
        }

        // ⛔ 從這裡開始是更換批次。
        if ($parentId === null) {
            throw new RuntimeException('⛔ 更換履約必須指向它取代的上一批。');
        }

        $parent = FulfillmentOrder::query()->find($parentId);

        if ($parent === null) {
            throw new RuntimeException('⛔ 找不到這筆更換履約的 parent。');
        }

        /*
         * ⛔⛔ 同一個商品項目。
         *
         * 跨商品的 child 會讓 A 商品的更換批次掛在 B 商品底下——鏈的兩端
         * 指向不同的東西，之後每一次讀取都會得到互相矛盾的答案。
         */
        if ((int) $fulfillment->order_item_id !== (int) $parent->order_item_id) {
            throw new RuntimeException('⛔ 更換履約必須與它的 parent 屬於同一個商品項目。');
        }

        /*
         * ⛔ sequence 必須恰為 parent + 1。
         *
         * 跳號會讓「第幾次更換」的計算失準（公開頁與時間線都用它），
         * 也讓 unique `(order_item_id, sequence_no)` 失去連續性的意義。
         */
        if ($sequence !== (int) $parent->sequence_no + 1) {
            throw new RuntimeException(
                '⛔ 更換履約的 sequence 必須恰為 parent + 1（parent='
                .$parent->sequence_no.'，收到 '.$sequence.'）。'
            );
        }

        // ⛔ provider 快照必須逐值一致。
        foreach (self::SNAPSHOT_COLUMNS as $column) {
            if (! $this->sameSnapshotValue($fulfillment->{$column}, $parent->{$column})) {
                throw new RuntimeException(
                    "⛔ 更換履約的供應商快照必須與 parent 完全一致（{$column} 不符）。"
                );
            }
        }
    }

    /**
     * ⛔ 逐值比較，但兩邊都先轉成可比較的純量。
     *
     * `payload_type_snapshot` 有 enum cast，`fulfillment_mapping_id` 可能是
     * int 或 null——直接 `!==` 會因為型別差異誤報。
     */
    private function sameSnapshotValue(mixed $a, mixed $b): bool
    {
        return $this->scalarise($a) === $this->scalarise($b);
    }

    private function scalarise(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }
}
