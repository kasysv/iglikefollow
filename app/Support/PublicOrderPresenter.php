<?php

namespace App\Support;

use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Models\FulfillmentOrder;
use App\Models\Order;
use App\Models\OrderItem;

/**
 * What a customer may see about their own order — an allowlist, not a filter.
 *
 * ⭐ 這是本輪最重要的安全邊界。公開查詢結果只准出現這些欄位：
 *
 *  - 本站訂單編號
 *  - 平台、服務、方案、購買數量
 *  - 客戶看得懂的狀態
 *  - 剩餘數量
 *
 * ⛔ **絕不輸出**：SMM／TheMostPanel 字樣、provider order ID、provider service
 * name／ID、raw status token、credential、API 資料、交付帳號／URL、Email、
 * 手機、付款技術細節、履約事件、內部 attention code。
 *
 * ⛔ 這裡刻意用「明確列出要輸出什麼」而不是「把不要的濾掉」。過濾式的作法
 * 在新增欄位時預設是**洩漏**：日後有人在 `OrderItem` 加一個欄位，過濾清單
 * 沒更新就直接出現在公開頁。allowlist 的預設是不輸出。
 *
 * ⛔ 也刻意不重用後台的 presenter：後台可以顯示 provider 原文，公開端不行。
 * 共用一份就等於一次改動同時影響兩邊，而其中一邊是給陌生人看的。
 */
final class PublicOrderPresenter
{
    /**
     * ⛔ R1：移除 `placed_at`。
     *
     * 下單時間看起來無害，但它**不在 Owner 批准的欄位清單內**。公開輸出的
     * 判準是「批准了什麼」，不是「這個看起來還好吧」——後者正是 allowlist
     * 會逐漸擴張、最後洩漏東西的方式。
     *
     * @return array{reference: string, items: list<array<string, mixed>>}
     */
    public static function for(Order $order): array
    {
        return [
            'reference' => (string) $order->reference,
            'items' => $order->items
                ->map(fn (OrderItem $item): array => self::item($order, $item))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(Order $order, OrderItem $item): array
    {
        /*
         * ⛔ 履約列可能不存在（尚未付款、或付款後還沒建立）。
         *
         * `OrderItem::fulfillmentOrder()` 是 HasOne——一個商品項目至多一筆
         * 履約列。⛔ 不存在時是 null，呼叫端必須處理，不得假設有值。
         */
        $fulfillment = $item->fulfillmentOrder;

        return [
            // ⛔ 全部來自本站訂單快照，不是 provider 資料。
            'platform' => (string) $item->platform_name,
            'service' => (string) $item->service_name,
            'variant' => (string) $item->variant_label,
            'quantity' => (int) $item->quantity,
            'status' => self::status($order, $fulfillment),
            'remains' => self::remains($fulfillment),
        ];
    }

    /**
     * The customer-facing status.
     *
     * ⛔ **只由本站 enum 推導**，⛔ 絕不顯示 `provider_status_code`。原文是
     * 給客服對照 SMM 後台用的；對客人來說 `In progress` 既看不懂，也洩漏了
     * 我們用哪一家供應商。
     *
     * ⛔ 例外狀態一律顯示「請聯絡客服」，⛔ 不冒充「進行中」：
     *
     *  - `Partial`：部分完成，客人可能少拿了數量，需要人處理。
     *  - `Canceled`／`Failed`：這張單不會自己好起來。
     *  - `SubmissionUnknown`：結果不明，需要人工對帳。
     *
     * 把這四種顯示成「進行中」會讓客人一直等一個永遠不會到的結果——那比說
     * 「請聯絡客服」糟糕得多。
     */
    private static function status(Order $order, ?FulfillmentOrder $fulfillment): string
    {
        // ⛔ 還沒付款：顯示本站真實狀態，不假裝已經在跑。
        if (! $order->isPaid()) {
            return match ($order->order_status) {
                OrderStatus::PendingPayment => '等待付款',
                OrderStatus::Canceled => '請聯絡客服',
                default => '請聯絡客服',
            };
        }

        // 已付款但尚未建立履約列：誠實說「準備中」。
        if ($fulfillment === null) {
            return '準備中';
        }

        /*
         * ⛔ R1：**逐一窮舉** enum，⛔ 不用會把未知狀態默認成「進行中」的
         * `default`。
         *
         * 初版的 `default => '進行中'` 有兩個問題：`ConfigurationPending`
         * （mapping／開關／payload 尚未就緒，**根本還沒開始履約**）被誤報成
         * 進行中；而日後新增任何狀態也會自動被歸進「進行中」——一個安全預設
         * 應該是相反方向。
         */
        return match ($fulfillment->status) {
            FulfillmentStatus::Completed => '已完成',

            // 真正在跑的四個狀態。
            FulfillmentStatus::Ready,
            FulfillmentStatus::Submitting,
            FulfillmentStatus::Submitted,
            FulfillmentStatus::Processing => '進行中',

            /*
             * ⛔ 需要人處理的五種狀態，統一「請聯絡客服」。
             *
             * 不細分原因：對客人來說下一步都是聯絡客服，而細分會洩漏我們與
             * 供應商之間發生了什麼。
             *
             * ⭐ `ConfigurationPending` 屬於這一類：它代表設定沒就緒，客人
             * 再等也不會好。
             */
            FulfillmentStatus::Partial,
            FulfillmentStatus::Canceled,
            FulfillmentStatus::Failed,
            FulfillmentStatus::SubmissionUnknown,
            FulfillmentStatus::ConfigurationPending => '請聯絡客服',
        };
    }

    /**
     * The remaining count, or a placeholder.
     *
     * ⛔ 只讀**已驗證落盤**的 `provider_remains`。
     *
     * ⛔ 不推算、不用購買數量代替：那會給客人一個看起來精確、實際上是我們
     * 編出來的數字。`null` 就誠實說「更新中」——那是真話（排程還沒問到）。
     *
     * ⛔ `0` 顯示 `0`（全部補完），不被 placeholder 吞掉。
     */
    private static function remains(?FulfillmentOrder $fulfillment): string
    {
        if ($fulfillment === null || $fulfillment->provider_remains === null) {
            return '更新中';
        }

        return number_format($fulfillment->provider_remains);
    }
}
