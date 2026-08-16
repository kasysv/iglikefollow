<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An immutable snapshot of what was bought.
     *
     * Names, prices and SKUs are copied in rather than read through the
     * relation: editing a variant in the admin, renaming it or unpublishing it
     * must never change what an existing order says was sold. The
     * service_variant_id is kept only as a pointer for fulfilment, and is
     * nulled rather than cascading if the variant is ever hard-deleted.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_variant_id')->nullable()
                ->constrained()->nullOnDelete();

            // 下單當下的快照，⛔ 之後後台怎麼改都不影響這裡。
            $table->string('platform_name');
            $table->string('service_name');
            $table->string('variant_label');
            $table->string('sku')->nullable();
            $table->string('external_sku')->nullable();

            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('quantity');
            $table->string('quantity_unit', 16);
            $table->unsignedInteger('amount');

            // 履約目標：帳號還是網址，以及客人填的值。
            $table->string('target_kind');
            $table->string('target_value');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
