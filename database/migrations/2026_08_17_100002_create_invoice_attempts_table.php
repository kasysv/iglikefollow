<?php

use App\Support\M3bRollbackGuard;
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

            // ⛔ restrictOnDelete：嘗試紀錄是「我們到底送出過幾次」的唯一證據，
            // 刪發票不該讓它一起消失，否則對帳時再也還原不了經過。
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();

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

        $this->addStatusConstraint();
    }

    /** ⛔ 狀態只能是規格中的四個值，資料庫層一併把關。 */
    private function addStatusConstraint(): void
    {
        $statuses = "'started','succeeded','failed','ambiguous'";
        $driver = DB::getDriverName();

        match ($driver) {
            'sqlite' => $this->addSqliteTriggers($statuses),
            'mysql', 'mariadb', 'pgsql' => DB::statement(
                "ALTER TABLE invoice_attempts ADD CONSTRAINT invoice_attempts_status_check
                 CHECK (status IN ({$statuses}))"
            ),
            default => throw new RuntimeException(
                "未預期的資料庫驅動 {$driver}：⛔ 無法建立 invoice_attempts 的值域限制。"
            ),
        };
    }

    private function addSqliteTriggers(string $statuses): void
    {
        $message = 'invoice_attempts: status 必須是 started／succeeded／failed／ambiguous';

        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
            DB::statement("
                CREATE TRIGGER invoice_attempts_status_check_{$suffix}
                BEFORE {$event} ON invoice_attempts
                FOR EACH ROW
                WHEN NEW.status NOT IN ({$statuses})
                BEGIN
                    SELECT RAISE(ABORT, '{$message}');
                END
            ");
        }
    }

    /**
     * ⛔ 三張 M3B-A 表任一有資料就整批拒絕；見 M3bRollbackGuard 的說明。
     *
     * 這張表在 batch rollback 中最先被處理，所以「先檢查全部再動手」特別重要：
     * 它一旦被 drop，後面的 migration 才報錯已經來不及。
     */
    public function down(): void
    {
        M3bRollbackGuard::assertAllTablesAreEmpty();

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS invoice_attempts_status_check_insert');
            DB::statement('DROP TRIGGER IF EXISTS invoice_attempts_status_check_update');
        }

        Schema::dropIfExists('invoice_attempts');
    }
};
