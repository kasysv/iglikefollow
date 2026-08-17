<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Support\HomeHighlightsRollbackGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Rolling back the homepage highlight columns must not silently delete copy.
 *
 * The columns hold text an Owner typed in the admin. Re-running the migration
 * restores the columns but not their contents, so a rollback that proceeds
 * while they are filled is an unrecoverable loss.
 */
class HomeHighlightsRollbackTest extends TestCase
{
    use RefreshDatabase;

    private const COLUMNS = [
        'home_highlight_1_title',
        'home_highlight_1_body',
        'home_highlight_2_title',
        'home_highlight_2_body',
        'home_highlight_3_title',
        'home_highlight_3_body',
    ];

    private function settings(array $attributes = []): SiteSetting
    {
        return SiteSetting::create(array_merge([
            'company_name' => 'IGLIKEFOLLOW',
            'home_h1' => '多平台社群服務，一次選好。',
        ], $attributes));
    }

    /** 目前資料庫中所有 table 的 row count。 */
    private function rowCounts(): array
    {
        $counts = [];

        foreach (['site_settings', 'services', 'service_variants', 'platforms', 'faqs', 'users'] as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        return $counts;
    }

    private function assertAllColumnsPresent(): void
    {
        foreach (self::COLUMNS as $column) {
            $this->assertTrue(
                Schema::hasColumn('site_settings', $column),
                "欄位 {$column} 不應該被刪除"
            );
        }
    }

    public function test_empty_columns_can_be_rolled_back_and_reapplied(): void
    {
        $this->settings();

        $before = $this->rowCounts();

        // 六欄全空：允許 drop。
        HomeHighlightsRollbackGuard::assertNoHighlightContent();

        Schema::table('site_settings', function ($table) {
            $table->dropColumn(self::COLUMNS);
        });

        foreach (self::COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('site_settings', $column));
        }

        // 再 up() 能重新建立。
        Schema::table('site_settings', function ($table) {
            $table->string('home_highlight_1_title')->nullable();
            $table->string('home_highlight_1_body')->nullable();
            $table->string('home_highlight_2_title')->nullable();
            $table->string('home_highlight_2_body')->nullable();
            $table->string('home_highlight_3_title')->nullable();
            $table->string('home_highlight_3_body')->nullable();
        });

        $this->assertAllColumnsPresent();

        // ⛔ 其他資料一列都不能少。
        $this->assertSame($before, $this->rowCounts());
    }

    public function test_other_settings_survive_an_empty_rollback(): void
    {
        $this->settings(['home_eyebrow' => 'Social growth services']);

        HomeHighlightsRollbackGuard::assertNoHighlightContent();

        Schema::table('site_settings', function ($table) {
            $table->dropColumn(self::COLUMNS);
        });

        $row = DB::table('site_settings')->first();

        $this->assertSame('IGLIKEFOLLOW', $row->company_name);
        $this->assertSame('Social growth services', $row->home_eyebrow);
        $this->assertSame('多平台社群服務，一次選好。', $row->home_h1);
    }

    public function test_a_complete_pair_blocks_the_rollback(): void
    {
        $this->settings([
            'home_highlight_1_title' => '價格透明',
            'home_highlight_1_body' => '結帳金額與頁面顯示一致',
        ]);

        $before = $this->rowCounts();

        try {
            HomeHighlightsRollbackGuard::assertNoHighlightContent();
            $this->fail('有內容時必須拒絕 rollback');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('home_highlight_1_title', $e->getMessage());
        }

        // ⛔ 六欄與精確值都必須原封不動。
        $this->assertAllColumnsPresent();

        $row = DB::table('site_settings')->first();
        $this->assertSame('價格透明', $row->home_highlight_1_title);
        $this->assertSame('結帳金額與頁面顯示一致', $row->home_highlight_1_body);

        $this->assertSame($before, $this->rowCounts());
    }

    /**
     * ⛔ 半組草稿同樣要擋。
     *
     * 它還不會顯示在首頁，但那是別人打到一半的內容；靜靜丟掉草稿與丟掉完稿
     * 是同一種損失。
     */
    public function test_a_title_without_a_body_blocks_the_rollback(): void
    {
        $this->settings(['home_highlight_2_title' => '只有標題的草稿']);

        $this->expectException(RuntimeException::class);

        try {
            HomeHighlightsRollbackGuard::assertNoHighlightContent();
        } finally {
            $this->assertAllColumnsPresent();
            $this->assertSame(
                '只有標題的草稿',
                DB::table('site_settings')->first()->home_highlight_2_title
            );
        }
    }

    public function test_a_body_without_a_title_blocks_the_rollback(): void
    {
        $this->settings(['home_highlight_3_body' => '只有說明的草稿']);

        $this->expectException(RuntimeException::class);

        try {
            HomeHighlightsRollbackGuard::assertNoHighlightContent();
        } finally {
            $this->assertAllColumnsPresent();
        }
    }

    public function test_whitespace_only_content_does_not_block_the_rollback(): void
    {
        $this->settings([
            'home_highlight_1_title' => '   ',
            'home_highlight_2_body' => "\t\n ",
        ]);

        // 純空白＝清空欄位後留下的殘跡，不顯示任何東西，不值得擋住 rollback。
        HomeHighlightsRollbackGuard::assertNoHighlightContent();

        Schema::table('site_settings', function ($table) {
            $table->dropColumn(self::COLUMNS);
        });

        $this->assertFalse(Schema::hasColumn('site_settings', 'home_highlight_1_title'));
    }

    public function test_the_guard_passes_when_no_settings_row_exists(): void
    {
        // 全新站台：沒有任何一列，自然沒有內容要保護。
        $this->assertSame(0, DB::table('site_settings')->count());

        HomeHighlightsRollbackGuard::assertNoHighlightContent();

        $this->assertAllColumnsPresent();
    }

    public function test_the_guard_is_safe_when_the_columns_are_already_gone(): void
    {
        $this->settings();

        Schema::table('site_settings', function ($table) {
            $table->dropColumn(self::COLUMNS);
        });

        // ⛔ probe 本身不得因欄位不存在而爆掉，否則會造成半套 rollback。
        HomeHighlightsRollbackGuard::assertNoHighlightContent();

        $this->assertSame(1, DB::table('site_settings')->count());
    }

    public function test_the_exception_never_leaks_the_stored_copy(): void
    {
        $secret = '這段文字不應該出現在例外訊息裡';

        $this->settings(['home_highlight_1_title' => $secret]);

        try {
            HomeHighlightsRollbackGuard::assertNoHighlightContent();
            $this->fail('應該要拋出例外');
        } catch (RuntimeException $e) {
            // ⛔ 例外訊息會進 log 與終端機：只列欄位名稱，不列內容。
            $this->assertStringNotContainsString($secret, $e->getMessage());
            $this->assertStringContainsString('home_highlight_1_title', $e->getMessage());
        }
    }

    public function test_the_guard_column_list_matches_the_schema(): void
    {
        // ⛔ guard 與 migration 的欄位清單必須一致，否則會漏掉一欄的內容。
        $this->assertSame(self::COLUMNS, HomeHighlightsRollbackGuard::COLUMNS);
    }
}
