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
    /** 只有這兩個狀態值得查詢；其餘不是已終止就是還沒送出。 */
    private const SYNCABLE = [
        FulfillmentStatus::Submitted,
        FulfillmentStatus::Processing,
    ];

    public function __construct(private readonly FulfillmentGateway $gateway) {}

    public function handle(FulfillmentOrder $fulfillment): FulfillmentOrder
    {
        // 列可能已在別處被刪除；⛔ 沒有可同步的對象就原樣返回。
        $fulfillment = $fulfillment->fresh() ?? $fulfillment;

        if ($fulfillment->provider_order_id === null) {
            return $fulfillment;
        }

        // ⛔ 終止狀態一律 no-op：已完成的單不得被再次改寫。
        if (! in_array($fulfillment->status, self::SYNCABLE, true)) {
            return $fulfillment;
        }

        try {
            $result = $this->gateway->sync($fulfillment->provider_order_id);
        } catch (Throwable) {
            // ⛔ 查詢失敗不改狀態：這是唯讀操作，失敗代表「還不知道」。
            return $this->recordUnrecognised($fulfillment);
        }

        if (! $result->isRecognised()) {
            return $this->recordUnrecognised($fulfillment);
        }

        return $this->recordStatus($fulfillment, $result->status);
    }

    private function recordStatus(FulfillmentOrder $fulfillment, FulfillmentStatus $status): FulfillmentOrder
    {
        $from = $fulfillment->status;

        if ($status === $from) {
            // 狀態沒變：只更新查詢時間，不灌爆時間線。
            $fulfillment->forceFill(['last_synced_at' => now()])->save();

            return $fulfillment->fresh();
        }

        return DB::transaction(function () use ($fulfillment, $from, $status) {
            $fulfillment->forceFill([
                'status' => $status,
                'last_synced_at' => now(),
            ])->save();

            $fulfillment->recordEvent(FulfillmentEventCode::StatusSynced, from: $from, to: $status);

            return $fulfillment->fresh();
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
