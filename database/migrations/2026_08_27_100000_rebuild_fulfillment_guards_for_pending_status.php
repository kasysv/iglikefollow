<?php

use App\Enums\FulfillmentAttentionReason;
use App\Enums\FulfillmentEventCode;
use App\Enums\FulfillmentPayloadType;
use App\Enums\FulfillmentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild the fulfilment guards so `pending` is a legal status.
 *
 * ⭐ 為什麼需要這一支：`2026_08_17_300002／300003／300004` 的 value allowlist
 * 與 transition guard 都是在**當時執行**時，由 `FulfillmentStatus::values()`
 * 展開成字面值寫進 CHECK／TRIGGER 的。那些字面值已經凍結在資料庫裡——
 * 只改 PHP enum 不會改變它們，於是 `pending` 會被資料庫直接拒絕。
 *
 * ⛔ 已執行過的 migration **一律不編輯**：改動它們對已部署的資料庫毫無作用
 * （它們不會再跑一次），卻會讓新舊環境的 schema 來源不一致。
 *
 * ⭐ 這支是 idempotent 的：
 *
 *  - 全新資料庫（`migrate:fresh`）跑到這裡時，300002／300004 剛剛才用**含有**
 *    `pending` 的 enum 建好 guard，所以這裡等於原樣重建一次。
 *  - 已部署的資料庫（staging）跑到這裡時，舊 guard 缺 `pending`，這裡把它補上。
 *
 * ⛔ 兩種情況都必須得到**同一份**最終 guard，因此這裡不做「判斷有沒有缺」
 * 的聰明事——一律先 drop 再依現行 enum 重建。少了那個一致性，兩種環境會
 * 慢慢長成兩套不同的規則。
 */
