<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Jobs\SendPaidOrderLineNotification;

/**
 * Queue the LINE notification for a newly-paid order.
 *
 * ⛔ 這個 listener 刻意什麼都不做，只 dispatch 一個 job。
 *
 * ⭐ 發票、履約與 LINE 通知是 `OrderPaid` 的**三條互相獨立的支線**。
 * listener 裡沒有任何邏輯，就沒有任何東西可以拋例外——因此這一條絕不可能
 * 把另外兩條一起帶下去。開關、credential、環境與訂單狀態的檢查全部在 job
 * 的 `handle()`，那裡是 queue 的邊界，失敗只影響它自己。
 *
 * ⛔ 不加 `ShouldQueue`：那會讓 listener 本身進 queue，變成「排一個工作去排
 * 一個工作」。既有的兩條支線都是同一個形狀。
 *
 * ⛔ 不在任何 ServiceProvider 手動註冊：Laravel 依 `handle()` 的型別自動探索
 * `app/Listeners`，再手動 listen 一次會讓同一張訂單排兩個工作。
 */
class ScheduleLineOrderNotificationForPaidOrder
{
    public function handle(OrderPaid $event): void
    {
        SendPaidOrderLineNotification::dispatch($event->order->id);
    }
}
