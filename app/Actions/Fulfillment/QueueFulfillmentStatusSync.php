<?php

namespace App\Actions\Fulfillment;

use App\Enums\FulfillmentStatus;
use App\Jobs\SyncFulfillmentStatus;
use App\Models\FulfillmentOrder;
use App\Services\Fulfillment\FulfillmentDispatchGate;

/**
 * Pick the fulfilment rows worth asking about, and queue one sync job each.
 *
 * ⛔ This action never calls the provider itself — it only dispatches the
 * existing `SyncFulfillmentStatus` jobs, which are unique per row. And it can
 * never resend an `add`: nothing here touches submission, and every selected
 * row already has a provider order id.
 *
 * ⛔ Eligibility is a closed list. Only `submitted` and `processing` rows
 * with a provider order id are askable. Terminal states (completed, partial,
 * canceled, failed) are done; `submission_unknown` needs a human, not a
 * poller; configuration_pending / ready / submitting have nothing to ask
 * about. Polling must never quietly "resolve" what a person should decide.
 *
 * ⛔ R1:staging／production 皆可,依 Owner 的自動派單總開關啟停——同一個
 * 開關,不再有獨立的 polling env 旗標。local／testing 永遠排入 0。
 */
class QueueFulfillmentStatusSync
{
    /** ⛔ 只有這兩個狀態可查:有 provider ID 且仍在進行。 */
    private const SYNCABLE_STATUSES = [
        FulfillmentStatus::Submitted,
        FulfillmentStatus::Processing,
    ];

    /** @return int 本輪排入的 job 數(gate 關閉時恆為 0) */
    public function handle(): int
    {
        if (! self::enabled()) {
            return 0;
        }

        $ids = FulfillmentOrder::query()
            ->whereNotNull('provider_order_id')
            ->whereIn('status', self::SYNCABLE_STATUSES)
            // 穩定排序＋固定上限:每輪可預期,不由後台輸入。
            ->orderBy('id')
            ->limit((int) config('fulfillment.status_polling_batch_limit', 50))
            ->pluck('id');

        foreach ($ids as $id) {
            // job 本身 ShouldBeUnique:同一列同時只會有一個在 queue。
            SyncFulfillmentStatus::dispatch($id);
        }

        return $ids->count();
    }

    public static function enabled(): bool
    {
        /*
         * ⛔ R1:輪詢跟隨自動派單總開關,不再有獨立的 env 旗標。
         *
         * Owner 打開「自動派單」,輪詢就開始;關掉,下一輪排入 0。兩個開關
         * 分開的舊設計,結果會是訂單派出去了、狀態卻永遠不更新——一個看起來
         * 卡住的系統,和一個真的卡住的系統,對 Owner 沒有差別。
         *
         * ⛔ 只允許 staging／production:local／testing 沒有 live gateway,
         * 排入的 jobs 只會灌一批 unrecognised events。gate 在 testing 的
         * fake 路徑會回 true——那是給派單測試用的;輪詢測試應明確切到
         * staging 再測,所以這裡另加環境判斷。
         */
        if (! app()->environment('staging', 'production')) {
            return false;
        }

        return FulfillmentDispatchGate::enabled();
    }
}
