<?php

namespace App\Actions\Fulfillment;

use App\Contracts\FulfillmentGateway;
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

        return $this->recordStatus($fulfillment, $result->status);
    }

    /**
     * Write the new status, re-checking under a lock.
     *
     * ⛔ The row is read again inside the transaction. This worker may have been
     * waiting on a slow provider while another finished the job; without the
     * re-check its stale answer would overwrite a terminal state that was
     * decided after it asked.
     */
    private function recordStatus(FulfillmentOrder $fulfillment, FulfillmentStatus $status): FulfillmentOrder
    {
        return DB::transaction(function () use ($fulfillment, $status) {
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
                return $this->recordUnrecognised($locked);
            }

            if ($from === $status) {
                // 狀態沒變：只更新查詢時間，不灌爆時間線。
                $locked->forceFill(['last_synced_at' => now()])->save();

                return $locked->fresh();
            }

            $locked->forceFill([
                'status' => $status,
                'last_synced_at' => now(),
            ])->save();

            $locked->recordEvent(FulfillmentEventCode::StatusSynced, from: $from, to: $status);

            return $locked->fresh();
        });
    }

    private function recordUnrecognised(FulfillmentOrder $fulfillment): FulfillmentOrder
    {
        return DB::transaction(function () use ($fulfillment) {
            $fulfillment->forceFill(['last_synced_at' => now()])->save();

            // ⛔ 狀態維持不變，只留下「讀不懂」這件事本身。
            $fulfillment->recordEvent(
                FulfillmentEventCode::StatusUnrecognised,
                from: $fulfillment->status,
                to: $fulfillment->status,
            );

            return $fulfillment->fresh();
        });
    }
}
