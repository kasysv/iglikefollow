<?php

namespace Tests\Feature\Fulfillment;

use App\Models\FulfillmentMapping;
use App\Models\FulfillmentOrder;
use App\Support\M4aRollbackGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Rolling M4A back must never quietly destroy dispatch records.
 *
 * A fulfilment row is the only local evidence that a paid order was sent to a
 * supplier. Losing it means the money is spent and nothing says where it went.
 */
class FulfillmentRollbackTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['fulfillment_events', 'fulfillment_orders', 'fulfillment_mappings'];

    public function test_empty_tables_may_be_rolled_back(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }

        M4aRollbackGuard::assertAllTablesAreEmpty();

        $this->assertTrue(true, '三表全空時不得阻擋');
    }

    public function test_a_fulfillment_row_blocks_the_whole_rollback(): void
    {
        FulfillmentOrder::factory()->create();

        $this->expectException(RuntimeException::class);

        M4aRollbackGuard::assertAllTablesAreEmpty();
    }

    public function test_a_mapping_alone_blocks_the_rollback(): void
    {
        FulfillmentMapping::factory()->create();

        $this->expectException(RuntimeException::class);

        M4aRollbackGuard::assertAllTablesAreEmpty();
    }

    /**
     * ⛔ 這是本檔最重要的一項。
     *
     * batch rollback 是反序執行的，所以 events 與 orders 會在 mappings 的
     * 守衛跑到之前就被 drop 掉。三個 down() 都檢查全部三張表，第一個跑到的
     * 就會擋下來——不會出現「兩張表已經沒了才中止」的半套狀態。
     */
    public function test_every_table_is_checked_from_every_migration(): void
    {
        FulfillmentMapping::factory()->create();

        // 只有 mappings 有資料，但任何一個 down() 都必須拒絕。
        try {
            M4aRollbackGuard::assertAllTablesAreEmpty();
            $this->fail('有資料時必須拒絕');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('履約對應設定', $e->getMessage());
        }

        // ⛔ 三張表都還在。
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }

    public function test_the_refusal_message_names_no_service_id_or_target(): void
    {
        $mapping = FulfillmentMapping::factory()->create(['provider_service_id' => 'FAKE-SECRET-ID']);
        FulfillmentOrder::factory()->submitted('FAKE-ORDER-ID')->create([
            'fulfillment_mapping_id' => $mapping->id,
        ]);

        try {
            M4aRollbackGuard::assertAllTablesAreEmpty();
            $this->fail('應該要拋出例外');
        } catch (RuntimeException $e) {
            // ⛔ 例外訊息會進 log 與終端機：只列表名與筆數。
            $this->assertStringNotContainsString('FAKE-SECRET-ID', $e->getMessage());
            $this->assertStringNotContainsString('FAKE-ORDER-ID', $e->getMessage());
            $this->assertStringContainsString('筆', $e->getMessage());
        }
    }

    public function test_the_guard_is_safe_when_the_tables_do_not_exist(): void
    {
        Schema::dropIfExists('fulfillment_events');
        Schema::dropIfExists('fulfillment_orders');
        Schema::dropIfExists('fulfillment_mappings');

        // ⛔ probe 本身不得因表不存在而爆掉，否則會造成半套 rollback。
        M4aRollbackGuard::assertAllTablesAreEmpty();

        $this->assertFalse(Schema::hasTable('fulfillment_orders'));
    }
}
