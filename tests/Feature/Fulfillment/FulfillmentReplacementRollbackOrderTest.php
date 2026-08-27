<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\FulfillmentStatus;
use App\Models\FulfillmentOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The order in which `down()` swaps the two indexes.
 *
 * ⛔⛔ `fulfillment_orders.order_item_id` 有外鍵。MySQL 8.0 官方明確規定：
 * referencing foreign-key columns 必須持續具備相同順序的**前導索引**，
 * 而且不能直接刪除外鍵仍需要的索引。
 * https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html
 *
 * `up()` 已經做對了：**先建** composite `(order_item_id, sequence_no)`，
 * 再刪舊的單欄 unique——外鍵在交換的每一刻都有可用的前導索引。
 *
 * ⛔ 但 `down()` 的順序是相反的：先刪 composite，最後才重建單欄 unique。
 * 那中間的一段時間，`order_item_id` 沒有任何可用前導索引——SQLite 不在乎，
 * **MySQL 會直接拒絕那個 DDL**。staging 已確認是 MySQL 8.0.42。
 *
 * ⭐ 因此這個檔案用 **DB query listener 擷取實際送出的 SQL**，
 * ⛔ 不看註解、⛔ 不讀原始碼字串——那兩者都證明不了 runtime 真正做了什麼。
 */
class FulfillmentReplacementRollbackOrderTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY_UNIQUE = 'fulfillment_orders_order_item_id_unique';

    private const CHAIN_UNIQUE = 'fulfillment_orders_item_sequence_unique';

    private const PARENT_UNIQUE = 'fulfillment_orders_replaces_unique';

    private const SUPPORT_INDEX = 'fulfillment_orders_replaces_rollback_fk_index';

    private const SHAPE_GUARD = 'fulfillment_orders_replacement_shape_guard';

    private function migration(): object
    {
        return require database_path(
            'migrations/2026_08_27_200000_enable_fulfillment_replacements.php'
        );
    }

    /**
     * Run a callback while recording every statement that touches our indexes.
     *
     * @return list<string> 依實際執行順序的 SQL
     */
    private function captureIndexSql(callable $callback): array
    {
        $statements = [];

        DB::listen(function ($query) use (&$statements): void {
            $sql = $query->sql;

            // 只留下與這兩個 index 有關的 DDL。
            if (str_contains($sql, self::LEGACY_UNIQUE) || str_contains($sql, self::CHAIN_UNIQUE)) {
                $statements[] = $sql;
            }
        });

        try {
            $callback();
        } finally {
            // ⛔ 移除 listener，避免影響同一程序中的其他測試。
            DB::flushQueryLog();
        }

        return $statements;
    }

    /**
     * Record every statement, unfiltered, in execution order.
     *
     * ⭐ 與 `captureIndexSql()` 不同：R3 要證明的是**跨多個物件**的相對順序
     * （self-FK support index、parent unique、order-item unique、shape guard、
     * 欄位移除），⛔ 因此不能只留下兩個 index 名稱的敘述。
     *
     * @return list<string>
     */
    private function captureAllSql(callable $callback): array
    {
        $statements = [];

        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        try {
            $callback();
        } finally {
            DB::flushQueryLog();
        }

        return $statements;
    }

    /**
     * ⛔ 第一個符合 `$needle`（且通過 `$kind` 判定）的敘述位置。
     */
    private function firstIndexOf(array $statements, string $needle, ?callable $kind = null): ?int
    {
        foreach ($statements as $index => $sql) {
            if (! str_contains($sql, $needle)) {
                continue;
            }

            if ($kind !== null && ! $kind($sql)) {
                continue;
            }

            return $index;
        }

        return null;
    }

    /**
     * ⛔⛔ staging MySQL 8.0.42 第一方失敗證據（2026-08-27）：
     *
     * ```text
     * SQLSTATE[HY000]: General error: 1553
     * Cannot drop index 'fulfillment_orders_replaces_unique':
     * needed in a foreign key constraint
     * ```
     *
     * ⭐ 根因：`replaces_fulfillment_order_id` 是 **self-referencing FK**。
     * `up()` 建立 parent unique 之後，MySQL 認為原本的隱式 FK index 冗餘而
     * 移除它——於是 `fulfillment_orders_replaces_unique` 成為該 FK
     * **唯一可用的索引**，不能先刪。
     *
     * ⛔ SQLite 完全不建立 FK index（我已實測：`constrained()` 之後
     * `sqlite_master` 裡一個 index 都沒有），所以本機永遠不會重現這個失敗。
     * ⭐ 因此這條測試改為釘住**操作順序**——那是 MySQL 規則要求的前提。
     *
     * ⛔ 正確解法是先補一個明確的非 unique support index，⛔ 不是關閉
     * `FOREIGN_KEY_CHECKS`、⛔ 不是 drop／重建 self-FK（第二個 DDL 若失敗會
     * 留下 FK 已消失的 partial state），⛔ 也不是吞掉 DDL 錯誤。
     */
    public function test_the_rollback_supports_the_self_fk_before_dropping_the_parent_unique(): void
    {
        $migration = $this->migration();

        $statements = $this->captureAllSql(fn () => $migration->down());

        try {
            $createSupport = $this->firstIndexOf($statements, self::SUPPORT_INDEX, $this->isCreate(...));
            $dropParent = $this->firstIndexOf($statements, self::PARENT_UNIQUE, $this->isDrop(...));

            $this->assertNotNull(
                $createSupport,
                '⛔ rollback 必須先為 self-FK 建立暫時 support index：'.self::SUPPORT_INDEX,
            );
            $this->assertNotNull($dropParent, '⛔ rollback 必須移除 parent unique。');

            $this->assertLessThan(
                $dropParent,
                $createSupport,
                '⛔⛔ 必須先建立 self-FK support index，再刪除 parent unique；'
                ."否則 MySQL 會以 errno 1553 拒絕該 DDL。\n實際順序：\n"
                .implode("\n", $statements),
            );
        } finally {
            $migration->up();
        }
    }

    /**
     * ⛔⛔ shape guard 必須**延後**到所有索引交換成功之後才移除。
     *
     * ⭐ staging 的實際傷害正是這一點造成的：MySQL 的 DDL 會自動提交，
     * `down()` 最前面的 `dropShapeGuard()` 已經生效，後面的 parent unique
     * drop 才失敗——結果 migration 仍標記 Ran，但資料表已經少了一道 CHECK，
     * 形成 partial rollback。GPT 必須手動以原 `addShapeGuard()` 恢復。
     *
     * ⛔ 把破壞性最大、最難復原的一步排在最後，失敗時才不會留下半改過的 schema。
     */
    public function test_the_rollback_drops_the_shape_guard_only_after_the_index_swaps(): void
    {
        $migration = $this->migration();

        $statements = $this->captureAllSql(fn () => $migration->down());

        try {
            $dropShapeGuard = $this->firstIndexOf($statements, self::SHAPE_GUARD, $this->isDrop(...));
            $dropParent = $this->firstIndexOf($statements, self::PARENT_UNIQUE, $this->isDrop(...));
            $dropChain = $this->firstIndexOf($statements, self::CHAIN_UNIQUE, $this->isDrop(...));

            $this->assertNotNull($dropShapeGuard, '⛔ rollback 必須移除 shape guard。');
            $this->assertNotNull($dropParent, '⛔ rollback 必須移除 parent unique。');
            $this->assertNotNull($dropChain, '⛔ rollback 必須移除 composite unique。');

            $this->assertGreaterThan(
                $dropParent,
                $dropShapeGuard,
                "⛔⛔ shape guard 必須在 parent unique 交換成功之後才移除。\n實際順序：\n"
                .implode("\n", $statements),
            );

            $this->assertGreaterThan(
                $dropChain,
                $dropShapeGuard,
                "⛔⛔ shape guard 必須在 order-item index 交換成功之後才移除。\n實際順序：\n"
                .implode("\n", $statements),
            );
        } finally {
            $migration->up();
        }
    }

    /**
     * ⛔ 暫時 support index 不得在 `down()` 結束後殘留。
     *
     * ⭐ 施工單 §4.8 要求「不得留下猜測行為」，所以我實測了 SQLite：
     * ⛔ 只要 index 還指向該欄位，`drop column` 會直接失敗——
     * `error in index ... after drop column: no such column`。
     * ⭐ 也就是說「index 會隨欄位自動消失」在 SQLite 上是**錯的**；
     * 必須明確 drop。同一個明確 drop 在 MySQL 上也永遠合法（FK 已先移除）。
     */
    public function test_the_rollback_leaves_no_temporary_support_index(): void
    {
        $migration = $this->migration();

        $migration->down();

        try {
            $this->assertFalse(
                $this->indexExists(self::SUPPORT_INDEX),
                '⛔ down() 結束後不得殘留暫時 support index：'.self::SUPPORT_INDEX,
            );

            $this->assertFalse(
                Schema::hasColumn('fulfillment_orders', 'replaces_fulfillment_order_id'),
                '⛔ down() 必須移除 replacement 欄位。',
            );
        } finally {
            $migration->up();
        }
    }

    /** ⭐ `up()` 之後不得留下只屬於 rollback 的暫時索引。 */
    public function test_the_upgrade_leaves_no_temporary_support_index(): void
    {
        $this->assertFalse(
            $this->indexExists(self::SUPPORT_INDEX),
            '⛔ up() 之後不得存在 rollback 專用的暫時索引。',
        );

        $this->assertTrue($this->indexExists(self::PARENT_UNIQUE), '⛔ parent unique 必須存在。');
        $this->assertTrue($this->indexExists(self::CHAIN_UNIQUE), '⛔ composite unique 必須存在。');
    }

    private function indexExists(string $name): bool
    {
        return DB::table('sqlite_master')
            ->where('type', 'index')
            ->where('name', $name)
            ->exists();
    }

    /**
     * ⛔⛔ `down()` 必須**先建立** legacy unique，**再刪除** composite。
     *
     * ⭐ 這是本輪唯一的必修：讓兩個索引在交換的瞬間**短暫共存**，
     * 外鍵因此在每一刻都有可用的前導索引。
     *
     * ⛔ 正確解法就是共存，⛔ 不是暫時關閉 `FOREIGN_KEY_CHECKS`、
     * ⛔ 不是 drop／重建外鍵，⛔ 也不是吞掉 DDL 錯誤——那三種都只是讓錯誤
     * 不出現，而不是讓操作真的安全。
     */
    public function test_the_rollback_creates_the_legacy_unique_before_dropping_the_composite(): void
    {
        $this->assertSame(0, DB::table('fulfillment_orders')->where('sequence_no', '>', 1)->count());

        $migration = $this->migration();

        $statements = $this->captureIndexSql(fn () => $migration->down());

        try {
            $createLegacy = null;
            $dropChain = null;

            foreach ($statements as $index => $sql) {
                if ($createLegacy === null
                    && str_contains($sql, self::LEGACY_UNIQUE)
                    && $this->isCreate($sql)) {
                    $createLegacy = $index;
                }

                if ($dropChain === null
                    && str_contains($sql, self::CHAIN_UNIQUE)
                    && $this->isDrop($sql)) {
                    $dropChain = $index;
                }
            }

            $this->assertNotNull($createLegacy, '⛔ rollback 必須重建 legacy unique。');
            $this->assertNotNull($dropChain, '⛔ rollback 必須移除 composite unique。');

            $this->assertLessThan(
                $dropChain,
                $createLegacy,
                '⛔⛔ 必須先建立 legacy unique 再刪除 composite，否則 `order_item_id` '
                ."外鍵會在中間失去可用前導索引，MySQL 會拒絕該 DDL。\n實際順序：\n"
                .implode("\n", $statements),
            );
        } finally {
            // ⛔ 復原，避免影響同一程序中的其他測試。
            $migration->up();
        }
    }

    /**
     * ⭐ `up()` 的順序原本就正確，⛔ 不得在本輪改壞。
     *
     * 先建 composite（`order_item_id` 為前導欄位），再刪單欄 unique。
     */
    public function test_the_upgrade_creates_the_composite_before_dropping_the_legacy_unique(): void
    {
        $migration = $this->migration();

        // 先回到未升級狀態，才能觀察 `up()` 實際送出的 SQL。
        $migration->down();

        $statements = $this->captureIndexSql(fn () => $migration->up());

        $createChain = null;
        $dropLegacy = null;

        foreach ($statements as $index => $sql) {
            if ($createChain === null
                && str_contains($sql, self::CHAIN_UNIQUE)
                && $this->isCreate($sql)) {
                $createChain = $index;
            }

            if ($dropLegacy === null
                && str_contains($sql, self::LEGACY_UNIQUE)
                && $this->isDrop($sql)) {
                $dropLegacy = $index;
            }
        }

        $this->assertNotNull($createChain, '⛔ upgrade 必須建立 composite unique。');
        $this->assertNotNull($dropLegacy, '⛔ upgrade 必須移除 legacy unique。');

        $this->assertLessThan(
            $dropLegacy,
            $createChain,
            "⛔ 必須先建立 composite 再刪除 legacy unique。\n實際順序：\n"
            .implode("\n", $statements),
        );
    }

    /**
     * ⛔ 有 replacement 時，fail-closed 必須在**任何** index DDL 之前就停止。
     *
     * ⭐ 若先動了索引才發現不能回滾，資料庫會停在一個半改過的狀態——
     * 那比直接拒絕糟得多。
     */
    public function test_a_failed_closed_rollback_touches_no_index(): void
    {
        // 建立一筆 replacement，讓 fail-closed 生效。
        $parent = FulfillmentOrder::factory()->submitted('SMM-ROLLBACK-1')->create();

        DB::table('fulfillment_orders')->insert([
            'order_item_id' => $parent->order_item_id,
            'sequence_no' => 2,
            'replaces_fulfillment_order_id' => $parent->id,
            'provider' => $parent->provider,
            'provider_service_id_snapshot' => $parent->provider_service_id_snapshot,
            'payload_type_snapshot' => 'link_quantity',
            'target_value_override' => 'ciphertext-placeholder',
            'quantity_override' => 100,
            'suggested_quantity_snapshot' => 100,
            'replacement_created_by_user_id' => User::factory()->create()->id,
            'status' => FulfillmentStatus::Ready->value,
            'attempt_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = $this->migration();

        $statements = $this->captureAllSql(function () use ($migration): void {
            try {
                $migration->down();
                $this->fail('⛔ 有 replacement 時必須拒絕回滾。');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('更換履約', $e->getMessage());
            }
        });

        /*
         * ⛔⛔ 一個 index／constraint／trigger DDL 都不該送出。
         *
         * ⭐ 只斷言「兩個 index 名稱沒出現」是不夠的：staging 真正受的傷是
         * `dropShapeGuard()` 先跑掉。所以這裡改為掃描**所有**敘述，
         * 任何一種 schema 變更都算違規。
         */
        $ddl = array_values(array_filter($statements, function (string $sql): bool {
            $lower = strtolower($sql);

            return str_contains($lower, 'alter table')
                || str_contains($lower, 'create index')
                || str_contains($lower, 'create unique index')
                || str_contains($lower, 'drop index')
                || str_contains($lower, 'drop trigger')
                || str_contains($lower, 'create trigger');
        }));

        $this->assertSame(
            [],
            $ddl,
            "⛔ fail-closed 必須在任何 index／constraint DDL 之前停止。\n實際送出：\n"
            .implode("\n", $ddl),
        );
    }

    private function isCreate(string $sql): bool
    {
        return str_contains(strtolower($sql), 'create');
    }

    private function isDrop(string $sql): bool
    {
        return str_contains(strtolower($sql), 'drop');
    }
}
