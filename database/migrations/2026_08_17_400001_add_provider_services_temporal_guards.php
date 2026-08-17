<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Time must be coherent in the catalog, at the database layer.
     *
     * The reviewer replayed an older snapshot over a newer one and produced a
     * row whose `first_seen_at` was *after* its `last_seen_at` — a timeline
     * that cannot have happened. The action now refuses stale snapshots, but
     * that is application code; these guards make the impossible states
     * unstorable no matter who writes:
     *
     *  - seen timestamps are both null (never observed) or both non-null with
     *    `first_seen_at <= last_seen_at` — never one-sided, never inverted;
     *  - `is_available = true` requires both seen timestamps: "available" is a
     *    claim that a successful snapshot observed the service, and a row
     *    that was never observed cannot make it.
     *
     * ⛔ A separate forward migration on purpose — `400000` has already run
     * everywhere it exists, and editing an applied migration changes nothing
     * on any database that matters.
     */
    public function up(): void
    {
        $this->assertDriverIsSupported();

        // ⛔ 先驗現況：已有違規 row 時 fail closed，只回報筆數，不自動改寫或刪除。
        $this->assertExistingRowsAreCoherent();

        $message = 'provider_services: seen timestamps 必須同為 null 或同為非 null 且不倒置；is_available 需要完整觀察時間';

        if (DB::getDriverName() === 'sqlite') {
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
                DB::statement("
                    CREATE TRIGGER provider_services_temporal_guard_{$suffix}
                    BEFORE {$event} ON provider_services
                    FOR EACH ROW
                    WHEN NOT ({$this->condition('NEW.', 'sqlite')})
                    BEGIN
                        SELECT RAISE(ABORT, '{$message}');
                    END
                ");
            }
        } else {
            DB::statement(
                'ALTER TABLE provider_services ADD CONSTRAINT provider_services_temporal_check CHECK ('
                .$this->condition('', DB::getDriverName()).')'
            );
        }

        $this->assertEveryGuardExists();
    }

    /**
     * ⛔ Removes only the guards this migration added. The table and every row
     * stay — a guard rollback must never be a data rollback.
     */
    public function down(): void
    {
        if (! Schema::hasTable('provider_services')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS provider_services_temporal_guard_insert');
            DB::statement('DROP TRIGGER IF EXISTS provider_services_temporal_guard_update');

            return;
        }

        DB::statement('ALTER TABLE provider_services DROP CONSTRAINT provider_services_temporal_check');
    }

    /**
     * The coherent-state test, shared by every driver.
     *
     * Timestamps compare correctly as strings on SQLite ('Y-m-d H:i:s' is
     * lexicographically ordered) and natively elsewhere.
     */
    private function condition(string $p, string $driver): string
    {
        $bothNull = "({$p}first_seen_at IS NULL AND {$p}last_seen_at IS NULL)";

        $bothSetInOrder = "({$p}first_seen_at IS NOT NULL AND {$p}last_seen_at IS NOT NULL"
            ." AND {$p}first_seen_at <= {$p}last_seen_at)";

        // ⛔ pgsql 的 boolean 不是整數，比較方式不同；其餘 driver 底層是 0／1。
        $unavailable = $driver === 'pgsql'
            ? "NOT {$p}is_available"
            : "{$p}is_available = 0";

        $observed = "({$p}first_seen_at IS NOT NULL AND {$p}last_seen_at IS NOT NULL)";

        return "({$bothNull} OR {$bothSetInOrder}) AND ({$unavailable} OR {$observed})";
    }

    /**
     * ⛔ Refuse to install guards over data that already violates them.
     *
     * Silently guarding forward would leave incoherent rows frozen in place,
     * unfixable through the guarded write path. The message carries a count
     * and nothing else — never a service id, name or timestamp.
     */
    private function assertExistingRowsAreCoherent(): void
    {
        if (! Schema::hasTable('provider_services')) {
            return;
        }

        $driver = DB::getDriverName();

        $invalid = DB::table('provider_services')
            ->whereRaw('NOT ('.$this->condition('', $driver).')')
            ->count();

        if ($invalid > 0) {
            throw new RuntimeException(
                "⛔ provider_services 有 {$invalid} 筆時間狀態不一致的資料，"
                .'temporal guard 拒絕安裝。請先人工檢視並修正，不得自動改寫或刪除。'
            );
        }
    }

    /** ⛔ 建立後驗證守衛真的存在，包含 400000 的值約束——不得默默缺一個。 */
    private function assertEveryGuardExists(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $expected = [
            'provider_services_values_check_insert',
            'provider_services_values_check_update',
            'provider_services_temporal_guard_insert',
            'provider_services_temporal_guard_update',
        ];

        $present = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->pluck('name')
            ->all();

        $missing = array_values(array_diff($expected, $present));

        if ($missing !== []) {
            throw new RuntimeException(
                '⛔ provider_services temporal guard 未完整建立，缺少：'.implode('、', $missing)
            );
        }
    }

    private function assertDriverIsSupported(): void
    {
        // ⛔ 未知 driver 就失敗，不靜默略過：略過會讓正式環境完全沒有這道保護。
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql', 'mariadb', 'pgsql'], true)) {
            throw new RuntimeException(
                '⛔ 未支援的資料庫 driver，無法建立 provider_services 的 temporal guard。'
            );
        }
    }
};
