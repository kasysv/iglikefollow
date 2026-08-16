<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The order timeline, and the outbox seam for fulfilment.
     *
     * order_paid rows are the handover point M4A will read from: a row is
     * written exactly once when an order first becomes paid, and its unique
     * (order_id, type) index means a duplicate payment callback cannot enqueue
     * a second SMM job. Nothing dispatches from here in this milestone.
     */
    public function up(): void
    {
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('type');
            // 摘要用途，⛔ 不放個資或 provider 原始 payload。
            $table->string('summary')->nullable();

            /*
             * 唯一鍵只套在需要「每張訂單一次」的事件上。
             *
             * order_paid 帶 'order_paid'，付款失敗等可重複發生的事件帶 null——
             * SQLite 與 MySQL 的 UNIQUE 都容許多個 NULL，所以同一張訂單可以有很多
             * 筆失敗紀錄，但 ⛔ 永遠只會有一筆 order_paid。
             */
            $table->string('unique_key')->nullable();

            $table->timestamps();

            $table->unique(['order_id', 'unique_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
    }
};
