<?php

use App\Support\M3bRollbackGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credentials for the payment, invoice and fulfilment providers.
     *
     * One row per provider and environment, because sandbox and production are
     * different accounts with different keys — keeping both on one row is how
     * a test key ends up taking real money.
     *
     * ⛔ There is no endpoint column. A URL that an admin can type is a
     * server-side request forgery waiting to happen: whatever host is entered
     * is a host this server will authenticate to. Endpoints belong in a code
     * whitelist keyed by provider and environment, where they can be reviewed.
     *
     * ⛔ Secrets live in one encrypted `credentials` payload rather than a
     * column each. The set of keys differs per provider, and a column per key
     * would either be mostly null or would invite storing an unvalidated field.
     */
    public function up(): void
    {
        Schema::create('integration_settings', function (Blueprint $table) {
            $table->id();

            $table->string('provider', 32);
            $table->string('environment', 16);

            // 公開識別碼（MerchantID／Channel ID）不是 secret，維持明文以便後台辨識。
            $table->string('identifier')->nullable();

            // ⛔ encrypted cast 產生的 base64 封包長度不可預測，故為 TEXT；不建索引。
            $table->text('credentials')->nullable();

            // ⛔ 預設關閉：新增一組 credential 不等於同意開始交易。
            $table->boolean('is_enabled')->default(false);

            // 連線測試結果的落點；本輪沒有測試按鈕，⛔ 且永遠只存 sanitized 訊息。
            $table->string('last_test_status', 32)->nullable();
            $table->string('last_test_message')->nullable();
            $table->timestamp('last_tested_at')->nullable();

            $table->timestamps();

            // 同一個 provider＋environment 只能有一組設定。
            $table->unique(['provider', 'environment']);
        });
    }

    /**
     * ⛔ 三張 M3B-A 表任一有資料就整批拒絕。
     *
     * 只檢查自己那張表是不夠的：batch rollback 由後往前執行，等輪到這裡時
     * invoices 與 invoice_attempts 早就被 drop 了，變成「錯誤訊息＋兩張表已消失」
     * 的半套回滾。因此三個 migration 都做同一份完整 preflight。
     */
    public function down(): void
    {
        M3bRollbackGuard::assertAllTablesAreEmpty();

        Schema::dropIfExists('integration_settings');
    }
};
