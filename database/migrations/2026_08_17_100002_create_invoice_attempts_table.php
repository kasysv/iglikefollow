<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per attempt to issue an invoice with a provider.
     *
     * The unique idempotency key is what makes a redelivered queue job safe:
     * the second insert fails at the database rather than becoming a second
     * request to the provider. It is derived from stable facts about the
     * invoice, ⛔ never from a random value, or a retry would compute a new key
     * and defeat the whole mechanism.
     *
     * ⛔ Raw requests and responses are not stored. They would contain the
     * buyer's details and enough material to replay a call, and neither is
     * needed to answer "what did we send and what came back" — the fingerprint
     * and sanitized codes cover that.
     */
    public function up(): void
    {
        Schema::create('invoice_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            // ⛔ 唯一冪等鍵：重送的 job 會在資料庫這裡就被擋下。
            $table->string('idempotency_key', 128)->unique();

            $table->string('status', 32);

            // 送出內容的雜湊，用來辨識「同一份請求」；⛔ 不可還原成原始內容。
            $table->string('request_fingerprint', 64)->nullable();

            $table->string('provider_reference')->nullable();

            // ⛔ 只存整理過的碼與訊息。
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['invoice_id', 'status']);
        });
    }

    /** ⛔ 有嘗試紀錄就不刪：這是「我們到底送出過幾次」的唯一證據。 */
    public function down(): void
    {
        if (! Schema::hasTable('invoice_attempts')) {
            return;
        }

        $count = DB::table('invoice_attempts')->count();

        if ($count > 0) {
            throw new RuntimeException(
                "無法回滾：invoice_attempts 已有 {$count} 筆開立嘗試紀錄。"
                .'請先匯出並確認後再手動清除。'
            );
        }

        Schema::dropIfExists('invoice_attempts');
    }
};
