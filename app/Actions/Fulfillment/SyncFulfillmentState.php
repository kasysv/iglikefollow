<?php

namespace App\Actions\Fulfillment;

use App\Contracts\FulfillmentGateway;
use App\Data\Fulfillment\FulfillmentSyncResult;
use App\Enums\FulfillmentEventCode;
use App\Enums\FulfillmentStatus;
use App\Models\FulfillmentOrder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ask the provider what became of an order we know exists.
 *
 * ⛔ Read-only, and only for rows that already carry a provider order id. A row
 * without one has nothing to ask about, and asking would mean guessing at an
 * identifier.
 *
 * ⛔ An unrecognised status never becomes `completed`. Providers add status
 * values over time, and rounding an unknown one up would close an order that is
 * still running — or one that failed and needs someone to look at it. The row
 * keeps its current state and the timeline records that we could not read the
 * answer.
 */
class SyncFulfillmentState
{
    public function __construct(private readonly FulfillmentGateway $gateway) {}

    public function handle(FulfillmentOrder $fulfillment): FulfillmentOrder
    {
        // 列可能已在別處被刪除；⛔ 沒有可同步的對象就原樣返回。
        $fulfillment = $fulfillment->fresh() ?? $fulfillment;

        if ($fulfillment->provider_order_id === null) {
            return $fulfillment;
        }

        // ⛔ 終止狀態一律 no-op：已完成的單不得被再次改寫。
        if (! in_array($fulfillment->status, FulfillmentStatus::syncableSources(), true)) {
            return $fulfillment;
        }

        try {
            $result = $this->gateway->sync($fulfillment->provider_order_id);
        } catch (Throwable) {
            // ⛔ 查詢失敗不改狀態：這是唯讀操作，失敗代表「還不知道」。
            return $this->recordUnrecognised($fulfillment);
        }

        /*
         * ⛔ 對方只可能回報這幾種狀態。
         *
         * `ready`、`submitting`、`configuration_pending`、`submission_unknown`
         * 都是我們描述自己處境的詞，任何供應商都不可能回報它們。接受其中之一
         * 會讓一個畸形回應把已送出的列倒退回可再次送出的狀態——那就是同一筆
         * 商品被下第二次單的路徑。
         */
        if (
            ! $result->isRecognised()
            || ! in_array($result->status, FulfillmentStatus::syncableTargets(), true)
        ) {
            return $this->recordUnrecognised($fulfillment);
        }

        return $this->recordStatus($fulfillment, $result);
    }

    /**
     * Write the new status, re-checking under a lock.
     *
     * ⛔ The row is read again inside the transaction. This worker may have been
     * waiting on a slow provider while another finished the job; without the
     * re-check its stale answer would overwrite a terminal state that was
     * decided after it asked.
     */
    private function recordStatus(FulfillmentOrder $fulfillment, FulfillmentSyncResult $result): FulfillmentOrder
    {
        $status = $result->status;

        return DB::transaction(function () use ($fulfillment, $result, $status) {
            $locked = FulfillmentOrder::query()
                ->whereKey($fulfillment->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return $fulfillment;
            }

            $from = $locked->status;

            // 期間狀態已經變了，且新答案不再合法：⛔ 不覆蓋，留下紀錄就好。
            if (! $from->canTransitionTo($status)) {
                // ⛔ 已經持有 lock，直接用鎖定後的現況寫，不再鎖一次。
                return $this->writeUnrecognised($locked);
            }

            /*
             * ⭐ provider 原文與 remains 與 `last_synced_at` 在**同一個 lock
             * 與 transaction** 內一起保存。
             *
             * ⛔ 分開寫會出現「狀態已更新但原文還是上一次」的中間狀態，而後台
             * 正是靠這兩個值一起判讀這張單現在的樣子。
             */
            $changes = array_merge(
                ['last_synced_at' => now()],
                $this->providerFields($locked, $result),
            );

            if ($from === $status) {
                /*
                 * 內部狀態沒變：只更新查詢時間與 provider 欄位，⛔ 不寫事件。
                 *
                 * ⭐ 但原文與 remains 仍然會被更新——同一個內部狀態底下
                 * provider token 可能改變（例如 `In progress` → `processing`），
                 * remains 更是每次都可能不同。⛔ 這不該產生狀態事件：時間線
                 * 記錄的是狀態轉換，不是每十分鐘一筆的數字變化。
                 */
                $locked->forceFill($changes)->save();

                return $locked->fresh();
            }

            $locked->forceFill(array_merge($changes, ['status' => $status]))->save();

            $locked->recordEvent(FulfillmentEventCode::StatusSynced, from: $from, to: $status);

            return $locked->fresh();
        });
    }

    /**
     * The provider-supplied columns to write, if this answer carried them.
     *
     * ⛔ 缺少或不合法的值**不出現在回傳陣列裡**，因此不會覆蓋既有欄位。
     * 這是刻意的：一次沒帶 remains 的回應，不該讓後台失去上一次正確的數字。
     *
     * ⛔ `0` 會被保存（`!== null` 而非 truthy 判斷）——它代表全部補完，
     * 與「還沒取得」是兩件不同的事。
     *
     * @return array<string, mixed>
     */
    private function providerFields(FulfillmentOrder $locked, FulfillmentSyncResult $result): array
    {
        $fields = [];

        if ($result->providerStatusToken !== null) {
            $fields['provider_status_code'] = $result->providerStatusToken;
        }

        if ($result->remains !== null) {
            $fields['provider_remains'] = $result->remains;
        }

        if ($result->startCount !== null) {
            $fields['provider_start_count'] = $result->startCount;
        }

        return $fields;
    }

    /**
     * Record that we could not read the answer — against the current row.
     *
     * ⛔ Re-reads under a lock, exactly like `recordStatus()`. This worker may
     * have spent the provider call waiting while another worker moved the row
     * on. Writing `from`/`to` from the model it was holding would append an
     * event saying `submitted → submitted` to a row that is already
     * `completed` — and the timeline is append-only, so that wrong entry can
     * never be corrected.
     *
     * The status itself was never at risk here; the damage is to the evidence.
     * A person reconciling a `submission_unknown` row reads this timeline to
     * decide what actually happened, so an entry describing a state the row was
     * not in is worse than no entry at all.
     */
    private function recordUnrecognised(FulfillmentOrder $fulfillment): FulfillmentOrder
    {
        return DB::transaction(function () use ($fulfillment) {
            $locked = FulfillmentOrder::query()
                ->whereKey($fulfillment->getKey())
                ->lockForUpdate()
                ->first();

            // 列已經不存在：⛔ 安全 no-op，不寫任何事件。
            if ($locked === null) {
                return $fulfillment;
            }

            return $this->writeUnrecognised($locked);
        });
    }

    /**
     * The write itself, for a row whose lock the caller already holds.
     *
     * ⛔ Takes the locked row, never a stale one. Split out so that
     * `recordStatus()` — which is already inside the transaction — can reuse it
     * without taking a second lock or duplicating the rule.
     */
    private function writeUnrecognised(FulfillmentOrder $locked): FulfillmentOrder
    {
        $locked->forceFill(['last_synced_at' => now()])->save();

        /*
         * ⛔ 狀態維持不變，from 與 to 都用 lock 之後的現況。
         *
         * 終止狀態不會被改寫；這筆事件只記錄「這一次我們讀不懂對方的回應」，
         * 而不是宣稱這一列當時在哪個狀態。
         */
        $locked->recordEvent(
            FulfillmentEventCode::StatusUnrecognised,
            from: $locked->status,
            to: $locked->status,
        );

        return $locked->fresh();
    }
}
