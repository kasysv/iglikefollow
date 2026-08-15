<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platforms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('eyebrow')->nullable();
            $table->string('h1')->nullable();
            $table->string('tagline')->nullable();
            $table->text('intro')->nullable();
            $table->string('hero_image_path')->nullable();
            $table->string('hero_image_alt')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('first_published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platforms');
    }
};
