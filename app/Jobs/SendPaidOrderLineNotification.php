<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Notifications\LineNotificationGate;
use App\Services\Notifications\LinePushClient;
use App\Services\Notifications\PaidOrderMessage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Push one LINE notification for one newly-paid order.
 *
 * ⛔⛔ 這條支線失敗，付款仍然成立。
 *
 * 通知是**事後告知**，不是交易的一部分。任何 dispatch 失敗、HTTP 錯誤、
 * 429、5xx、逾時或永久 4xx，都不得回滾或改寫 `payment_status`，也不得
 * 阻擋發票與派單——那兩條是各自獨立的 `OrderPaid` 支線。
 *
 * ⛔ payload 只有 `orderId`（int）。⛔ 不序列化 Order model、Email、電話、
 * 交付目標或 credential：queue payload 會以明文躺在 `jobs` 資料表裡，
 * 失敗時還會複製一份進 `failed_jobs`，而那兩張表的存取控制比訂單資料寬鬆。
 */
class SendPaidOrderLineNotification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * ⛔ 有限重試：429／5xx／transport 才值得再試一次。
     *
     * ⛔ 與既有 worker 設定一致（`--tries=3 --timeout=60`、DB queue
     * `retry_after=90`）：在這裡寫一個更大的數字，只會讓 worker 的設定與
     * job 的期望互相矛盾。
     */
    public int $tries = 3;

    /**
     * ⛔ 同一張訂單在 5 分鐘內只會有一個這種工作。
     *
     * 可信 callback 可能重複送達（金流商本來就會重送）。少了這個，
     * 每一次重複 callback 都會變成 Owner 手機上的另一則通知。
     */
    public int $uniqueFor = 300;

    public function __construct(public readonly int $orderId) {}

    public function uniqueId(): string
    {
        return 'line-order-notification-'.$this->orderId;
    }

    public function handle(LinePushClient $client): void
    {
        /*
         * ⛔ 重新從 DB 讀訂單，⛔ 不信任 dispatch 當下的狀態。
         *
         * 這個工作可能在 queue 裡等了一段時間。期間訂單可能被退款或改狀態；
         * 一則「新訂單」通知在那之後才送出是誤導。
         */
        $order = Order::query()->with('items')->find($this->orderId);

        if ($order === null || ! $order->isPaid()) {
            return;
        }

        /*
         * ⛔ 執行時**重新**檢查開關、credential 與環境。
         *
         * gate 每次都重讀 DB，所以長駐 worker 會看到 Owner 現在的設定，
         * 而不是它啟動當下的設定。
         */
        $setting = LineNotificationGate::setting();

        if ($setting === null) {
            // ⛔ 靜默結束：這不是錯誤，是「現在不該送」。不重試、不拋例外。
            return;
        }

        $outcome = $client->push(
            $setting,
            PaidOrderMessage::for($order),
            self::retryKeyFor($order),
        );

        /*
         * ⛔ 只有「可重試」的結果才 release 回 queue。
         *
         * 永久 4xx（token 錯、接收 ID 錯、payload 不合法）重試同一份內容只會
         * 得到同一個答案，還會消耗 LINE 的配額——那需要 Owner 去改設定，
         * 不是讓 queue 一直撞牆。
         *
         * ⛔ 重試會帶著**同一個** retry key，所以「其實已經送達」的情況
         * 不會變成第二則訊息。
         */
        if ($outcome->retryable && $this->attempts() < $this->tries) {
            /*
             * ⛔ 退避後再試，⛔ 不立刻重送。
             *
             * 429 代表「太快」；立刻重試只會再撞一次限流。
             */
            $this->release(30 * $this->attempts());
        }

        /*
         * ⛔ 不可重試的失敗**不拋例外**：直接結束。
         *
         * 拋例外會讓這個工作進 `failed_jobs`，而 failed job payload 會被保存
         * 下來供人檢視——我們不希望「送給 Owner 的訂單通知失敗」變成一筆
         * 長期存在、任何能讀 DB 的人都看得到的紀錄。失敗原因已由 client
         * 以 allowlist token 記進 log。
         */
    }

    /**
     * A stable retry key for this order.
     *
     * ⛔⛔ 同一張訂單的每一次 retry 都必須得到**同一個值**。
     *
     * LINE 保證：帶同一個 retry key 的請求只會被執行一次，之後的重試回 409
     * 而不會重複送達。若這裡改用隨機 UUID，一次 timeout 後的重試就會被 LINE
     * 當成一則全新訊息——Owner 收到兩則相同通知。
     *
     * ⛔ 因此用 `APP_KEY` 的 domain-separated HMAC 由 order id 推導：
     * 對同一張訂單永遠相同，且不可被外部猜到（雖然 retry key 本身不是秘密，
     * 用可預測的值仍會讓別人有機會搶先佔用一個 key）。
     *
     * ⛔ 格式必須是合法 UUID（官方要求十六進位 UUID 格式）。這裡取 HMAC 的
     * 前 32 個十六進位字元排成 8-4-4-4-12，並依 RFC 4122 設定 version（4）
     * 與 variant 位元——⛔ 不是隨便插連字號，那會產生一個形狀像 UUID
     * 但 version 欄位不合法的值。
     */
    public static function retryKeyFor(Order $order): string
    {
        $digest = hash_hmac(
            'sha256',
            'iglikefollow.line-order-notification.retry-key.v1|'.$order->id,
            (string) config('app.key'),
        );

        $hex = substr($digest, 0, 32);

        // version 4
        $hex = substr($hex, 0, 12).'4'.substr($hex, 13);

        // variant 10xx：第 17 個十六進位字元必須是 8／9／a／b
        $variant = ['8', '9', 'a', 'b'][hexdec($hex[16]) % 4];
        $hex = substr($hex, 0, 16).$variant.substr($hex, 17);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
