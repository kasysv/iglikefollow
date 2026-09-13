<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->json('variant_guide')->nullable();
        });
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('show_variant_guide')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('site_settings')->whereNotNull('variant_guide')->exists()
            || DB::table('services')->whereNotNull('show_variant_guide')->exists()) {
            throw new RuntimeException('款式說明或商品開關已有自訂資料；請先備份，回滾程式時可保留這兩個欄位。');
        }

        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('show_variant_guide'));
        Schema::table('site_settings', fn (Blueprint $table) => $table->dropColumn('variant_guide'));
    }
};
