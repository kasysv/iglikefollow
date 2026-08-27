<?php

use App\Enums\FulfillmentEventCode;
use App\Enums\FulfillmentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-batch fulfilment: one order item may have a chain of replacement rows.
 *
 * ⭐ Owner 會先自行在 TheMostPanel 後台取消舊單，再於本站輸入新連結與新數量，
 * 由本站建立**新的一批**履約。舊批次不改寫、不刪除，仍由既有排程繼續同步。
 *
 * ⛔⛔ 這支 migration 最敏感的一件事：拿掉 `order_item_id` 的單欄 unique。
 *
 * 那個 index 原本是「一個商品項目最多一筆履約」的**最終防線**——重送的事件、
 * 重試的 job 或兩個並行 worker 都被它擋在資料庫層。拿掉它而不補等價保護，
 * 等於把防重複派單降級成只靠應用層判斷。
 *
 * ⭐ 因此改為 unique `(order_item_id, sequence_no)`：
 *
 *  - `order_item_id` 仍是**前導欄位**，既有 FK 仍有可用 index（MySQL 需要）；
 *  - 初始批次的 `sequence_no` 恆為 1，所以「同一個商品項目的第一筆履約」
 *    仍然只可能有一筆——原本那道防線在初始派單上**完全沒有被放寬**；
 *  - 第 2、3 批各自有不同的 sequence，因此可以並存。
 *
 * ⛔ 另加 `replaces_fulfillment_order_id` 的 unique：一個 parent 最多一個
 * 直接 child。少了它，兩個並行請求可以各自建立一個 child，同一筆訂單就會被
 * 送去供應商兩次——那是真正會花錢的錯誤。
 */
