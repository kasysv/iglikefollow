<?php

namespace App\Support;

use App\Enums\FulfillmentEventCode;
use App\Enums\FulfillmentStatus;
use App\Models\FulfillmentEvent;
use App\Models\Order;
use App\Models\OrderEvent;
use Illuminate\Support\Carbon;

/**
 * One merged, read-only timeline: order-level events plus every fulfilment
 * row's events, in one stable order.
 *
 * ⛔ Presenter only — never writes. `order_events` and `fulfillment_events`
 * are each append-only DB tables in their own right; merging them for display
 * must not create a third place events get written to. Reading twice must
 * yield the same order every time, so ties break on `id` after `created_at`,
 * with a fixed `source` tiebreak for the rare case both land in the same
 * database second at the same id (SQLite/MySQL autoincrement ids are
 * per-table, so two different tables' rows can share both).
 *
 * ⛔ Fulfilment entries never carry provider text — only the closed
 * `FulfillmentEventCode`/`FulfillmentStatus` enums already on the row, mapped
 * to fixed local Chinese phrases. Order entries reuse `OrderEvent::label()`,
 * which is the same safe summary already shown elsewhere in the admin.
 */
final class OrderActivityTimeline
{
    /** @return list<array{created_at: Carbon, source: string, label: string, smm_service_name: ?string}> */
    public static function for(Order $order): array
    {
        $orderEntries = OrderEvent::query()
            ->where('order_id', $order->id)
            ->get()
            ->map(fn (OrderEvent $event): array => [
                'created_at' => $event->created_at,
                'id' => $event->id,
                'source' => 'order',
                'label' => $event->label(),
                'smm_service_name' => null,
            ]);

        // ⛔ 明確指定表名:hasManyThrough join 後裸 `id` 在 order_items／
        // fulfillment_orders 兩邊都有,SQLite 會回報 ambiguous column。
        $fulfillmentOrderIds = $order->fulfillmentOrders()->pluck('fulfillment_orders.id');

        $fulfillmentEntries = FulfillmentEvent::query()
            ->whereIn('fulfillment_order_id', $fulfillmentOrderIds)
            ->with('fulfillmentOrder.orderItem')
            ->get()
            ->map(fn (FulfillmentEvent $event): array => [
                'created_at' => $event->created_at,
                'id' => $event->id,
                'source' => 'fulfillment',
                'label' => self::fulfillmentLabel($event),
                'smm_service_name' => $event->fulfillmentOrder?->displayServiceName(),
            ]);

        return $orderEntries->concat($fulfillmentEntries)
            // ⛔ 穩定排序:created_at → id → source,兩個不同表的 id 可能撞號。
            ->sortBy([
                ['created_at', 'asc'],
                ['id', 'asc'],
                ['source', 'asc'],
            ])
            ->values()
            ->map(fn (array $entry): array => [
                /*
                 * ⭐ 穩定且唯一的 key：`order:{id}` 或 `fulfillment:{id}`。
                 *
                 * 下方的時間線表格需要逐列 key。⛔ 不能用陣列索引——那會隨新
                 * 事件插入而整批位移，列狀態就會跳到別列；也不能單用 `id`，
                 * 因為兩個來源表的自增 id 會撞號。加上來源前綴之後，同一筆
                 * 事件在任何時候都對應同一個 key。
                 */
                'key' => $entry['source'].':'.$entry['id'],
                'created_at' => $entry['created_at'],
                'source' => $entry['source'],
                'label' => $entry['label'],
                'smm_service_name' => $entry['smm_service_name'],
            ])
            ->all();
    }

    /**
     * ⛔ 只用本地 enum 組出固定中文句子,不含任何 provider 原文。
     *
     * 施工單指定的三句對應:
     *   SUBMITTED                       → 已在 SMM 平台下單
     *   STATUS_SYNCED → processing      → SMM 平台已進行中
     *   STATUS_SYNCED → completed       → SMM 平台已完成
     * 其餘 partial／failed／canceled／unknown 依既有本地 enum 顯示。
     */
    private static function fulfillmentLabel(FulfillmentEvent $event): string
    {
        if ($event->event_code === FulfillmentEventCode::Submitted) {
            return '已在 SMM 平台下單';
        }

        if ($event->event_code === FulfillmentEventCode::StatusSynced) {
            return match ($event->to_status) {
                FulfillmentStatus::Processing => 'SMM 平台已進行中',
                FulfillmentStatus::Completed => 'SMM 平台已完成',
                FulfillmentStatus::Partial => 'SMM 平台部分完成',
                FulfillmentStatus::Canceled => 'SMM 平台已取消',
                FulfillmentStatus::Failed => 'SMM 平台回報失敗',
                FulfillmentStatus::SubmissionUnknown => 'SMM 平台結果不明（待人工對帳）',
                default => $event->event_code->label(),
            };
        }

        return $event->event_code->label();
    }
}
