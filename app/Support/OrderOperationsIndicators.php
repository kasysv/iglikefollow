<?php

namespace App\Support;

use App\Enums\FulfillmentStatus;
use App\Enums\InvoiceStatus;
use App\Models\FulfillmentOrder;
use App\Models\Order;

/**
 * The two at-a-glance columns on the order list: 發票 and SMM.
 *
 * ⛔⛔ 這個 presenter 只讀**已經保存的狀態**，⛔ 不查 provider、⛔ 不推定、
 * ⛔ 不寫入任何東西。它回答的是「我們目前手上的紀錄怎麼說」，
 * ⛔ 不是「供應商現在的實際情形」。
 *
 * ⭐ 為什麼把判斷放在這裡，而不是寫成 table 欄位裡的 closure：
 * 這幾條規則有真正的邊界情形（多商品、只有部分送出、latest 是 Partial／
 * Canceled、舊批次有單號但**最新**批次還沒送出），⛔ 而 closure 很難單獨測。
 * 放在 presenter 才能逐條反證。
 */
final class OrderOperationsIndicators
{
    /**
     * ⛔ 封閉的 Filament color token，⛔ 不是 CSS class、⛔ 不是 DB 值。
     *
     * ⭐ Blade／Filament 只把它當成 token 用；任何資料庫或 provider 的字串
     * 都不會經由這裡變成 HTML 屬性。
     */
    public const WARNING = 'warning';

    public const DANGER = 'danger';

    /**
     * ⛔⛔ 只有「最新一張發票確實是 Issued」才算開立完成。
     *
     * ⭐ `Order::invoice()` 已經是 `latestOfMany()`，所以這裡讀到的就是最新
     * 那一張。⛔ 無 invoice、待開立、開立中、失敗、需人工對帳、已作廢
     * 一律不算——它們都還沒有一張真正開出去的發票。
     */
    public static function invoiceIssued(Order $order): bool
    {
        return $order->invoice?->status === InvoiceStatus::Issued;
    }

    /**
     * The SMM column's state for one order.
     *
     * ⛔⛔ 這一欄**不得只用勾叉蓋掉例外狀態**。Owner 的要求是：
     * 平常不露文字，但 `Partial`／`Canceled` 必須看得見，
     * ⛔ 而且不能被「其他商品還沒送出」的叉蓋掉，反之亦然。
     *
     * ⭐ 因此回傳的是一組**可以同時成立**的旗標，⛔ 不是單一狀態：
     *
     *  - `partial`／`canceled`：分別對應黃色與紅色三角形驚嘆號；
     *  - `pending`：另有商品的 latest 批次尚未取得 `provider_order_id`，顯示叉；
     *  - `allSubmitted`：三者皆無時才顯示勾。
     *
     * ⛔⛔ 一律以每個商品的**最新**批次判定。
     * ⭐ 這是最容易錯的一點：舊批次可能有單號，但 Owner 剛建立的更換批次
     * 還沒派出去——那個商品**現在**是沒送出的。讀舊批次會把它誤報成勾。
     *
     * @return array{partial: bool, canceled: bool, pending: bool, allSubmitted: bool}
     */
    public static function smm(Order $order): array
    {
        $partial = false;
        $canceled = false;
        $pending = false;
        $items = 0;

        foreach ($order->items as $item) {
            $items++;

            /*
             * ⛔ `latestFulfillmentOrder` 是 `ofMany('sequence_no', 'max')`
             * ——鏈尾，也就是這個商品**現在**的履約。
             */
            $latest = $item->latestFulfillmentOrder;

            if (! $latest instanceof FulfillmentOrder) {
                // ⛔ 完全還沒建立履約：這個商品沒送出。
                $pending = true;

                continue;
            }

            $partial = $partial || $latest->status === FulfillmentStatus::Partial;
            $canceled = $canceled || $latest->status === FulfillmentStatus::Canceled;

            /*
             * ⛔ 「有沒有送出」只看 `provider_order_id` 是否為非空字串。
             *
             * ⭐ 那個 id 是對方接受過的證據；⛔ 內部 status 不能替代它
             * （狀態可能因為同步而變動，但單號一旦拿到就是事實）。
             */
            if (! filled($latest->provider_order_id)) {
                $pending = true;
            }
        }

        /*
         * ⛔ 一張沒有任何商品的訂單不算「全部送出」——那是資料異常，
         * ⛔ 不該顯示成一個令人安心的勾。
         */
        $allSubmitted = $items > 0 && ! $partial && ! $canceled && ! $pending;

        return [
            'partial' => $partial,
            'canceled' => $canceled,
            'pending' => $pending,
            'allSubmitted' => $allSubmitted,
        ];
    }

    /**
     * The exact provider-facing token shown only inside a tooltip／aria-label.
     *
     * ⛔⛔ 這兩個字串是**寫死的字面值**，⛔ 不是把 DB 或 provider 回應接進來。
     * ⭐ 它們剛好與 TheMostPanel 的 exact token 同字，但來源是我們自己的
     * 封閉 enum 分支——⛔ 任何時候都不會有第三個值從這裡跑出來。
     */
    public static function partialToken(): string
    {
        return 'Partial';
    }

    public static function canceledToken(): string
    {
        return 'Canceled';
    }
}
