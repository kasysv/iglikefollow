<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The provider's `start_count`: the follower/like count before this order ran.
 *
 * ⭐ Owner 要求在「SMM 履約進度」顯示起始值。它與 `remains` 一樣只在每十分鐘
 * 的排程同步時取得，若不落盤，後台重新整理就消失——而後台頁面**不得**因為被
 * 打開就呼叫 TheMostPanel。
 *
 * ⛔ nullable：第一次同步之前沒有值，而「還沒問到」與「對方回報 0」是兩件不同
 * 的事。`0` 是合法且有意義的（開始前本來就是 0），必須顯示為 `0`；`null` 才是
 * 「尚未取得」。用 `0` 當預設會讓兩者永久混淆。
 *
 * ⛔ unsigned big integer：起始值永遠不會是負數，與既有 `provider_remains`
 * 採同一型別，兩者顯示與驗證規則保持一致。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillment_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_start_count')
                ->nullable()
                ->after('provider_remains');
        });
    }

    /**
     * ⛔ Fail closed：已經存有同步資料時不得靜默丟掉這個欄位。
     *
     * 這是 additive migration，code rollback 只需 revert commit——欄位留著不
     * 影響舊程式（舊程式根本不讀它）。真正危險的是有人在已經同步過的環境執行
     * `migrate:rollback`：那會把每一筆已取得的 start_count 永久刪除，而那些值
     * 來自 provider，本站無法重建。
     *
     * ⛔ 不自動備份、不自動清空——那都是替使用者做決定。
     *
     * 同時相容 SQLite（本機／測試）與 MySQL（staging／正式）。
     */
    public function down(): void
    {
        if (! Schema::hasColumn('fulfillment_orders', 'provider_start_count')) {
            return;
        }

        $hasData = DB::table('fulfillment_orders')
            ->whereNotNull('provider_start_count')
            ->exists();

        if ($hasData) {
            throw new RuntimeException(
                'fulfillment_orders.provider_start_count 已存有同步資料，拒絕自動刪除欄位。'
                .'請先確認這些值不再需要，再手動處理。'
            );
        }

        Schema::table('fulfillment_orders', function (Blueprint $table) {
            $table->dropColumn('provider_start_count');
        });
    }
};
