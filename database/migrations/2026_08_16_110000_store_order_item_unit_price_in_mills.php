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
     * service_variants.unit_price is decimal(12,4), so NT$0.1234 is a valid
     * price. The original snapshot stored cents, which silently rounded that
     * to 0.12 — the snapshot was meant to preserve exactly what the customer
     * was charged against, so losing two digits defeats its purpose.
     *
     * This is a corrective migration rather than an edit to the original one:
     * the first migration has already run on the local database, so changing
     * it in place would leave that database and the migration history
     * disagreeing.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // 萬分之一元；容得下 decimal(12,4) 的完整精度。
            $table->unsignedBigInteger('unit_price_mills')->default(0)->after('external_sku');
        });

        // 既有資料：分 → 毫，⛔ 用整數乘法轉換，不引入浮點。
        DB::table('order_items')->update([
            'unit_price_mills' => DB::raw('unit_price_cents * 100'),
        ]);

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('unit_price_cents');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('unit_price_cents')->default(0)->after('external_sku');
        });

        // 毫 → 分會失去兩位精度，這是回滾本身的代價，⛔ 不是資料損毀。
        DB::table('order_items')->update([
            'unit_price_cents' => DB::raw('unit_price_mills / 100'),
        ]);

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('unit_price_mills');
        });
    }
};
