<?php

namespace App\Observers;

use App\Enums\FulfillmentStatus;
use App\Models\FulfillmentOrder;
use RuntimeException;

/**
 * Refuse an illegal status change at the model layer.
 *
 * ⛔ The database guard is the layer that cannot be bypassed; this one exists so
 * that ordinary application code fails with a message naming the transition
 * rather than a raw constraint violation. Both are needed — a clear error where
 * developers work, an unbypassable one underneath.
 *
 * The rule itself lives in `FulfillmentStatus::canTransitionTo()`, so this
 * observer and the migration cannot drift apart.
 */
class FulfillmentOrderIntegrityObserver
{
    public function updating(FulfillmentOrder $fulfillment): void
    {
        $this->assertStatusTransitionIsLegal($fulfillment);
        $this->assertSubmittedHasAnIdentifier($fulfillment);
    }

    public function saving(FulfillmentOrder $fulfillment): void
    {
        // 建立時也要檢查：⛔ 不得直接以 submitted 建立一筆沒有單號的列。
        if (! $fulfillment->exists) {
            $this->assertSubmittedHasAnIdentifier($fulfillment);
        }
    }

    private function assertStatusTransitionIsLegal(FulfillmentOrder $fulfillment): void
    {
        if (! $fulfillment->isDirty('status')) {
            return;
        }

        $from = $fulfillment->getOriginal('status');
        $to = $fulfillment->status;

        $from = $from instanceof FulfillmentStatus ? $from : FulfillmentStatus::tryFrom((string) $from);
        $to = $to instanceof FulfillmentStatus ? $to : FulfillmentStatus::tryFrom((string) $to);

        if ($from === null || $to === null) {
            throw new RuntimeException('⛔ 履約狀態不在允許清單中。');
        }

        if (! $from->canTransitionTo($to)) {
            throw new RuntimeException(
                "⛔ 不允許的履約狀態轉移：{$from->value} → {$to->value}。"
                .'已終止或已送出的紀錄不得回到送出前的狀態。'
            );
        }
    }

    /**
     * ⛔ A submitted row without an identifier can never be reconciled.
     *
     * It claims the order was placed while giving us nothing to ask the
     * provider about.
     */
    private function assertSubmittedHasAnIdentifier(FulfillmentOrder $fulfillment): void
    {
        if ($fulfillment->status !== FulfillmentStatus::Submitted) {
            return;
        }

        if (trim((string) $fulfillment->provider_order_id) === '') {
            throw new RuntimeException('⛔ 已送出的履約紀錄必須具備供應商單號。');
        }
    }
}
