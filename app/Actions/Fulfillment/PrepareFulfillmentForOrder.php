<?php

namespace App\Actions\Fulfillment;

use App\Enums\FulfillmentAttentionReason;
use App\Enums\FulfillmentEventCode;
use App\Enums\FulfillmentStatus;
use App\Enums\IntegrationProvider;
use App\Models\FulfillmentMapping;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProviderService;
use App\Services\Fulfillment\FulfillmentDispatchGate;
use Illuminate\Support\Facades\DB;

/**
 * Create the one fulfilment row each item of a paid order gets.
 *
 * Idempotent by construction: `fulfillment_orders.order_item_id` is unique, so
 * a redelivered event, a retried job or two concurrent workers all converge on
 * the same row. The application checks first for a clearer path; the index is
 * what makes a second row impossible.
 *
 * ⛔ An unpaid order gets nothing at all. Not a row in a waiting state — no row.
 * A row that exists is a row someone can act on, and there is no version of
 * "dispatch it anyway" that is safe before the money has arrived.
 */
class PrepareFulfillmentForOrder
{
    /**
     * @return list<FulfillmentOrder> the rows that are ready to submit
     */
    public function handle(Order $order): array
    {
        if (! $order->isPaid()) {
            // ⛔ 未付款：完全不建立履約列。
            return [];
        }

        $ready = [];

        foreach ($order->items()->orderBy('id')->get() as $item) {
            $fulfillment = $this->prepareItem($item);

            if ($fulfillment?->status === FulfillmentStatus::Ready) {
                $ready[] = $fulfillment;
            }
        }

        return $ready;
    }

    private function prepareItem(OrderItem $item): ?FulfillmentOrder
    {
        $mapping = $this->mappingFor($item);

        return DB::transaction(function () use ($item, $mapping) {
            /*
             * ⛔ 只找**第 1 批**（sequence 1）。
             *
             * ⭐ 這個 action 回答的問題是「這個商品項目的**初始**履約建立過
             * 了嗎」。更換批次（sequence ≥ 2）是 Owner 事後另外建立的，
             * ⛔ 與這裡無關——用 `where('order_item_id', ...)` 撈任何一列，
             * 在有更換批次時會撈到鏈尾那一筆並把它當成「初始列已存在」回傳，
             * 語意就錯了。
             *
             * ⛔ 防重複派單的保證沒有被放寬：DB 的 unique
             * `(order_item_id, sequence_no)` 讓 sequence 1 只可能有一筆，
             * 與原本的單欄 unique 在初始派單上完全等價。
             */
            $existing = FulfillmentOrder::query()
                ->where('order_item_id', $item->id)
                ->where('sequence_no', 1)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                // ⛔ 已經有了就不再建立，也不覆寫既有狀態。
                return $existing;
            }

            $blocked = $this->blockingReason($mapping);

            $fulfillment = FulfillmentOrder::create([
                'order_item_id' => $item->id,
                'fulfillment_mapping_id' => $mapping?->id,
                'provider' => IntegrationProvider::TheMostPanel->value,
                'status' => $blocked === null
                    ? FulfillmentStatus::Ready
                    : FulfillmentStatus::ConfigurationPending,
                'attention_code' => $blocked,
                // 進入 ready 時凍結；⛔ 之後改 mapping 不得改動這一筆。
                'provider_service_id_snapshot' => $blocked === null ? $mapping->provider_service_id : null,
                'payload_type_snapshot' => $blocked === null ? $mapping->payload_type->value : null,
                /*
                 * ⛔ 同一刻凍結的顯示用名稱快照：與 id 快照同一組
                 * (provider, provider_service_id) exact key 查詢,不得只憑
                 * id 誤配到另一個 provider 的同號服務。查不到就是 null,
                 * 顯示層自行 fallback,不在這裡猜或留空字串。
                 */
                'provider_service_name_snapshot' => $blocked === null
                    ? $this->currentCatalogName($mapping->provider_service_id)
                    : null,
            ]);

            $fulfillment->recordEvent(
                FulfillmentEventCode::Created,
                to: FulfillmentStatus::ConfigurationPending,
            );

            if ($blocked === null) {
                $fulfillment->recordEvent(
                    FulfillmentEventCode::Ready,
                    from: FulfillmentStatus::ConfigurationPending,
                    to: FulfillmentStatus::Ready,
                );
            } else {
                $fulfillment->recordEvent(
                    FulfillmentEventCode::ConfigurationBlocked,
                    from: FulfillmentStatus::ConfigurationPending,
                    to: FulfillmentStatus::ConfigurationPending,
                );
            }

            return $fulfillment->fresh();
        });
    }

    /**
     * The catalog's current name for a service id, exact-key only.
     *
     * ⛔ Always TheMostPanel — the only provider this action ever creates
     * rows for. Never joins on id alone: a second provider could reuse the
     * same numeric id for a completely different service.
     */
    private function currentCatalogName(string $providerServiceId): ?string
    {
        return ProviderService::query()
            ->where('provider', IntegrationProvider::TheMostPanel->value)
            ->where('provider_service_id', $providerServiceId)
            ->value('name');
    }

    private function mappingFor(OrderItem $item): ?FulfillmentMapping
    {
        if ($item->service_variant_id === null) {
            return null;
        }

        return FulfillmentMapping::query()
            ->where('service_variant_id', $item->service_variant_id)
            ->where('provider', IntegrationProvider::TheMostPanel->value)
            ->first();
    }

    /**
     * Why this item cannot be dispatched, or null if it can.
     *
     * ⛔ Order matters only for the message an operator sees; every branch is
     * equally a refusal to send.
     */
    private function blockingReason(?FulfillmentMapping $mapping): ?FulfillmentAttentionReason
    {
        if ($mapping === null) {
            return FulfillmentAttentionReason::MappingMissing;
        }

        if (! $mapping->is_enabled) {
            return FulfillmentAttentionReason::MappingDisabled;
        }

        if (! $mapping->isUsable()) {
            return FulfillmentAttentionReason::UnsupportedPayload;
        }

        // ⛔ 最後才問總開關：mapping 正確與「可以送出」是兩件事。
        if (! FulfillmentDispatchGate::enabled()) {
            return FulfillmentAttentionReason::DispatchDisabled;
        }

        return null;
    }
}
