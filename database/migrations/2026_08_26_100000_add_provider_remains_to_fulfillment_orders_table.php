<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The provider's own "remains" figure, kept between scheduled syncs.
 *
 * ⭐ 為什麼需要一個欄位：`remains` 只在每十分鐘的排程同步時取得。若不落盤，
 * Owner 一重新整理後台就什麼都看不到——而後台頁面**不得**因為被打開就即時
 * 呼叫 TheMostPanel（那會讓每次瀏覽都變成一次 provider request）。
 *
 * ⛔ nullable：第一次同步之前沒有值，而「還沒問到」與「對方回報 0」是兩件
 * 完全不同的事。`0` 是合法且有意義的值（全部補完），必須顯示為 `0`；
 * `null` 才是「尚未取得」。用 `0` 當預設會讓兩者永久混淆。
 *
 * ⛔ unsigned big integer：provider 的數量上限遠小於此，但 remains 永遠不會
 * 是負數，而 big integer 讓未來的上限調整不需要再改一次 schema。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillment_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_remains')
                ->nullable()
                ->after('provider_status_code');
        });
    }

    /**
     * ⛔ Fail closed：已經存有同步資料時不得靜默丟掉這個欄位。
     *
     * 這是 additive migration，code rollback 只需 revert commit——欄位留著
     * 不影響舊程式（舊程式根本不讀它）。真正危險的是有人在已經同步過的環境
     * 執行 `migrate:rollback`：那會把每一筆已取得的 remains 永久刪除，而那些
     * 值來自 provider，本站無法重建。
     *
     * 所以只有在**全部為 null** 時才允許 drop；否則明確拋出，讓人先決定要
     * 怎麼處理那些資料。⛔ 不自動備份、不自動清空——那都是替使用者做決定。
     *
     * 同時相容 SQLite（本機／測試）與 MySQL（staging／正式）：這裡只用
     * `whereNotNull()->exists()` 與 `dropColumn()`，兩者行為一致。
     */
    public function down(): void
    {
        if (! Schema::hasColumn('fulfillment_orders', 'provider_remains')) {
            return;
        }

        $hasData = DB::table('fulfillment_orders')
            ->whereNotNull('provider_remains')
            ->exists();

        if ($hasData) {
            throw new RuntimeException(
                'fulfillment_orders.provider_remains 已存有同步資料，拒絕自動刪除欄位。'
                .'請先確認這些值不再需要，再手動處理。'
            );
        }

        Schema::table('fulfillment_orders', function (Blueprint $table) {
            $table->dropColumn('provider_remains');
        });
    }
};
