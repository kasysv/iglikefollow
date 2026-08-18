<?php

namespace App\Support;

use App\Enums\FulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Order;

/**
 * The four independent lanes of one order — order, payment, invoice,
 * fulfilment — as safe, deterministic display strings.
 *
 * ⛔ Read-only, and honest about absence: "尚未建立" means the record does
 * not exist, never "it failed" and never "it succeeded". Multiple attempts
 * follow explicit rules — any succeeded payment wins, otherwise the latest
 * attempt by id speaks; mixed fulfilment rows are broken down per status and
 * are labelled fully complete ONLY when every row is completed.
 *
 * ⛔ No provider service id, no provider order id, no target, no secret —
 * this summary renders for editors as well as the Owner.
 */
final class OrderOperationsSummary
{
    /** @return array{order: string, payment: string, invoice: string, fulfillment: string} */
    public static function for(Order $order): array
    {
        return [
            'order' => self::orderLane($order),
            'payment' => self::paymentLane($order),
            'invoice' => self::invoiceLane($order),
            'fulfillment' => self::fulfillmentLane($order),
        ];
    }

    private static function orderLane(Order $order): string
    {
        $line = $order->order_status->label();

        if ($order->paid_at !== null) {
            $line .= ';付款完成於 '.$order->paid_at->format('Y-m-d H:i');
        }

        return $line;
    }

    private static function paymentLane(Order $order): string
    {
        $attempts = $order->paymentAttempts()->orderBy('id')->get();

        if ($attempts->isEmpty()) {
            return '尚未建立';
        }

        $succeeded = $attempts->filter(fn ($attempt) => $attempt->status === PaymentStatus::Succeeded);

        // ⛔ 明確規則:有任何成功以成功為準;否則最新一筆(id 最大)發言。
        if ($succeeded->isNotEmpty()) {
            return '已成功('.$succeeded->count().'/'.$attempts->count().' 次嘗試)';
        }

        return '最新嘗試:'.$attempts->last()->status->label().'(共 '.$attempts->count().' 次)';
    }

    private static function invoiceLane(Order $order): string
    {
        $invoices = Invoice::query()->where('order_id', $order->id)->orderBy('id')->get();

        if ($invoices->isEmpty()) {
            return '尚未建立';
        }

        // ⛔ 同一規則:最新一筆(id 最大)發言;多筆時標示總數。
        $latest = $invoices->last();

        return $latest->status->label()
            .($invoices->count() > 1 ? '(最新;共 '.$invoices->count().' 筆)' : '');
    }

    private static function fulfillmentLane(Order $order): string
    {
        $rows = $order->fulfillmentOrders()->get();

        if ($rows->isEmpty()) {
            return '尚未建立';
        }

        $total = $rows->count();
        $completed = $rows->filter(fn ($row) => $row->status === FulfillmentStatus::Completed)->count();

        // ⛔ 只有每一筆都 completed 才可說全部完成。
        if ($completed === $total) {
            return '全部完成('.$total.'/'.$total.')';
        }

        // mixed:逐狀態列出(依 enum case 順序,deterministic)。
        $parts = [];

        foreach (FulfillmentStatus::cases() as $status) {
            $count = $rows->filter(fn ($row) => $row->status === $status)->count();

            if ($count > 0) {
                $parts[] = $status->label().' '.$count;
            }
        }

        return '共 '.$total.' 筆:'.implode('、', $parts);
    }
}
