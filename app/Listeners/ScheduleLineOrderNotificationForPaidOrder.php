<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Jobs\SendPaidOrderLineNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queue the LINE notification for a newly-paid order.
 *
 * ⭐ 發票、履約與 LINE 通知是 `OrderPaid` 的**三條互相獨立的支線**。
 *
 * ⛔⛔ R1 修正：初版的註解宣稱「listener 裡沒有任何邏輯，就沒有任何東西
 * 可以拋例外」。**那個推論是錯的**——`dispatch()` 本身就會寫 queue：
 * database driver 要 INSERT 一列 `jobs`。DB 連線中斷、queue 表鎖死或磁碟
 * 滿的時候，它會拋 `QueryException`，而那個例外會沿著 event dispatcher
 * 往上冒泡，把**發票與履約**兩條支線一起帶下去。
 *
 * ⛔ 「很薄」不等於「不會拋」。因此這裡對唯一那行 dispatch 做最小的
 * `try/catch (Throwable)`：LINE 通知排不進去，絕不能讓付款後的另外兩條
 * 支線也停擺。
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
        try {
            SendPaidOrderLineNotification::dispatch($event->order->id);
        } catch (Throwable) {
            /*
             * ⛔ 只記一個固定 token，⛔ 不含 order ID、Email、電話、訊息內容、
             * 接收 ID 或 credential。
             *
             * ⛔ 連 order ID 都不記：這行 log 的用途是「有一則通知沒排進去」，
             * 而不是「是哪一張訂單」——後者要靠 queue 監控與 Owner 的實際
             * 收件狀況判斷，不值得為它在 log 裡留下可關聯的識別碼。
             *
             * ⛔ log 本身也包在 catch 裡：寫不進 log（磁碟滿、權限錯）同樣
             * 不得往上拋。這是 LINE Pay R1 的教訓——一個沒有隔離的
             * `Log::warning()` 曾讓付款收斂整個中斷。
             * ⛔ 必須 catch `Throwable` 而不是 `Exception`：Monolog 會拋 `Error`。
             */
            try {
                Log::warning('line_notification.dispatch_failed');
            } catch (Throwable) {
                // ⛔ 刻意留空：觀測性不得成為新的單點故障。
            }
        }
    }
}
