<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Database-level limits on what a payment attempt may say.
     *
     * A forward migration rather than an edit to the M3A one: that migration
     * has already run everywhere, so changing it in place would leave the
     * schema and the migration history disagreeing.
     *
     * The domain enforces the same rules. These exist for the writes that
     * never touch a model — a repair script, a console, a future migration —
     * because an attempt claiming to have taken NT$0, or carrying a status
     * nothing in the code understands, should not be representable.
     */
    private const STATUSES = "'initiated','pending','succeeded','failed','canceled','expired','reconciliation_required'";

    private const PROVIDERS = "'ecpay','line-pay','fake'";

    public function up(): void
    {
        $this->assertDriverIsSupported();

        // ⛔ provider_reference 必須跨嘗試唯一：兩筆嘗試共用同一個 provider
        // 交易編號，就無法分辨哪一次付款對應哪一張訂單。
        // M3A 已建立 (provider, provider_reference) unique，這裡不重複建立。

        $condition = fn (string $p) => "{$p}amount > 0"
            ." AND {$p}currency = 'TWD'"
            ." AND {$p}status IN (".self::STATUSES.')'
            ." AND {$p}provider IN (".self::PROVIDERS.')';

        match (DB::getDriverName()) {
            'sqlite' => $this->addSqliteTriggers($condition('NEW.')),
            'mysql', 'mariadb', 'pgsql' => DB::statement(
                'ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_values_check CHECK ('
                .$condition('').')'
            ),
        };
    }

    public function down(): void
    {
        $this->assertDriverIsSupported();

        match (DB::getDriverName()) {
            'sqlite' => $this->dropSqliteTriggers(),
            'mysql', 'mariadb', 'pgsql' => DB::statement(
                'ALTER TABLE payment_attempts DROP CONSTRAINT payment_attempts_values_check'
            ),
        };
    }

    /**
     * ⛔ Fail rather than skip on an unrecognised driver.
     *
     * Silently doing nothing would leave production with no constraint at all,
     * and nobody would find out until a bad row appeared.
     */
    private function assertDriverIsSupported(): void
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true)) {
            throw new RuntimeException(
                "未預期的資料庫驅動 {$driver}：⛔ 無法建立 payment_attempts 的值域限制。"
            );
        }
    }

    private function addSqliteTriggers(string $condition): void
    {
        $message = 'payment_attempts: amount 必須為正整數、currency 必須為 TWD、status 與 provider 必須合法';

        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
            DB::statement("
                CREATE TRIGGER payment_attempts_values_check_{$suffix}
                BEFORE {$event} ON payment_attempts
                FOR EACH ROW
                WHEN NOT ({$condition})
                BEGIN
                    SELECT RAISE(ABORT, '{$message}');
                END
            ");
        }
    }

    private function dropSqliteTriggers(): void
    {
        if (! Schema::hasTable('payment_attempts')) {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS payment_attempts_values_check_insert');
        DB::statement('DROP TRIGGER IF EXISTS payment_attempts_values_check_update');
    }
};
