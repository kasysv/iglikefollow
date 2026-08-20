<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2-C R3:公開內容的穩定識別 key。
 *
 * ⛔ 只限兩類公開內容(FAQ 與 service content sections)。R3 importer 以
 * managed_key 做 idempotent upsert 與精確 rollback,不再依會被改寫的
 * 顯示 heading/question 判斷;nullable=既有非受管資料完全不受影響。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faqs', function (Blueprint $table) {
            $table->string('managed_key')->nullable()->unique();
        });

        Schema::table('service_content_sections', function (Blueprint $table) {
            $table->string('managed_key')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('faqs', function (Blueprint $table) {
            $table->dropUnique(['managed_key']);
            $table->dropColumn('managed_key');
        });

        Schema::table('service_content_sections', function (Blueprint $table) {
            $table->dropUnique(['managed_key']);
            $table->dropColumn('managed_key');
        });
    }
};
