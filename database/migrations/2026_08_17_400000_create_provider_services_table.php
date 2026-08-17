<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the supplier currently claims to offer — nothing more.
     *
     * ⛔ Deliberately not related to `fulfillment_mappings`. The catalog is the
     * provider's declaration; a mapping is the Owner's decision. No FK points
     * either way, so a service vanishing from a future sync can never take a
     * mapping or an order's history with it.
     *
     * ⛔ `is_available` defaults to false and CATALOG-A never sets it true: only
     * a future complete, successful snapshot of the real account may. Rows here
     * carry provider-controlled text and raw rates — the rate is not a retail
     * price and its currency/unit is unverified.
     */
    public function up(): void
    {
        Schema::create('provider_services', function (Blueprint $table) {
            $table->id();

            $table->string('provider', 32);

            // ⛔ canonical positive-integer string；不假設連號或穩定性。
            $table->string('provider_service_id', 64);

            $table->string('name', 255);

            // ⛔ 叫 service_type 不叫 type：provider 原文，不等同本站 payload type。
            $table->string('service_type', 100);

            $table->string('category', 255);

            // ⛔ 原始字串，不做 float／金額換算；幣別與計費單位未驗證。
            $table->string('rate_raw', 64);

            $table->string('minimum_quantity_raw', 64);
            $table->string('maximum_quantity_raw', 64);

            $table->boolean('supports_refill');
            $table->boolean('supports_cancel');

            $table->boolean('is_available')->default(false);

            // ⛔ CATALOG-A 不 seed 也不偽造觀察時間；只有真實 snapshot 會填。
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'provider_service_id']);
            $table->index(['provider', 'is_available']);
            $table->index('category');
            $table->index('service_type');
        });

        $this->addValueConstraints();
        $this->assertEveryGuardExists();
    }

    /**
     * ⛔ A rollback holding data fails closed. Catalog rows are observations of
     * a supplier account at a point in time; once real ones exist they cannot
     * be re-created by re-running a migration, so dropping them needs a human
     * decision, not a `migrate:rollback`.
     */
    public function down(): void
    {
        if (Schema::hasTable('provider_services')) {
            $count = DB::table('provider_services')->count();

            if ($count > 0) {
                throw new RuntimeException(
                    "無法回滾 provider_services：仍有 {$count} 筆供應商服務快照。"
                    .'⛔ 尚未刪除任何資料表。請先匯出並確認後再手動清除，'
                    .'或改用 code-only rollback（git revert，保留資料表）。'
                );
            }
        }

        $this->dropValueConstraints();

        Schema::dropIfExists('provider_services');
    }

    /**
     * Second-layer protection in the database itself.
     *
     * Parser and model validation can be bypassed by a raw query, a seeder or
     * a future refactor. These say the same thing where nothing can go around
     * them: allowlisted provider, positive-integer service id, no blank
     * required strings, and booleans that are actually 0 or 1.
     */
    private function addValueConstraints(): void
    {
        $driver = DB::getDriverName();

        $message = 'provider_services: provider 必須合法，service ID 必須為正整數字串，必要欄位不得空白';

        match ($driver) {
            'sqlite' => $this->addSqliteTriggers($this->condition('NEW.', $driver), $message),
            'mysql', 'mariadb', 'pgsql' => DB::statement(
                'ALTER TABLE provider_services ADD CONSTRAINT provider_services_values_check CHECK ('
                .$this->condition('', $driver).')'
            ),
            // ⛔ 未知 driver 就失敗，不靜默略過：略過會讓正式環境完全沒有這道保護。
            default => throw new RuntimeException(
                '⛔ 未支援的資料庫 driver，無法建立 provider_services 的資料約束。'
            ),
        };
    }

    /**
     * ⛔ 前綴由呼叫端傳入，不用字串取代加上 NEW.：
     * 'provider' 這個字也出現在 provider_service_id 裡，盲目取代會產生無效 SQL。
     */
    private function condition(string $p, string $driver): string
    {
        $providers = "'themostpanel'";

        // 正整數、無前導零、只有數字。
        $serviceId = match ($driver) {
            'sqlite' => "{$p}provider_service_id GLOB '[1-9]*'"
                ." AND {$p}provider_service_id NOT GLOB '*[^0-9]*'",
            'mysql', 'mariadb' => "{$p}provider_service_id REGEXP '^[1-9][0-9]*$'",
            default => "{$p}provider_service_id ~ '^[1-9][0-9]*$'",
        };

        // rate／quantity：至少非空白且只含數字（rate 另可含小數點）。
        // ⛔ 嚴格十進位格式由 parser 把關；這裡是繞過 parser 時的底線。
        $numericish = match ($driver) {
            'sqlite' => "length({$p}rate_raw) > 0 AND {$p}rate_raw NOT GLOB '*[^0-9.]*'"
                ." AND length({$p}minimum_quantity_raw) > 0"
                ." AND {$p}minimum_quantity_raw NOT GLOB '*[^0-9]*'"
                ." AND length({$p}maximum_quantity_raw) > 0"
                ." AND {$p}maximum_quantity_raw NOT GLOB '*[^0-9]*'",
            'mysql', 'mariadb' => "{$p}rate_raw REGEXP '^[0-9]+(\\\\.[0-9]+)?$'"
                ." AND {$p}minimum_quantity_raw REGEXP '^[0-9]+$'"
                ." AND {$p}maximum_quantity_raw REGEXP '^[0-9]+$'",
            default => "{$p}rate_raw ~ '^[0-9]+(\\.[0-9]+)?$'"
                ." AND {$p}minimum_quantity_raw ~ '^[0-9]+$'"
                ." AND {$p}maximum_quantity_raw ~ '^[0-9]+$'",
        };

        $condition = "{$p}provider IN ({$providers})"
            ." AND {$serviceId}"
            ." AND length(trim({$p}name)) > 0"
            ." AND length(trim({$p}service_type)) > 0"
            ." AND length(trim({$p}category)) > 0"
            ." AND {$numericish}";

        /*
         * ⛔ boolean 只認 0／1：SQLite 與 MySQL 底層是整數欄位，raw insert
         * 可以塞進 2 或 'yes'。PostgreSQL 的 boolean 型別本身就拒絕非法值，
         * 加上 IN (0,1) 反而是型別錯誤，所以略過。
         */
        if ($driver !== 'pgsql') {
            $condition .= " AND {$p}supports_refill IN (0, 1)"
                ." AND {$p}supports_cancel IN (0, 1)"
                ." AND {$p}is_available IN (0, 1)";
        }

        return $condition;
    }

    private function addSqliteTriggers(string $condition, string $message): void
    {
        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
            DB::statement("
                CREATE TRIGGER provider_services_values_check_{$suffix}
                BEFORE {$event} ON provider_services
                FOR EACH ROW
                WHEN NOT ({$condition})
                BEGIN
                    SELECT RAISE(ABORT, '{$message}');
                END
            ");
        }
    }

    /**
     * ⛔ Prove the guards are actually there before declaring success — the
     * lesson from M3A-R2 and M4A-R1, where a table rebuild silently destroyed
     * triggers and the migration reported DONE anyway.
     */
    private function assertEveryGuardExists(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $expected = [
            'provider_services_values_check_insert',
            'provider_services_values_check_update',
        ];

        $present = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->pluck('name')
            ->all();

        $missing = array_values(array_diff($expected, $present));

        if ($missing !== []) {
            throw new RuntimeException(
                '⛔ provider_services 資料約束未完整建立，缺少：'.implode('、', $missing)
            );
        }
    }

    private function dropValueConstraints(): void
    {
        if (! Schema::hasTable('provider_services')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS provider_services_values_check_insert');
            DB::statement('DROP TRIGGER IF EXISTS provider_services_values_check_update');
        }
    }
};