return new class extends Migration
{
    /**
     * ⛔ 這些 guard 的定義必須與 300002／300004 完全一致，只差 enum 內容。
     *
     * ⛔ 這裡刻意**重新展開** enum 而不是抄一份字面值：抄字面值等於再凍結
     * 一次，下一個新狀態又要重來。
     */
    public function up(): void
    {
        $this->assertDriverIsSupported();

        $this->rebuildOrderValueGuard();
        $this->rebuildEventValueGuard();
        $this->rebuildTransitionGuard();
        $this->rebuildIdentifierGuard();

        $this->assertGuardsExist();
    }

    /**
     * ⛔⛔ Fail closed：只要已經有 `pending` 資料就拒絕回滾。
     *
     * 舊 guard 不認得 `pending`。若在有 Pending 資料時恢復舊 guard，那些列會
     * 立刻變成資料庫規則下的非法資料——之後任何一次 UPDATE 都會被 abort，
     * 而且是在完全無關的操作上炸開。
     *
     * ⛔ 不自動把 Pending 改成 Submitted、⛔ 不刪事件、⛔ 不清資料：那是拿
     * 真實履約紀錄去遷就一次 schema 回滾。要回滾必須先有 Owner 批准的資料
     * 處置方案。
     */
    public function down(): void
    {
        $this->assertDriverIsSupported();

        $orders = DB::table('fulfillment_orders')
            ->where('status', FulfillmentStatus::Pending->value)
            ->count();

        $events = DB::table('fulfillment_events')
            ->where('from_status', FulfillmentStatus::Pending->value)
            ->orWhere('to_status', FulfillmentStatus::Pending->value)
            ->count();

        if ($orders > 0 || $events > 0) {
            throw new RuntimeException(
                '⛔ 已有 pending 履約資料，無法恢復舊 guard：'
                ."fulfillment_orders={$orders}、fulfillment_events={$events}。"
                .'⛔ 不得自動改名或刪除資料；請先取得 Owner 批准的資料處置方案。'
            );
        }

        /*
         * 沒有 Pending 資料才走到這裡：用**不含** `pending` 的舊 allowlist
         * 重建，等同恢復 300002／300004 當初的狀態。
         */
        $legacy = array_values(array_filter(
            FulfillmentStatus::values(),
            fn (string $value): bool => $value !== FulfillmentStatus::Pending->value,
        ));

        $this->rebuildOrderValueGuard($legacy);
        $this->rebuildEventValueGuard($legacy);
        $this->rebuildTransitionGuard($legacy);
        // ⛔ identifier guard 也恢復成只要求 `submitted`。
        $this->rebuildIdentifierGuard(includePending: false);
    }

    /**
     * `fulfillment_orders` 的 value allowlist。
     *
     * ⛔ 條件與 300002 逐字相同（含 submitted 必須有 provider_order_id 那一段），
     * 只有 `$statuses` 的內容不同。
     *
     * @param  list<string>|null  $statuses  null 代表使用現行 enum
     */
    private function rebuildOrderValueGuard(?array $statuses = null): void
    {
        $statusList = $this->quote($statuses ?? FulfillmentStatus::values());
        $reasons = $this->quote(FulfillmentAttentionReason::values());
        $payloads = $this->quote(FulfillmentPayloadType::values());

        $condition = fn (string $p) => "{$p}status IN ({$statusList})"
            ." AND ({$p}attention_code IS NULL OR {$p}attention_code IN ({$reasons}))"
            ." AND ({$p}payload_type_snapshot IS NULL OR {$p}payload_type_snapshot IN ({$payloads}))"
            ." AND {$p}attempt_count >= 0"
            /*
             * ⛔ 已送出就必須有供應商單號。
             *
             * ⛔ 這一段**不因 `pending` 而放寬**：`pending` 是 post-submit 狀態，
             * 一定已經有 provider order ID。下方的 identifier guard（300004）
             * 另外強制它非空白，這裡維持與 300002 相同的條件不動。
             */
            ." AND NOT ({$p}status = 'submitted' AND {$p}provider_order_id IS NULL)";

        $message = 'fulfillment_orders: status／attention_code／payload_type 必須合法，'
            .'且 submitted 必須具備 provider_order_id';

        if (DB::getDriverName() === 'sqlite') {
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
                DB::statement("DROP TRIGGER IF EXISTS fulfillment_orders_values_check_{$suffix}");
                DB::statement("
                    CREATE TRIGGER fulfillment_orders_values_check_{$suffix}
                    BEFORE {$event} ON fulfillment_orders
                    FOR EACH ROW
                    WHEN NOT ({$condition('NEW.')})
                    BEGIN
                        SELECT RAISE(ABORT, '{$message}');
                    END
                ");
            }

            return;
        }

        $this->dropCheck('fulfillment_orders', 'fulfillment_orders_values_check');

        DB::statement(
            'ALTER TABLE fulfillment_orders ADD CONSTRAINT fulfillment_orders_values_check CHECK ('
            .$condition('').')'
        );
    }

    /**
     * `fulfillment_events` 的 value allowlist。
     *
     * @param  list<string>|null  $statuses
     */
    private function rebuildEventValueGuard(?array $statuses = null): void
    {
        $codes = $this->quote(FulfillmentEventCode::values());
        $statusList = $this->quote($statuses ?? FulfillmentStatus::values());

        $condition = fn (string $p) => "{$p}event_code IN ({$codes})"
            ." AND ({$p}from_status IS NULL OR {$p}from_status IN ({$statusList}))"
            ." AND ({$p}to_status IS NULL OR {$p}to_status IN ({$statusList}))";

        $message = 'fulfillment_events: event_code 與 status 必須是 allowlist 中的值';

        if (DB::getDriverName() === 'sqlite') {
            /*
             * ⛔ 只有 INSERT：UPDATE 由 append-only trigger 全面阻擋。
             * ⛔ 這裡**不碰** append-only trigger——它與 status 無關，
             * 重建它只會多一次出錯的機會。
             */
            DB::statement('DROP TRIGGER IF EXISTS fulfillment_events_values_check_insert');
            DB::statement("
                CREATE TRIGGER fulfillment_events_values_check_insert
                BEFORE INSERT ON fulfillment_events
                FOR EACH ROW
                WHEN NOT ({$condition('NEW.')})
                BEGIN
                    SELECT RAISE(ABORT, '{$message}');
                END
            ");

            return;
        }

        $this->dropCheck('fulfillment_events', 'fulfillment_events_values_check');

        DB::statement(
            'ALTER TABLE fulfillment_events ADD CONSTRAINT fulfillment_events_values_check CHECK ('
            .$condition('').')'
        );
    }

    /**
     * The illegal-transition denylist.
     *
     * ⛔ 與 300004 相同：雙重迴圈展開所有 `canTransitionTo()` 為 false 的
     * from→to 組合。⛔ 這裡不手寫配對表——手寫的表會與 enum 的規則漂移，
     * 而那正是「PHP 說可以、資料庫說不行」這種難查問題的來源。
     *
     * @param  list<string>|null  $statuses  限定只考慮這些狀態（down 用）
     */
    private function rebuildTransitionGuard(?array $statuses = null): void
    {
        $cases = FulfillmentStatus::cases();

        if ($statuses !== null) {
            $cases = array_values(array_filter(
                $cases,
                fn (FulfillmentStatus $case): bool => in_array($case->value, $statuses, true),
            ));
        }

        $illegal = [];

        foreach ($cases as $from) {
            foreach ($cases as $to) {
                if (! $from->canTransitionTo($to)) {
                    $illegal[] = "(OLD.status = '{$from->value}' AND NEW.status = '{$to->value}')";
                }
            }
        }

        $condition = implode(' OR ', $illegal);
        $message = '⛔ 不允許的履約狀態轉移：已終止或已送出的紀錄不得回到送出前的狀態';

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS fulfillment_orders_transition_guard');
            DB::statement("
                CREATE TRIGGER fulfillment_orders_transition_guard
                BEFORE UPDATE OF status ON fulfillment_orders
                FOR EACH ROW
                WHEN ({$condition})
                BEGIN
                    SELECT RAISE(ABORT, '{$message}');
                END
            ");

            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS fulfillment_orders_transition_guard ON fulfillment_orders');
            DB::statement("
                CREATE OR REPLACE FUNCTION fulfillment_orders_transition_guard_fn() RETURNS trigger AS $$
                BEGIN
                    IF ({$condition}) THEN
                        RAISE EXCEPTION '{$message}';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            ");
            DB::statement('
                CREATE TRIGGER fulfillment_orders_transition_guard
                BEFORE UPDATE ON fulfillment_orders
                FOR EACH ROW EXECUTE FUNCTION fulfillment_orders_transition_guard_fn();
            ');

            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS fulfillment_orders_transition_guard');
        DB::statement("
            CREATE TRIGGER fulfillment_orders_transition_guard
            BEFORE UPDATE ON fulfillment_orders
            FOR EACH ROW
            BEGIN
                IF ({$condition}) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';
                END IF;
            END
        ");
    }

    /**
     * The "must have a provider order id" guard.
     *
     * ⭐ 施工單指定：`pending` 也必須具備非空白的 provider order ID。
     *
     * ⛔ 300004 的原版只列了 `'submitted'`。若不一起重建，一筆
     * 「pending 但沒有單號」的紀錄就能寫進資料庫——那是無法對帳的：
     * 我們宣稱對方正在排隊，卻沒有任何東西可以拿去問對方。
     *
     * ⛔⛔ 只擴充到 `pending`，⛔ **不放寬**任何送出前狀態
     * （`ready`／`submitting`／`configuration_pending` 仍然可以沒有單號——
     * 它們本來就還沒送出）。
     *
     * @param  bool  $includePending  false 代表恢復 300004 的原版（down 用）
     */
    private function rebuildIdentifierGuard(bool $includePending = true): void
    {
        $statuses = $includePending
            ? "'submitted','pending'"
            : "'submitted'";

        $bad = "NEW.status IN ({$statuses})"
            .' AND (NEW.provider_order_id IS NULL OR trim(NEW.provider_order_id) = \'\')';

        $blank = "NEW.provider_order_id IS NOT NULL AND trim(NEW.provider_order_id) = ''";

        $message = '⛔ 已送出的履約紀錄必須具備非空白的供應商單號';

        if (DB::getDriverName() === 'sqlite') {
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
                DB::statement("DROP TRIGGER IF EXISTS fulfillment_orders_identifier_guard_{$suffix}");
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

        $condition = "({$bad}) OR ({$blank})";

        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
            $name = "fulfillment_orders_identifier_guard_{$suffix}";

            if (DB::getDriverName() === 'pgsql') {
                DB::statement("DROP TRIGGER IF EXISTS {$name} ON fulfillment_orders");
                $pgCondition = str_replace('trim(', 'btrim(', $condition);
                DB::statement("
                    CREATE OR REPLACE FUNCTION {$name}_fn() RETURNS trigger AS $$
                    BEGIN
                        IF ({$pgCondition}) THEN
                            RAISE EXCEPTION '{$message}';
                        END IF;
                        RETURN NEW;
                    END;
                    $$ LANGUAGE plpgsql;
                ");
                DB::statement("
                    CREATE TRIGGER {$name}
                    BEFORE {$event} ON fulfillment_orders
                    FOR EACH ROW EXECUTE FUNCTION {$name}_fn();
                ");

                continue;
            }

            DB::statement("DROP TRIGGER IF EXISTS {$name}");
            DB::statement("
                CREATE TRIGGER {$name}
                BEFORE {$event} ON fulfillment_orders
                FOR EACH ROW
                BEGIN
                    IF ({$condition}) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';
                    END IF;
                END
            ");
        }
    }

    /**
     * ⛔ MySQL／PG 的 CHECK 必須先移除才能重建；名稱不存在時不得整支失敗。
     */
    private function dropCheck(string $table, string $name): void
    {
        $sql = DB::getDriverName() === 'pgsql'
            ? "ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}"
            : "ALTER TABLE {$table} DROP CHECK {$name}";

        try {
            DB::statement($sql);
        } catch (Throwable) {
            /*
             * ⛔ 刻意吞掉：MySQL 沒有 `DROP CHECK IF EXISTS`，而這支 migration
             * 必須能在「guard 已存在」與「guard 不存在」兩種資料庫上都跑完。
             * ⛔ 這裡不會遮蔽真正的問題——重建失敗會在下一行的 ADD CONSTRAINT
             * 直接拋出。
             */
        }
    }

    /** @param  list<string>  $values */
    private function quote(array $values): string
    {
        return "'".implode("','", $values)."'";
    }

    /**
     * ⛔ 重建後確認 guard 真的在。
     *
     * 一支「跑完了但其實少建一個 trigger」的 migration，會讓所有人以為
     * 保護還在。
     */
    private function assertGuardsExist(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $expected = [
            'fulfillment_orders_values_check_insert',
            'fulfillment_orders_values_check_update',
            'fulfillment_events_values_check_insert',
            'fulfillment_orders_transition_guard',
            'fulfillment_orders_identifier_guard_insert',
            'fulfillment_orders_identifier_guard_update',
        ];

        $existing = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->pluck('name')
            ->all();

        $missing = array_diff($expected, $existing);

        if ($missing !== []) {
            throw new RuntimeException(
                '⛔ 履約 guard 重建後仍缺少：'.implode('、', $missing)
            );
        }
    }

    private function assertDriverIsSupported(): void
    {
        // ⛔ 未知 driver 就失敗，不靜默略過：略過會讓正式環境完全沒有這道保護。
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql', 'mariadb', 'pgsql'], true)) {
            throw new RuntimeException(
                '⛔ 未支援的資料庫 driver，無法重建履約 guard。'
            );
        }
    }
};
