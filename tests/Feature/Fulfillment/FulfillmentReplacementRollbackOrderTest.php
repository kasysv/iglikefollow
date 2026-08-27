<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\FulfillmentStatus;
use App\Models\FulfillmentOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $statements = $this->captureIndexSql(function () use ($migration): void {
            try {
                $migration->down();
                $this->fail('⛔ 有 replacement 時必須拒絕回滾。');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('更換履約', $e->getMessage());
            }
        });

        // ⛔ 一個 index DDL 都不該送出。
        $this->assertSame([], $statements, '⛔ fail-closed 必須在任何 index DDL 之前停止。');
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
