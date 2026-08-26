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
         * ⛔ 待補的定義：兩個 hash 至少有一個是 null。
         *
         * 手機是選填欄位，所以「phone hash 為 null」不一定代表待補——下面
         * 逐列處理時會再判斷該列是否真的有手機。
         */
        $pending = Order::query()
            ->whereNull('customer_email_lookup_hash')
            ->orWhereNull('customer_phone_lookup_hash')
            ->count();

        $this->line('訂單總數：'.$total);
        $this->line('待檢查：'.$pending);

        if (! $apply) {
            $this->warn('dry-run：未寫入任何資料。加上 --apply 才會實際執行。');

            return self::SUCCESS;
        }

        $updated = 0;
        $failed = 0;

        Order::query()
            ->where(function ($query) {
                $query->whereNull('customer_email_lookup_hash')
                    ->orWhereNull('customer_phone_lookup_hash');
            })
            ->chunkById(self::CHUNK, function ($orders) use (&$updated, &$failed) {
                foreach ($orders as $order) {
                    try {
                        /*
                         * ⛔ 每一列各自一個 transaction：一列的解密失敗不該
                         * 讓整批已成功的更新被回滾。
                         */
                        DB::transaction(function () use ($order, &$updated) {
                            $changes = [];

                            /*
                             * ⛔ 讀取 `customer_email` 會觸發解密。解密失敗
                             * （例如 APP_KEY 換過）會拋出——由外層 catch 記為
                             * failed，⛔ 不猜值、不寫入部分結果。
                             */
                            if ($order->customer_email_lookup_hash === null) {
                                $hash = ContactLookupHash::forEmail($order->customer_email);

                                if ($hash !== null) {
                                    $changes['customer_email_lookup_hash'] = $hash;
                                }
                            }

                            if ($order->customer_phone_lookup_hash === null) {
                                $hash = ContactLookupHash::forPhone($order->customer_phone);

                                // ⛔ 沒有手機的訂單維持 null，不是失敗。
                                if ($hash !== null) {
                                    $changes['customer_phone_lookup_hash'] = $hash;
                                }
                            }

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

        if ($failed > 0) {
            $this->error('失敗：'.$failed.'（資料異常或無法解密，未寫入）');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
