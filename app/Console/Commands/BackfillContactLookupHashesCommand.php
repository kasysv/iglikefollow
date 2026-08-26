<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Support\ContactLookupHash;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Backfill lookup hashes for orders created before the columns existed.
 *
 * ⭐ 沒有這個 command，既有訂單一律查不到——`CreatePendingOrder` 只會為
 * **新**訂單寫入 hash。
 *
 * ⛔ 預設 dry-run：只有明確加上 `--apply` 才會寫入。一個會改動正式訂單資料
 * 的指令，不該因為有人手滑按了 enter 就執行。
 *
 * ⛔ 輸出**只有計數**：總數、待補數、成功數、失敗數。⛔ 絕不輸出 reference、
 * Email、手機或 hash——命令列輸出會進 CI log、螢幕截圖與終端機歷史。
 *
 * ⛔ Idempotent：只處理 hash 為 null 的列，重跑不會改寫已有的值。
 */
class BackfillContactLookupHashesCommand extends Command
{
    protected $signature = 'orders:backfill-lookup-hashes {--apply : 實際寫入；未指定時只做 dry-run}';

    protected $description = '為既有訂單補上 Email／手機的查詢用 HMAC（預設 dry-run）';

    /** ⛔ 分批處理，避免一次載入整張 orders 表。 */
    private const CHUNK = 200;

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $total = Order::query()->count();

        /*
         * ⭐ R1：待補的定義改為「算出來的 desired hash 與現值不同」。
         *
         * ⛔ 初版只看 `null`，有兩個問題：
         *
         *  1. 手機語意升到 v2 之後，曾跑過 A2 的訂單留著 v1 hash——它不是
         *     null，所以永遠不會被更新，那些客人永遠查不到自己的訂單。
         *  2. **沒有手機**的訂單 phone hash 恆為 null，於是每次執行都被列為
         *     pending，計數永遠不會歸零，看起來像有事沒做完。
         *
         * 因此改成逐列比對 desired 值——它同時解決兩者，也讓「第二次 apply
         * 為 0 change」成為可驗證的性質。
         */
        $pending = $this->countRowsNeedingUpdate();

        $this->line('訂單總數：'.$total);
        $this->line('待檢查：'.$pending);

        if (! $apply) {
            $this->warn('dry-run：未寫入任何資料。加上 --apply 才會實際執行。');

            return self::SUCCESS;
        }

        $updated = 0;
        $failed = 0;

        Order::query()->chunkById(self::CHUNK, function ($orders) use (&$updated, &$failed) {
            foreach ($orders as $order) {
                try {
                    /*
                     * ⛔ 每一列各自一個 transaction：一列的解密失敗不該讓
                     * 整批已成功的更新被回滾。
                     */
                    DB::transaction(function () use ($order, &$updated) {
                        $changes = $this->changesFor($order);

                        if ($changes === []) {
                            return;
                        }

                        $order->forceFill($changes)->save();
                        $updated++;
                    });
                } catch (Throwable) {
                    /*
                     * ⛔ 只計數，⛔ 不輸出 exception 訊息——它可能含有
                     * 買受人資料或 credential 片段。
                     */
                    $failed++;
                }
            }
        });

        $this->info('已更新：'.$updated);

        // 這行讓「第二次 apply 應為 0」成為可直接讀出的事實。
        $this->line('剩餘待更新：'.$this->countRowsNeedingUpdate());

        if ($failed > 0) {
            $this->error('失敗：'.$failed.'（資料異常或無法解密，未寫入）');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The columns that would actually change for this order.
     *
     * ⭐ 以「desired 值 vs 現值」判斷，⛔ 不是「現值是不是 null」。
     *
     * 這一點同時解決兩件事：手機語意升到 v2 之後，留著 v1 hash 的舊訂單會被
     * 更新；而**沒有手機**的訂單 desired 就是 null、現值也是 null，兩者相同，
     * ⛔ 因此不會每次都被算成待更新。
     *
     * ⛔ 讀取 `customer_email`／`customer_phone` 會觸發解密。解密失敗（例如
     * `APP_KEY` 換過）會拋出，由呼叫端計為 failed，⛔ 不猜值、不寫入部分結果。
     *
     * @return array<string, string|null>
     */
    private function changesFor(Order $order): array
    {
        $changes = [];

        $email = ContactLookupHash::forEmail($order->customer_email);

        if ($email !== $order->customer_email_lookup_hash) {
            $changes['customer_email_lookup_hash'] = $email;
        }

        $phone = ContactLookupHash::forPhone($order->customer_phone);

        if ($phone !== $order->customer_phone_lookup_hash) {
            $changes['customer_phone_lookup_hash'] = $phone;
        }

        return $changes;
    }

    /**
     * How many rows would `--apply` actually change.
     *
     * ⛔ 逐列計算（需要解密），因此用 chunk 而不是一次載入整張表。
     * ⛔ 解密失敗的列計入待更新——它們確實還沒到位，只是 apply 時會失敗；
     * 把它們算成「已完成」會讓計數說謊。
     */
    private function countRowsNeedingUpdate(): int
    {
        $count = 0;

        Order::query()->chunkById(self::CHUNK, function ($orders) use (&$count) {
            foreach ($orders as $order) {
                try {
                    if ($this->changesFor($order) !== []) {
                        $count++;
                    }
                } catch (Throwable) {
                    $count++;
                }
            }
        });

        return $count;
    }
}
