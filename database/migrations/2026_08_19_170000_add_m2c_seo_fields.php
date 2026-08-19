<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2-C(D-103 方案3)資料模型:首頁 SEO 欄位與 `/product/` 商品路由 slug。
 *
 * ⛔ `services.slug` 仍是內部英文 service key,不改名、不覆寫;
 * `product_slug` 是受控技術 SEO 欄位(D-103 canonical 的 slug 本體,
 * 不含 `/product/` 前綴),由 DB unique+request validation 雙層防護。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('seo_title')->nullable();
            $table->string('meta_description')->nullable();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->string('product_slug')->nullable()->unique();
            $table->string('cta_label')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(['seo_title', 'meta_description']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique(['product_slug']);
            $table->dropColumn(['product_slug', 'cta_label']);
        });
    }
};
