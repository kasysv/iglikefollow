<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep the full catalogue precision in the order snapshot.
     *
     * service_variants.unit_price is decimal(12,4), so a unit rate of NT$0.1234
     * is valid. The original snapshot stored cents, which silently rounded that
     * to 0.12 — the snapshot exists to preserve exactly what the customer was
     * charged against, so losing two digits defeats its purpose.
     *
     * This is a corrective migration rather than an edit to the original one:
     * the first migration has already run on the local database, so changing it
     * in place would leave that database and the migration history disagreeing.
     *
     * EXPAND / CONTRACT
     * -----------------
     * unit_price_cents is deliberately NOT dropped. During the rollback window
     * both columns exist: new code writes the precise mills and keeps the
     * legacy cents in step, so code written before this migration still reads a
     * sane (if less precise) value. That makes the supported rollback a code-only
     * revert, with no schema change and therefore no data loss.
     *
     * ⛔ down() is destructive and will refuse to run if any row would lose
     * precision. See the guard below.
     */
    public function up(): void
    {
        // 這個 migration 的初版會 drop 掉 legacy 欄位，所以已升級過的資料庫可能沒有它。
        $hadCents = Schema::hasColumn('order_items', 'unit_price_cents');

        if (! Schema::hasColumn('order_items', 'unit_price_mills')) {
            Schema::table('order_items', function (Blueprint $table) {
                // 萬分之一元；容得下 decimal(12,4) 的完整精度。
                $table->unsignedBigInteger('unit_price_mills')->default(0)->after('external_sku');
            });

            if ($hadCents) {
                // 既有資料：分 → 毫，⛔ 用整數乘法轉換，不引入浮點。
                DB::table('order_items')->update([
                    'unit_price_mills' => DB::raw('unit_price_cents * 100'),
                ]);
            }
        }

        // ⛔ 保留 legacy 欄位：expand／contract 的 rollback window 靠它讓舊程式仍讀得到值。
        if (! $hadCents) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->unsignedInteger('unit_price_cents')->default(0)->after('external_sku');
            });

            // 毫 → 分只作為 legacy 讀值，精確值仍在 unit_price_mills。
            DB::table('order_items')->update([
                'unit_price_cents' => DB::raw('unit_price_mills / 100'),
            ]);

            return;
        }

        // 原始 migration 的 legacy 欄位是 NOT NULL 且沒有預設值，因此任何沒有指名
        // 它的 insert 都會失敗。它現在只是回退用的附帶欄位，⛔ 不該再逼呼叫端填。
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('unit_price_cents')->default(0)->change();
        });
    }

    /**
     * ⛔ 這個 down() 會刪掉精確欄位，只留「分」。
     *
     * 正式 rollback 步驟是「只回退程式碼，不執行這個 down()」——兩個欄位在
     * rollback window 內同時存在，舊程式讀 cents 就能運作。真的要縮回舊 schema
     * 時，任何一列只要有無法用「分」表達的精度，就整個中止：⛔ 寧可 rollback
     * 失敗，也不能把 1234 毫悄悄變成 1200 毫，讓大量購買的應付總額對不上。
     */
    public function down(): void
    {
        $lossy = DB::table('order_items')
            ->whereRaw('unit_price_mills % 100 != 0')
            ->count();

        if ($lossy > 0) {
            throw new RuntimeException(
                "無法回滾：有 {$lossy} 筆訂單商品的單價精度無法用「分」表達，"
                .'強制回滾會改變應付金額。正式 rollback 請只回退程式碼，保留這個 migration。'
            );
        }

        if (! Schema::hasColumn('order_items', 'unit_price_cents')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->unsignedInteger('unit_price_cents')->default(0)->after('external_sku');
            });
        }

        DB::table('order_items')->update([
            'unit_price_cents' => DB::raw('unit_price_mills / 100'),
        ]);

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('unit_price_mills');
        });
    }
};
