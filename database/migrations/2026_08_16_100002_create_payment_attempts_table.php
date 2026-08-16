<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per payment attempt.
     *
     * A customer may fail, cancel, let an attempt expire and then pay. Each
     * outcome is preserved so the order history stays answerable.
     *
     * (provider, provider_reference) is unique: a repeated callback for the
     * same provider transaction is rejected by the database, which is what
     * makes idempotency a guarantee rather than a code convention.
     */
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('provider');
            // 本站付款參考，⛔ 不可猜測。
            $table->string('reference', 32)->unique();
            // provider 端交易編號；建立時還沒有，因此可空。
            $table->string('provider_reference')->nullable();

            $table->string('status')->index();
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('TWD');

            // 只存遮罩後的錯誤代碼／訊息。
            // ⛔ 不得保存卡號、CVV、API secret 或未遮罩的完整 provider payload。
            $table->string('failure_code')->nullable();
            $table->string('failure_message')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // 同一筆 provider 交易只能記錄一次。
            $table->unique(['provider', 'provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
