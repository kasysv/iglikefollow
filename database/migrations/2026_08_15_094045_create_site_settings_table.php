<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->string('home_eyebrow')->nullable();
            $table->string('home_h1');
            $table->text('home_intro')->nullable();
            $table->string('company_image_path')->nullable();
            $table->string('company_image_alt')->nullable();
            $table->string('primary_cta_label')->nullable();
            // ⛔ CTA 只能指向既有內部 route 名稱白名單，不接受任意 URL。
            $table->string('primary_cta_route')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