return new class extends Migration
{
    /** ⛔ 只有這張表的這些 guard 屬於本支 migration 的職責。 */
    private const ORDER_ITEM_UNIQUE = 'fulfillment_orders_order_item_id_unique';

    private const CHAIN_UNIQUE = 'fulfillment_orders_item_sequence_unique';

    private const PARENT_UNIQUE = 'fulfillment_orders_replaces_unique';

    private const SHAPE_GUARD = 'fulfillment_orders_replacement_shape_guard';

    public function up(): void
    {
        $this->assertDriverIsSupported();

        Schema::table('fulfillment_orders', function (Blueprint $table): void {
            /*
             * ⛔ 既有列一律視為第 1 批：default 1 讓升級不需要 backfill 腳本，
             * 也讓「sequence 1 = 原始列」成為一條可驗證的規則。
             */
            $table->unsignedInteger('sequence_no')->default(1)->after('order_item_id');

            /*
             * ⛔ self FK：child 指向 parent。`restrictOnDelete` 而不是 cascade
             * ——履約歷史是派單證據，⛔ 不得因為刪一列就連帶消失。
             */
            $table->foreignId('replaces_fulfillment_order_id')
                ->nullable()
                ->after('sequence_no')
                ->constrained('fulfillment_orders')
                ->restrictOnDelete();

            /*
             * ⛔ `text` ＋ Eloquent `encrypted` cast：新連結是客人的交付目標，
             * 與 `order_items.target_value` 同等敏感，⛔ 不得明文落盤。
             * ⛔ 用 text 而非 string(255)：密文比明文長。
             */
            $table->text('target_value_override')->nullable()->after('replaces_fulfillment_order_id');

            $table->unsignedInteger('quantity_override')->nullable()->after('target_value_override');

            /*
             * ⛔ 允許 0：`provider_remains` 可能就是 0（全部補完後才取消）。
             * 這一欄只是**當時給 Owner 看的建議值快照**，不參與任何判斷。
             */
            $table->unsignedInteger('suggested_quantity_snapshot')->nullable()->after('quantity_override');

            $table->foreignId('replacement_created_by_user_id')
                ->nullable()
                ->after('suggested_quantity_snapshot')
                ->constrained('users')
                ->restrictOnDelete();
        });

        $this->swapOrderItemUnique();
        $this->addParentUnique();
        $this->addShapeGuard();

        /*
         * ⛔⛔ 必須重建 `fulfillment_orders` 上的既有 guard。
         *
         * SQLite 的 `ALTER TABLE`（尤其加欄位＋改 index）會**重建整張表**，
         * 而重建會把該表上的所有 trigger 一起帶走——`300004` 的註解已經記錄過
         * 這個陷阱（它自己也因此得重建 events 的 trigger）。
         *
         * ⛔ 我實測確認過：不重建的話，`values_check`、`transition_guard` 與
         * `identifier_guard` 全部消失，而測試會以「原本該被 DB 拒絕的寫入
         * 竟然成功了」的形式失敗。⛔ 一支把既有保護悄悄拆掉、卻回報成功的
         * migration，比直接失敗危險得多。
         */
        $this->restoreOrderGuardsLostToTableRebuild();

        /*
         * ⛔⛔ 事件 allowlist 必須在**最後**重建。
         *
         * 上面那支 guard migration 自己也會重建 `fulfillment_events` 的
         * value check——而它是用**它當時的** enum 快照寫的。若先重建再呼叫它，
         * 我們新增的 `REPLACEMENT_CREATED` 會被它覆蓋掉，於是 child 的建立
         * 事件會被資料庫拒絕。
         *
         * ⛔ 我實測遇到過這個順序問題：測試以「child 沒有 CREATED 事件」
         * 的形式失敗，而真正的原因在兩支 migration 的執行順序。
         */
        $this->rebuildEventValueGuard();

        $this->assertGuardsExist();
    }

    /**
     * ⛔⛔ Fail closed：只要已經有任何 replacement 就拒絕回滾。
     *
     * 那些列是**真實的派單證據**——我們真的把一筆訂單送去了供應商。
     * ⛔ 不得為了讓 schema 回得去而刪除、合併或改寫它們。
     */
    public function down(): void
    {
        $this->assertDriverIsSupported();

        $replacements = DB::table('fulfillment_orders')->where('sequence_no', '>', 1)->count();

        if ($replacements > 0) {
            throw new RuntimeException(
                "⛔ 已有 {$replacements} 筆更換履約，無法回滾此 migration。"
                .'這些是真實的派單紀錄；⛔ 不得刪除或合併履約歷史來強行 rollback。'
                .'請先取得 Owner 批准的資料處置方案。'
            );
        }

        $this->dropShapeGuard();
        $this->dropIndexIfExists('fulfillment_orders', self::PARENT_UNIQUE);
        $this->dropIndexIfExists('fulfillment_orders', self::CHAIN_UNIQUE);

        Schema::table('fulfillment_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('replacement_created_by_user_id');
            $table->dropColumn('suggested_quantity_snapshot');
            $table->dropColumn('quantity_override');
            $table->dropColumn('target_value_override');
            $table->dropConstrainedForeignId('replaces_fulfillment_order_id');
            $table->dropColumn('sequence_no');
        });

        // ⛔ 恢復原本的單欄 unique：沒有 replacement 時它與新規則等價。
        Schema::table('fulfillment_orders', function (Blueprint $table): void {
            $table->unique('order_item_id', self::ORDER_ITEM_UNIQUE);
        });

        /*
         * ⛔⛔ R1 修正：SQLite 的 `down()` 同樣會重建整張表並帶走所有 trigger。
         *
         * ⭐ 初版只在 `up()` 補了這件事，`down()` 沒有——於是回滾之後，
         * `values_check`／`transition_guard`／`identifier_guard` 全部消失，
         * 資料庫進入一個**完全沒有履約保護**的狀態。
         *
         * ⛔ 原本的 rollback 測試看不出來：它 `down()` 之後只檢查欄位不見了，
         * 就立刻再 `up()` 把 guard 裝回去——那個「中間的無保護狀態」從來沒有
         * 被檢查過。一支讓資料庫變成無保護、卻回報成功的 rollback，
         * 比直接失敗危險得多。
         */
        $this->restoreOrderGuardsLostToTableRebuild();

        $this->rebuildEventValueGuard(legacy: true);
    }

    /**
     * Replace the single-column unique with the composite.
     *
     * ⛔ 順序很重要：**先建**新的 composite（`order_item_id` 為前導欄位），
     * 再刪舊的單欄 unique。MySQL 的 FK 需要一個以該欄位為前導的 index；
     * 反過來做會在刪除時因「FK 失去可用 index」而失敗。
     */
    private function swapOrderItemUnique(): void
    {
        Schema::table('fulfillment_orders', function (Blueprint $table): void {
            $table->unique(['order_item_id', 'sequence_no'], self::CHAIN_UNIQUE);
        });

        $this->dropIndexIfExists('fulfillment_orders', self::ORDER_ITEM_UNIQUE);
    }

    /** ⛔ 一個 parent 最多一個直接 child——這是防止併發雙擊送出兩次的關鍵。 */
    private function addParentUnique(): void
    {
        Schema::table('fulfillment_orders', function (Blueprint $table): void {
            $table->unique('replaces_fulfillment_order_id', self::PARENT_UNIQUE);
        });
    }

    /**
     * The shape rule for the two kinds of row.
     *
     * ⛔ sequence 1（原始列）：replacement 欄位**必須全部為 null**。
     * ⛔ sequence >1（更換列）：必須具備 parent、encrypted target、正整數
     *    actual quantity、suggested snapshot 與建立者 ID。
     *
     * ⭐ 放在 DB 而不只是應用層：一筆缺 parent 的 sequence 2 會讓 chain 斷裂，
     * 而那種資料一旦寫進去，後續每一次讀取都要處理一個不該存在的狀態。
     */
    private function addShapeGuard(): void
    {
        /*
         * ⛔ 條件描述的是**非法**的形狀（與既有 transition guard 同一風格）。
         *
         * ⛔ `suggested_quantity_snapshot` 只檢查 NOT NULL，⛔ 不要求 > 0：
         * `provider_remains` 可能真的是 0。
         */
        $p = $this->prefix();

        /*
         * ⛔ 改為「一組非法情況」再 OR 起來，⛔ 不再手工拼接巢狀括號。
         *
         * ⭐ R1 施工時我用字串接了一次括號、以為對了，實際生成的 SQL 少一個
         * 右括號（我在寫入前把條件印出來檢查才發現）。這種錯誤在 migration
         * 裡特別危險：它可能在某些 driver 上仍然「跑得過」，但保護的範圍
         * 與預期不同。分組之後每一段自己閉合，⛔ 不依賴人眼數括號。
         */
        $replacementColumns = [
            'replaces_fulfillment_order_id',
            'target_value_override',
            'quantity_override',
            'suggested_quantity_snapshot',
            'replacement_created_by_user_id',
        ];

        /*
         * ⛔⛔ R1 修正：`sequence_no < 1` 明確列為非法。
         *
         * ⭐ 初版只處理 `= 1` 與 `> 1` 兩種情況——`0` 兩邊都不符合，於是整條
         * 檢查被**跳過**，一筆 sequence 0 的列可以直接寫進去。
         * GPT 已以 fresh SQLite migration 實證（`SEQUENCE_ZERO_ACCEPTED`）。
         *
         * ⛔ 不能只靠 unsigned 欄位：SQLite 的 `unsigned` 只是型別親和性提示，
         * 不是約束。
         */
        $conditions = ["{$p}sequence_no < 1"];

        // ⛔ 第 1 批不得帶任何更換欄位。
        $firstBatchHasReplacementData = implode(' OR ', array_map(
            fn (string $c): string => "{$p}{$c} IS NOT NULL",
            $replacementColumns,
        ));
        $conditions[] = "{$p}sequence_no = 1 AND ({$firstBatchHasReplacementData})";

        // ⛔ 第 2 批以後必須齊備，且數量必須是正整數。
        $replacementMissingData = implode(' OR ', array_merge(
            array_map(fn (string $c): string => "{$p}{$c} IS NULL", $replacementColumns),
            ["{$p}quantity_override < 1"],
        ));
        $conditions[] = "{$p}sequence_no > 1 AND ({$replacementMissingData})";

        $illegal = '('.implode(') OR (', $conditions).')';

        $message = '⛔ 更換履約的資料形狀不合法：第 1 批不得有更換欄位；'
            .'第 2 批以後必須有 parent、新連結、正整數數量、建議值與建立者。';

        if (DB::getDriverName() === 'sqlite') {
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
                DB::statement('DROP TRIGGER IF EXISTS '.self::SHAPE_GUARD."_{$suffix}");
                DB::statement('
                    CREATE TRIGGER '.self::SHAPE_GUARD."_{$suffix}
                    BEFORE {$event} ON fulfillment_orders
                    FOR EACH ROW
                    WHEN ({$illegal})
                    BEGIN
                        SELECT RAISE(ABORT, '{$message}');
                    END
                ");
            }

            return;
        }

        /*
         * MySQL／MariaDB／PostgreSQL：用 CHECK constraint。
         *
         * ⛔ 這個條件只看 `NEW.`／目前列，沒有 `OLD.`，因此 CHECK 就夠了，
         * ⛔ 不需要 trigger（trigger 的維護成本與跨 driver 差異都更高）。
         */
        $condition = str_replace('NEW.', '', $illegal);

        DB::statement(
            'ALTER TABLE fulfillment_orders ADD CONSTRAINT '.self::SHAPE_GUARD
            ." CHECK (NOT ({$condition}))"
        );
    }

    private function dropShapeGuard(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['insert', 'update'] as $suffix) {
                DB::statement('DROP TRIGGER IF EXISTS '.self::SHAPE_GUARD."_{$suffix}");
            }

            return;
        }

        $this->dropCheckConstraint('fulfillment_orders', self::SHAPE_GUARD);
    }

    private function prefix(): string
    {
        return DB::getDriverName() === 'sqlite' ? 'NEW.' : '';
    }

    /**
     * Re-create the guards that a SQLite table rebuild silently dropped.
     *
     * ⭐ 直接**重跑前一支 guard migration 的 `up()`**，⛔ 不是在這裡抄一份
     * 定義。抄一份就等於同一組規則有兩個來源，某天只改一處就會出現
     * 「新環境有這道保護、舊環境沒有」的差異。
     *
     * ⛔ 那支 migration 本身就是為了「依現行 enum 重建 guard」而寫的，
     * 而且是 idempotent 的——重跑一次得到的結果與它自己剛跑完時完全相同。
     *
     * ⛔ 只在 SQLite 需要：MySQL／MariaDB／PostgreSQL 的 `ALTER TABLE` 不會
     * 重建整張表，CHECK constraint 與 trigger 都原地保留。
     */
    private function restoreOrderGuardsLostToTableRebuild(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $guards = require database_path(
            'migrations/2026_08_27_100000_rebuild_fulfillment_guards_for_pending_status.php'
        );

        $guards->up();
    }

    /**
     * Rebuild the events value allowlist for the new `REPLACEMENT_CREATED` code.
     *
     * ⛔ 同 `2026_08_27_100000` 的理由：那份 allowlist 是**當時執行**時把
     * enum 展開成字面值寫進去的，已凍結在資料庫裡；只改 PHP enum 不會改變它。
     *
     * @param  bool  $legacy  true 代表用不含新 event code 的舊清單（down 用）
     */
    private function rebuildEventValueGuard(bool $legacy = false): void
    {
        $codes = FulfillmentEventCode::values();

        if ($legacy) {
            $codes = array_values(array_filter(
                $codes,
                fn (string $c): bool => $c !== FulfillmentEventCode::ReplacementCreated->value,
            ));
        }

        $codeList = "'".implode("','", $codes)."'";
        $statusList = "'".implode("','", FulfillmentStatus::values())."'";

        $condition = fn (string $p) => "{$p}event_code IN ({$codeList})"
            ." AND ({$p}from_status IS NULL OR {$p}from_status IN ({$statusList}))"
            ." AND ({$p}to_status IS NULL OR {$p}to_status IN ({$statusList}))";

        $message = 'fulfillment_events: event_code 與 status 必須是 allowlist 中的值';

        if (DB::getDriverName() === 'sqlite') {
            // ⛔ 只有 INSERT：UPDATE 由既有 append-only trigger 全面阻擋。
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

        $this->dropCheckConstraint('fulfillment_events', 'fulfillment_events_values_check');

        DB::statement(
            'ALTER TABLE fulfillment_events ADD CONSTRAINT fulfillment_events_values_check CHECK ('
            .$condition('').')'
        );
    }

    /**
     * ⛔ 每個 driver 用它**真正支援**的語法（R2 的教訓）。
     *
     * MariaDB 官方用 `DROP CONSTRAINT`，⛔ 不是 MySQL 專屬的 `DROP CHECK`。
     * ⛔ 先精確確認存在再 drop，⛔ 不用 catch 吞掉權限／鎖／語法錯誤。
     */
    private function dropCheckConstraint(string $table, string $name): void
    {
        $driver = DB::getDriverName();

        $exists = $driver === 'pgsql'
            ? DB::table('information_schema.table_constraints')
                ->where('table_name', $table)->where('constraint_name', $name)
                ->where('constraint_type', 'CHECK')->exists()
            : DB::table('information_schema.TABLE_CONSTRAINTS')
                ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', $table)->where('CONSTRAINT_NAME', $name)
                ->where('CONSTRAINT_TYPE', 'CHECK')->exists();

        if (! $exists) {
            return;
        }

        DB::statement(match ($driver) {
            'pgsql', 'mariadb' => "ALTER TABLE {$table} DROP CONSTRAINT {$name}",
            'mysql' => "ALTER TABLE {$table} DROP CHECK {$name}",
            default => throw new RuntimeException("⛔ 未支援的 driver：{$driver}"),
        });
    }

    /**
     * ⛔ 只在 index 真的存在時才 drop。
     *
     * SQLite 與 MySQL 對「刪除不存在的 index」的反應不同，⛔ 而我們不能用
     * catch 吞掉錯誤——那會連權限與鎖的問題一起吃掉。
     */
    private function dropIndexIfExists(string $table, string $name): void
    {
        if (! $this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name): void {
            $blueprint->dropIndex($name);
        });
    }

    private function indexExists(string $table, string $name): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::table('sqlite_master')
                ->where('type', 'index')->where('name', $name)->exists();
        }

        if (DB::getDriverName() === 'pgsql') {
            return DB::table('pg_indexes')
                ->where('tablename', $table)->where('indexname', $name)->exists();
        }

        return DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $name)
            ->exists();
    }

    /** ⛔ 重建後確認 guard 真的在；少建一個會讓所有人以為保護還在。 */
    private function assertGuardsExist(): void
    {
        if (! $this->indexExists('fulfillment_orders', self::CHAIN_UNIQUE)) {
            throw new RuntimeException('⛔ 缺少 '.self::CHAIN_UNIQUE);
        }

        if (! $this->indexExists('fulfillment_orders', self::PARENT_UNIQUE)) {
            throw new RuntimeException('⛔ 缺少 '.self::PARENT_UNIQUE);
        }

        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $triggers = DB::table('sqlite_master')->where('type', 'trigger')->pluck('name')->all();

        foreach ([self::SHAPE_GUARD.'_insert', self::SHAPE_GUARD.'_update',
            'fulfillment_events_values_check_insert'] as $expected) {
            if (! in_array($expected, $triggers, true)) {
                throw new RuntimeException("⛔ 缺少 trigger：{$expected}");
            }
        }
    }

    private function assertDriverIsSupported(): void
    {
        // ⛔ 未知 driver 就失敗，不靜默略過：略過會讓正式環境完全沒有這道保護。
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql', 'mariadb', 'pgsql'], true)) {
            throw new RuntimeException('⛔ 未支援的資料庫 driver，無法建立更換履約的保護。');
        }
    }
};
