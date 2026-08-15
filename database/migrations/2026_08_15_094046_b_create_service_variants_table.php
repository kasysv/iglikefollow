<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->nullable()->unique();
            $table->string('label');
            $table->string('description')->nullable();
            $table->string('image_path')->nullable();
            $table->string('image_alt')->nullable();
            $table->string('quantity_unit')->default('個');
            $table->unsignedInteger('min_quantity');
            $table->unsignedInteger('max_quantity');
            $table->unsignedInteger('step_quantity')->default(1);
            $table->unsignedInteger('default_quantity');
            $table->decimal('unit_price', 12, 4);
            $table->string('currency', 3)->default('TWD');
            // 為未來履約／catalog API 預留；⛔ 本輪不呼叫任何外部 API。
            $table->string('external_sku')->nullable();
            $table->boolean('is_featured')->default(false);
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
        Schema::dropIfExists('service_variants');
    }
};
