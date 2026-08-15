<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('card_title')->nullable();
            $table->string('h1')->nullable();
            $table->string('summary')->nullable();
            $table->text('intro')->nullable();
            $table->string('goal')->nullable();
            $table->string('card_blurb')->nullable();
            // input_kind allowlist: account / post_url / video_url / page_url
            $table->string('input_kind')->default('account');
            $table->string('input_label');
            $table->string('input_hint')->nullable();
            $table->string('delivery_summary')->nullable();
            $table->string('card_image_path')->nullable();
            $table->string('card_image_alt')->nullable();
            $table->string('hero_image_path')->nullable();
            $table->string('hero_image_alt')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->string('status')->default('draft');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('first_published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // slug 在同一 platform 內唯一
            $table->unique(['platform_id', 'slug']);
            $table->index(['status', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
