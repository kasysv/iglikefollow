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
 * ⛔ Staging only, default off. Production is refused unconditionally;
 * local never polls; the flag alone is not enough outside staging.
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
        if (app()->environment('production')) {
            return false;
        }

        // ⛔ 只允許 staging;local 與其他 environment 永遠 0。
        if (! app()->environment('staging')) {
            return false;
        }

        if (! (bool) config('fulfillment.status_polling_enabled', false)) {
            return false;
        }

        /*
         * ⛔ R1(P0-2):gateway capability gate 也必須成立。polling flag
         * 單獨打開時,排入的 jobs 只會走 Disabled gateway 灌一批
         * unrecognised events——所以 dispatch gate 不成立就一列都不排。
         */
        return FulfillmentDispatchGate::enabled();
    }
}
