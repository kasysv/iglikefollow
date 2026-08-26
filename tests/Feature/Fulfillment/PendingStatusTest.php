<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\FulfillmentEventCode;
use App\Enums\FulfillmentStatus;
use App\Models\FulfillmentEvent;
use App\Models\FulfillmentOrder;
use App\Services\Fulfillment\TheMostPanelFulfillmentGateway;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The provider's exact `Pending` token as an independent status.
 *
 * ⭐ Owner 以第一方 live 資料確認 TheMostPanel 剛開始會回 exact `Pending`。
 * 缺了它，那個回應會被 fail-closed 記成 `STATUS_UNRECOGNISED`——staging 上
 * 實際發生過。
 *
 * ⛔⛔ 它必須是**獨立**狀態，⛔ 不是 `Submitted` 的別名（GPT 前版那樣做，
 * 已由 Owner 否決）：`Submitted` = 還沒問過對方，`Pending` = 問過且對方說
 * 還在排隊。合併兩者，後台就再也分不出輪詢到底有沒有在動。
 */
class PendingStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    // ==================================== 1. enum 與狀態機

    /** ⛔ `Pending` 是獨立的 case，⛔ 不等於 Submitted 或 Processing。 */
    public function test_pending_is_its_own_status(): void
    {
        $this->assertSame('pending', FulfillmentStatus::Pending->value);
        $this->assertNotSame(FulfillmentStatus::Submitted, FulfillmentStatus::Pending);
        $this->assertNotSame(FulfillmentStatus::Processing, FulfillmentStatus::Pending);

        // ⛔ 已送出之後的狀態：絕不可能因此重新派單。
        $this->assertTrue(FulfillmentStatus::Pending->isPostSubmit());
        // ⛔ 不是終止：還要繼續輪詢。
        $this->assertFalse(FulfillmentStatus::Pending->isTerminal());
    }

    /** ⭐ 正常流程與 provider 可能的跳階。 */
    public function test_the_allowed_transitions_match_the_specification(): void
    {
        // 正常：Submitted → Pending → Processing → terminal
        $this->assertTrue(FulfillmentStatus::Submitted->canTransitionTo(FulfillmentStatus::Pending));
        $this->assertTrue(FulfillmentStatus::Pending->canTransitionTo(FulfillmentStatus::Processing));
        $this->assertTrue(FulfillmentStatus::Pending->canTransitionTo(FulfillmentStatus::Completed));

        /*
         * ⭐ provider 在 Processing 之後又回 exact Pending 是真實會發生的
         * （重新排隊）。⛔ 允許安全退回獨立 Pending——⛔ 不得改叫 Submitted，
         * ⛔ 也不得寫成 unrecognised。
         */
        $this->assertTrue(FulfillmentStatus::Processing->canTransitionTo(FulfillmentStatus::Pending));

        // Submitted／Pending 都可直接跳到終止狀態。
        foreach ([FulfillmentStatus::Completed, FulfillmentStatus::Partial,
            FulfillmentStatus::Canceled, FulfillmentStatus::Failed] as $terminal) {
            $this->assertTrue(FulfillmentStatus::Submitted->canTransitionTo($terminal));
            $this->assertTrue(FulfillmentStatus::Pending->canTransitionTo($terminal));
        }
    }

    /**
     * ⛔⛔ 絕不退回 `Submitted`，也絕不退回任何送出前狀態。
     *
     * 退回送出前狀態會讓這一列重新符合派單資格——那就是「一筆付款變成兩筆
     * 供應商訂單」的路徑。退回 `Submitted` 則是宣稱我們從沒問過對方。
     */
    public function test_pending_never_rewinds(): void
    {
        $this->assertFalse(FulfillmentStatus::Pending->canTransitionTo(FulfillmentStatus::Submitted));

        foreach ([FulfillmentStatus::Ready, FulfillmentStatus::Submitting,
            FulfillmentStatus::ConfigurationPending] as $preSubmit) {
            $this->assertFalse(FulfillmentStatus::Pending->canTransitionTo($preSubmit));
        }
    }

    /** ⭐ `Pending` 必須可以再被輪詢，也必須是 provider 可回報的目標。 */
    public function test_pending_is_syncable_in_both_directions(): void
    {
        $this->assertContains(FulfillmentStatus::Pending, FulfillmentStatus::syncableSources());
        $this->assertContains(FulfillmentStatus::Pending, FulfillmentStatus::syncableTargets());
    }

    // ==================================== 2. DB guard

    /** ⭐ 資料庫必須接受 `pending`（新 migration 重建過 guard）。 */
    public function test_the_database_accepts_a_pending_row(): void
    {
        $row = FulfillmentOrder::factory()->submitted('SMM-PENDING-1')->create();

        $row->forceFill(['status' => FulfillmentStatus::Pending])->save();

        $this->assertSame(
            'pending',
            DB::table('fulfillment_orders')->where('id', $row->id)->value('status'),
        );
    }

    /**
     * ⛔ `pending` 也必須具備 provider order ID。
     *
     * 一筆沒有單號的 pending 是無法對帳的紀錄：我們宣稱對方在排隊，
     * 卻沒有任何東西可以拿去問。
     */
    public function test_a_pending_row_without_a_provider_id_is_refused(): void
    {
        $row = FulfillmentOrder::factory()->submitted('SMM-PENDING-2')->create();

        $this->expectException(QueryException::class);

        DB::table('fulfillment_orders')->where('id', $row->id)->update([
            'status' => 'pending',
            'provider_order_id' => null,
        ]);
    }

    /** ⛔ 非法轉移仍被資料庫擋下（重建 guard 沒有放寬既有規則）。 */
    public function test_an_illegal_transition_is_still_refused_by_the_database(): void
    {
        $row = FulfillmentOrder::factory()->submitted('SMM-PENDING-3')->create();
        DB::table('fulfillment_orders')->where('id', $row->id)->update(['status' => 'pending']);

        $this->expectException(QueryException::class);

        // ⛔ pending → ready 會讓這一列重新可派單。
        DB::table('fulfillment_orders')->where('id', $row->id)->update(['status' => 'ready']);
    }

    /** ⭐ 事件表也接受 `pending` 作為 from／to。 */
    public function test_the_events_table_accepts_pending(): void
    {
        $row = FulfillmentOrder::factory()->submitted('SMM-PENDING-4')->create();

        $event = FulfillmentEvent::create([
            'fulfillment_order_id' => $row->id,
            'event_code' => FulfillmentEventCode::StatusSynced,
            'from_status' => FulfillmentStatus::Submitted,
            'to_status' => FulfillmentStatus::Pending,
        ]);

        $this->assertSame('pending', DB::table('fulfillment_events')
            ->where('id', $event->id)->value('to_status'));
    }

    /**
     * ⛔⛔ 有 `pending` 資料時，migration 的 `down()` 必須 fail closed。
     *
     * 舊 guard 不認得 `pending`。若在有 Pending 資料時恢復舊 guard，那些列會
     * 立刻變成資料庫規則下的非法資料——之後任何一次 UPDATE 都會在完全無關的
     * 操作上被 abort。
     *
     * ⛔ 而正確的處置**不是**自動把 Pending 改成 Submitted、刪事件或清資料：
     * 那是拿真實履約紀錄去遷就一次 schema 回滾。
     */
    public function test_the_migration_refuses_to_roll_back_while_pending_data_exists(): void
    {
        $row = FulfillmentOrder::factory()->submitted('SMM-DOWN-1')->create();
        DB::table('fulfillment_orders')->where('id', $row->id)->update(['status' => 'pending']);

        $migration = require database_path(
            'migrations/2026_08_27_100000_rebuild_fulfillment_guards_for_pending_status.php'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/已有 pending 履約資料/u');

        $migration->down();
    }

    /**
     * ⭐ 沒有 `pending` 資料時，`down()` 可以正常恢復舊 guard。
     *
     * ⛔ 恢復之後 `pending` 必須真的被資料庫拒絕——否則那個 `down()` 只是
     * 看起來跑完了。
     */
    public function test_the_migration_can_roll_back_when_no_pending_data_exists(): void
    {
        $this->assertSame(0, DB::table('fulfillment_orders')->where('status', 'pending')->count());

        $migration = require database_path(
            'migrations/2026_08_27_100000_rebuild_fulfillment_guards_for_pending_status.php'
        );

        $migration->down();

        $row = FulfillmentOrder::factory()->submitted('SMM-DOWN-2')->create();

        try {
            DB::table('fulfillment_orders')->where('id', $row->id)->update(['status' => 'pending']);
            $this->fail('⛔ 恢復舊 guard 之後，資料庫仍接受 pending。');
        } catch (QueryException) {
            // 預期：舊 guard 不認得 pending。
        } finally {
            // ⛔ 復原，避免影響同一個測試程序中的其他測試。
            $migration->up();
        }
    }

    // ==================================== 2b. 跨 driver 的 constraint drop 語法

    /**
     * ⛔⛔ 每個 driver 必須選到它**真正支援**的 DROP 語法。
     *
     * ⭐ R2 修正一個真實缺陷：R1 把所有非 PostgreSQL 的 driver 都送往
     * `ALTER TABLE … DROP CHECK`——那是 MySQL 語法。MariaDB 官方用
     * `DROP CONSTRAINT`。R1 明明把 `mariadb` 列在支援清單裡卻沒有分支，
     * 在 MariaDB 上舊 constraint 會留著，接著 `ADD CONSTRAINT` 因同名而失敗
     * ——staging migrate 直接掛掉。
     *
     * ⛔ 這條測試只驗證**語法選擇**（純字串邏輯），⛔ 不宣稱在真實 MySQL 或
     * MariaDB 上跑過——本機只有 SQLite。runtime 仍為 NOT VERIFIED，
     * 已如實寫進結果文件。
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function dropSyntaxProvider(): array
    {
        return [
            // MySQL 8.0.16+ 的 CHECK constraint 用 DROP CHECK。
            'mysql' => ['mysql', 'DROP CHECK'],
            // ⛔ MariaDB 官方語法是 DROP CONSTRAINT。
            'mariadb' => ['mariadb', 'DROP CONSTRAINT'],
            'pgsql' => ['pgsql', 'DROP CONSTRAINT'],
        ];
    }

    #[DataProvider('dropSyntaxProvider')]
    public function test_each_driver_selects_its_supported_drop_syntax(
        string $driver,
        string $expected,
    ): void {
        /*
         * ⛔ 直接讀 migration 原始碼中的 `match` 分支——⛔ 不是重新實作一份
         * 判斷邏輯（那只會測到我自己抄的第二份，兩份還會各自漂移）。
         */
        $source = file_get_contents(database_path(
            'migrations/2026_08_27_100000_rebuild_fulfillment_guards_for_pending_status.php'
        ));

        $this->assertMatchesRegularExpression(
            "/'{$driver}'[^=\n]*=>[^\n]*{$expected}/u",
            $source,
            "⛔ driver `{$driver}` 必須使用 `{$expected}`。",
        );
    }

    /** ⛔ 不得再以泛用 `catch (Throwable)` 吞掉 DDL 錯誤。 */
    public function test_ddl_errors_are_no_longer_swallowed(): void
    {
        $source = file_get_contents(database_path(
            'migrations/2026_08_27_100000_rebuild_fulfillment_guards_for_pending_status.php'
        ));

        /*
         * ⛔ R1 用 `try { DB::statement($sql); } catch (Throwable) {}` 把權限、
         * 鎖等待、連線中斷與語法錯誤全部當成「沒關係」。一支會靜靜跳過保護、
         * 卻回報成功的 migration，比直接失敗危險得多。
         */
        $this->assertStringNotContainsString('} catch (Throwable) {', $source);

        // ⭐ 改為先精確查詢 constraint 是否存在。
        $this->assertStringContainsString('information_schema', $source);
        $this->assertStringContainsString('checkConstraintExists', $source);
    }

    /** ⛔ 表名與 constraint 名只能來自封閉 allowlist。 */
    public function test_only_allowlisted_constraints_can_be_dropped(): void
    {
        $migration = require database_path(
            'migrations/2026_08_27_100000_rebuild_fulfillment_guards_for_pending_status.php'
        );

        $method = new \ReflectionMethod($migration, 'dropCheck');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/不在 allowlist 內/u');

        // ⛔ 任意表名／constraint 名必須被拒絕，⛔ 不得拼進 DDL。
        $method->invoke($migration, 'users', 'users_values_check');
    }

    // ==================================== 3. exact token 比對

    /**
     * ⛔ 只有 exact `Pending` 被接受。
     *
     * ⭐ 這是這一輪的重點：不做 trim、不做大小寫正規化。任何變體都必須維持
     * unrecognised，讓「對方換了拼法」成為一個看得見的事件，⛔ 而不是被我們
     * 猜著吞掉。
     *
     * @return array<string, array{0: string}>
     */
    public static function nonExactPendingProvider(): array
    {
        return [
            'lowercase' => ['pending'],
            'uppercase' => ['PENDING'],
            'leading space' => [' Pending'],
            'trailing space' => ['Pending '],
            'mixed case' => ['PenDing'],
        ];
    }

    #[DataProvider('nonExactPendingProvider')]
    public function test_only_the_exact_pending_token_is_recognised(string $variant): void
    {
        $map = new \ReflectionClassConstant(
            TheMostPanelFulfillmentGateway::class,
            'STATUS_MAP',
        );

        $statusMap = $map->getValue();

        $this->assertArrayHasKey('Pending', $statusMap, '⭐ exact `Pending` 必須被認得。');
        $this->assertSame(FulfillmentStatus::Pending, $statusMap['Pending']);

        // ⛔ 其餘拼法一律不在表內 → fail closed 為 unrecognised。
        $this->assertArrayNotHasKey($variant, $statusMap, "⛔ `{$variant}` 不得被接受。");
    }

    /** ⭐ 完整的 exact mapping 表（施工單第 30 點）。 */
    public function test_the_full_exact_mapping_matches_the_specification(): void
    {
        $map = (new \ReflectionClassConstant(
            TheMostPanelFulfillmentGateway::class,
            'STATUS_MAP',
        ))->getValue();

        $this->assertSame([
            'Pending' => FulfillmentStatus::Pending,
            'In progress' => FulfillmentStatus::Processing,
            'Completed' => FulfillmentStatus::Completed,
            'Partial' => FulfillmentStatus::Partial,
            'Rejected' => FulfillmentStatus::Failed,
            'processing' => FulfillmentStatus::Processing,
            'Cancel' => FulfillmentStatus::Canceled,
        ], $map);

        // ⛔ `Submitted` 不是 provider status token，不得出現在表內。
        $this->assertArrayNotHasKey('Submitted', $map);
    }
}
