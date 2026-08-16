<?php

use App\Support\M3bRollbackGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One e-invoice per paid order.
     *
     * ⛔ No buyer details are copied here — no email, phone, tax id, company
     * name or carrier. The order already stores those, encrypted, and a second
     * copy would be a second place for personal data to leak from while adding
     * nothing: this table's job is to record what happened with the provider.
     *
     * The unique key on order_id is the database-level guarantee that a single
     * order can never be invoiced twice, no matter how many times a queue
     * redelivers the job or how many OrderPaid events arrive.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // ⛔ 一張訂單只能有一張發票；這是資料庫層的最終保證。
            //
            // ⛔ restrictOnDelete，不是 cascade：發票是稅務憑證，刪一張訂單不該
            // 連帶消滅它。要刪訂單就必須先明確處理發票，而不是讓它悄悄消失。
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();

            $table->string('provider', 32);
            $table->string('status', 32);

            // 整數台幣，與已付款訂單金額相同；⛔ 不接受小數。
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('TWD');

            // provider 回傳的識別；發票號碼與隨機碼是對帳依據，非個資。
            $table->string('provider_reference')->nullable();
            $table->string('invoice_number', 32)->nullable();
            $table->string('random_code', 8)->nullable();

            // ⛔ 只存整理過的錯誤碼與訊息，不存 raw response。
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamp('allowance_at')->nullable();
            // 結果不明時記錄需要人工確認的時間點。
            $table->timestamp('reconciliation_required_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('invoice_number');
        });

        $this->addValueConstraints();
    }

    /**
     * Database-level limits on what an invoice may say.
     *
     * The domain observer enforces the same rules, but a constraint holds for
     * writes that never touch a model — a migration, a repair script, someone
     * at a SQL prompt. ⛔ An invoice for zero dollars or an unrecognised status
     * is not a value the application should be able to produce by any route.
     */
    private function addValueConstraints(): void
    {
        $statuses = "'pending_configuration','pending','processing','issued','failed','reconciliation_required','voided'";

        // ⛔ 兩份運算式分開寫，不用字串取代去加 NEW. 前綴：
        // 'status' 這個字也出現在狀態值裡，盲目取代會產生無效 SQL。
        $condition = fn (string $p) => "{$p}amount > 0 AND {$p}currency = 'TWD'"
            ." AND {$p}status IN ({$statuses})"
            ." AND {$p}provider IN ('ecpay_invoice')";

        $driver = DB::getDriverName();

        // ⛔ 明確分支：SQLite 的 CHECK 只能在建表時加入，MySQL／Postgres 可事後加。
        // 遇到沒處理過的驅動就中止，不靜默跳過而讓保護在正式環境不存在。
        match ($driver) {
            // SQLite 已在建表後，改用等效的 trigger；語意與 CHECK 相同。
            'sqlite' => $this->addSqliteTriggers($condition('NEW.')),
            'mysql', 'mariadb', 'pgsql' => DB::statement(
                'ALTER TABLE invoices ADD CONSTRAINT invoices_values_check CHECK ('.$condition('').')'
            ),
            default => throw new RuntimeException(
                "未預期的資料庫驅動 {$driver}：⛔ 無法建立 invoices 的值域限制，拒絕在無保護的情況下繼續。"
            ),
        };
    }

    private function addSqliteTriggers(string $condition): void
    {
        $message = 'invoices: amount 必須為正整數、currency 必須為 TWD、status 與 provider 必須合法';

        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
            DB::statement("
                CREATE TRIGGER invoices_values_check_{$suffix}
                BEFORE {$event} ON invoices
                FOR EACH ROW
                WHEN NOT ({$condition})
                BEGIN
                    SELECT RAISE(ABORT, '{$message}');
                END
            ");
        }
    }

    /**
     * ⛔ 三張 M3B-A 表任一有資料就整批拒絕；見 M3bRollbackGuard 的說明。
     *
     * 發票是稅務憑證，國稅局那邊另有一份；把我們這份靜靜刪掉，等於日後說不出
     * 自己開過什麼。回滾是開發便利，必須讓路給資料。
     */
    public function down(): void
    {
        M3bRollbackGuard::assertAllTablesAreEmpty();

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS invoices_values_check_insert');
            DB::statement('DROP TRIGGER IF EXISTS invoices_values_check_update');
        }

        Schema::dropIfExists('invoices');
    }
};
