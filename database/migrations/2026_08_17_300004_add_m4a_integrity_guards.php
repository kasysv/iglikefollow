<?php

use App\Enums\FulfillmentEventCode;
use App\Enums\FulfillmentStatus;
use App\Support\M4aRollbackGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The database half of M4A's integrity rules.
     *
     * Two things the application layer alone cannot promise:
     *
     *  - a fulfilment row must never walk backwards out of a terminal or
     *    post-submit state, because doing so makes it submittable again and one
     *    paid item becomes two supplier orders;
     *  - the timeline must never be updated *or* deleted, because a history
     *    that can be rewritten is not evidence.
     *
     * ⛔ Observers can be bypassed by a raw query, a seeder, `DB::table()` or a
     * future refactor. These cannot.
     */
    public function up(): void
    {
        $this->assertDriverIsSupported();

        $this->addStatusTransitionGuard();
        $this->addSubmittedIdentifierGuard();
        $this->addTimelineDeleteGuard();

        $this->assertEveryGuardExists();
    }

    /**
     * ⛔ Prove the guards are actually there before declaring success.
     *
     * The FK change rebuilds the table on SQLite and takes its triggers with
     * it. Recreating them is not enough on its own — a migration that quietly
     * left a protection missing would report DONE either way, and the gap would
     * only surface as a corrupted timeline months later.
     */
    private function assertEveryGuardExists(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $expected = [
            'fulfillment_orders_transition_guard',
            'fulfillment_orders_identifier_guard_insert',
            'fulfillment_orders_identifier_guard_update',
            'fulfillment_events_append_only_update',
            'fulfillment_events_append_only_delete',
            'fulfillment_events_values_check_insert',
            'fulfillment_orders_values_check_insert',
            'fulfillment_orders_values_check_update',
            'fulfillment_mappings_values_check_insert',
            'fulfillment_mappings_values_check_update',
        ];

        $present = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->pluck('name')
            ->all();

        $missing = array_values(array_diff($expected, $present));

        if ($missing !== []) {
            throw new RuntimeException(
                '⛔ M4A 完整性保護未完整建立，缺少：'.implode('、', $missing)
            );
        }
    }

    public function down(): void
    {
        /*
         * ⛔ 同一道三表守衛。
         *
         * 沒有這一行，有資料的 full rollback 會先拆掉完整性保護，然後才在更舊
         * 的 migration 失敗——留下一個「保護沒了、資料還在」的最糟狀態。
         */
        M4aRollbackGuard::assertAllTablesAreEmpty();

        $this->dropGuards();
    }

    /**
     * Build the legal-transition test from the enum itself.
     *
     * ⛔ Generated from `canTransitionTo()` rather than hand-written, so the SQL
     * and the application can never disagree about what is legal.
     */
    private function transitionCondition(): string
    {
        $illegal = [];

        foreach (FulfillmentStatus::cases() as $from) {
            foreach (FulfillmentStatus::cases() as $to) {
                if (! $from->canTransitionTo($to)) {
                    $illegal[] = "(OLD.status = '{$from->value}' AND NEW.status = '{$to->value}')";
                }
            }
        }

        return implode(' OR ', $illegal);
    }

    private function addStatusTransitionGuard(): void
    {
        $illegal = $this->transitionCondition();
        $message = '⛔ 不允許的履約狀態轉移：已終止或已送出的紀錄不得回到送出前的狀態';

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("
                CREATE TRIGGER fulfillment_orders_transition_guard
                BEFORE UPDATE OF status ON fulfillment_orders
                FOR EACH ROW
                WHEN ({$illegal})
                BEGIN
                    SELECT RAISE(ABORT, '{$message}');
                END
            ");

            return;
        }

        // MySQL／MariaDB／PostgreSQL：同一份條件，改用 BEFORE UPDATE trigger。
        $this->addSqlTrigger(
            'fulfillment_orders_transition_guard',
            'fulfillment_orders',
            $illegal,
            $message,
        );
    }

    /**
     * ⛔ A submitted row must carry a usable identifier.
     *
     * The original CHECK only rejected NULL, so `''` and `'   '` passed — a row
     * that claims to be dispatched with nothing to reconcile against.
     */
    private function addSubmittedIdentifierGuard(): void
    {
        $bad = "NEW.status = 'submitted'"
            ." AND (NEW.provider_order_id IS NULL OR trim(NEW.provider_order_id) = '')";

        $blank = "NEW.provider_order_id IS NOT NULL AND trim(NEW.provider_order_id) = ''";

        $message = '⛔ 已送出的履約紀錄必須具備非空白的供應商單號';

        if (DB::getDriverName() === 'sqlite') {
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
                DB::statement("
                    CREATE TRIGGER fulfillment_orders_identifier_guard_{$suffix}
                    BEFORE {$event} ON fulfillment_orders
                    FOR EACH ROW
                    WHEN (({$bad}) OR ({$blank}))
                    BEGIN
                        SELECT RAISE(ABORT, '{$message}');
                    END
                ");
            }

            return;
        }

        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
            $this->addSqlTrigger(
                "fulfillment_orders_identifier_guard_{$suffix}",
                'fulfillment_orders',
                "({$bad}) OR ({$blank})",
                $message,
                $event,
            );
        }
    }

    /**
     * ⛔ No event may be deleted, by anyone, ever.
     *
     * M4A's first version left DELETE unguarded because a DELETE trigger also
     * fires for `ON DELETE CASCADE`, which made a fulfilment row undeletable
     * once it had any event. The fix is not to weaken the guard — it is to stop
     * relying on cascade: `fulfillment_events.fulfillment_order_id` becomes
     * `RESTRICT`, so a fulfilment row with a timeline cannot be removed at all.
     *
     * That is the correct outcome anyway. Nothing in M4A deletes a fulfilment
     * row — its `order_item_id` is already `RESTRICT` — and a rollback holding
     * data is required to fail closed regardless.
     */
    private function addTimelineDeleteGuard(): void
    {
        $this->replaceEventsForeignKeyWithRestrict();

        $message = '⛔ 履約事件為 append-only：不得刪除';

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("
                CREATE TRIGGER fulfillment_events_append_only_delete
                BEFORE DELETE ON fulfillment_events
                FOR EACH ROW
                BEGIN
                    SELECT RAISE(ABORT, '{$message}');
                END
            ");

            return;
        }

        // 其他 driver 的 UPDATE guard 在這裡一併補上：原本只有 SQLite 有。
        $this->addSqlTrigger(
            'fulfillment_events_append_only_update',
            'fulfillment_events',
            '1 = 1',
            '⛔ 履約事件為 append-only：不得修改',
            'UPDATE',
            useOld: false,
        );

        $this->addSqlTrigger(
            'fulfillment_events_append_only_delete',
            'fulfillment_events',
            '1 = 1',
            $message,
            'DELETE',
            useOld: false,
        );
    }

    /**
     * ⛔ cascade 換成 restrict：時間線不得被父列悄悄帶走。
     *
     * ⛔ SQLite 沒有真正的 ALTER：改 FK 會讓 driver 重建整張表，而重建會把
     * 這張表上既有的 trigger 一併丟掉。M3A-R2 就是在同一個行為上出過事。
     * 所以這裡改完 FK 之後必須把 events 的既有 trigger 全部重新建立，並在
     * 事後驗證它們真的還在。
     */
    private function replaceEventsForeignKeyWithRestrict(): void
    {
        Schema::table('fulfillment_events', function ($table) {
            $table->dropForeign(['fulfillment_order_id']);
            $table->foreign('fulfillment_order_id')
                ->references('id')->on('fulfillment_orders')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'sqlite') {
            $this->restoreEventTriggersLostToTableRebuild();
        }
    }

    /**
     * Rebuild the triggers the FK change destroyed.
     *
     * ⛔ Recreated verbatim from the original migration. If these silently went
     * missing, the timeline would accept edits and events could carry codes
     * outside the allowlist — the exact protections this milestone is meant to
     * strengthen, quietly removed by the act of strengthening them.
     */
    private function restoreEventTriggersLostToTableRebuild(): void
    {
        $codes = "'".implode("','", FulfillmentEventCode::values())."'";
        $statuses = "'".implode("','", FulfillmentStatus::values())."'";

        $condition = "NEW.event_code IN ({$codes})"
            ." AND (NEW.from_status IS NULL OR NEW.from_status IN ({$statuses}))"
            ." AND (NEW.to_status IS NULL OR NEW.to_status IN ({$statuses}))";

        DB::statement("
            CREATE TRIGGER fulfillment_events_values_check_insert
            BEFORE INSERT ON fulfillment_events
            FOR EACH ROW
            WHEN NOT ({$condition})
            BEGIN
                SELECT RAISE(ABORT, 'fulfillment_events: event_code 與 status 必須是 allowlist 中的值');
            END
        ");

        DB::statement("
            CREATE TRIGGER fulfillment_events_append_only_update
            BEFORE UPDATE ON fulfillment_events
            FOR EACH ROW
            BEGIN
                SELECT RAISE(ABORT, 'fulfillment_events 是 append-only：不得修改既有事件');
            END
        ");
    }

    /**
     * One trigger body shared by MySQL, MariaDB and PostgreSQL.
     *
     * ⛔ PostgreSQL needs a trigger function; MySQL takes the body inline. Both
     * raise, neither silently allows.
     */
    private function addSqlTrigger(
        string $name,
        string $table,
        string $condition,
        string $message,
        string $event = 'UPDATE',
        bool $useOld = true,
    ): void {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $pgCondition = str_replace('trim(', 'btrim(', $condition);

            DB::statement("
                CREATE OR REPLACE FUNCTION {$name}_fn() RETURNS trigger AS $$
                BEGIN
                    IF ({$pgCondition}) THEN
                        RAISE EXCEPTION '{$message}';
                    END IF;
                    RETURN ".($event === 'DELETE' ? 'OLD' : 'NEW').';
                END;
                $$ LANGUAGE plpgsql;
            ');

            DB::statement("
                CREATE TRIGGER {$name}
                BEFORE {$event} ON {$table}
                FOR EACH ROW EXECUTE FUNCTION {$name}_fn();
            ");

            return;
        }

        DB::statement("
            CREATE TRIGGER {$name}
            BEFORE {$event} ON {$table}
            FOR EACH ROW
            BEGIN
                IF ({$condition}) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';
                END IF;
            END
        ");
    }

    private function dropGuards(): void
    {
        $triggers = [
            'fulfillment_orders_transition_guard',
            'fulfillment_orders_identifier_guard_insert',
            'fulfillment_orders_identifier_guard_update',
            'fulfillment_events_append_only_delete',
        ];

        if (DB::getDriverName() !== 'sqlite') {
            $triggers[] = 'fulfillment_events_append_only_update';
        }

        foreach ($triggers as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger}");

            if (DB::getDriverName() === 'pgsql') {
                DB::statement("DROP FUNCTION IF EXISTS {$trigger}_fn()");
            }
        }

        // 還原原本的 cascade，讓舊 migration 的 down() 仍可運作。
        if (Schema::hasTable('fulfillment_events')) {
            Schema::table('fulfillment_events', function ($table) {
                $table->dropForeign(['fulfillment_order_id']);
                $table->foreign('fulfillment_order_id')
                    ->references('id')->on('fulfillment_orders')
                    ->cascadeOnDelete();
            });
        }
    }

    private function assertDriverIsSupported(): void
    {
        // ⛔ 未知 driver 就失敗，不靜默略過：略過會讓正式環境完全沒有這道保護。
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql', 'mariadb', 'pgsql'], true)) {
            throw new RuntimeException(
                '⛔ 未支援的資料庫 driver，無法建立 M4A 完整性保護。'
            );
        }
    }
};
