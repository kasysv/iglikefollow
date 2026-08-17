<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Refuse to drop the homepage highlight columns while they hold copy.
 *
 * The columns carry text an Owner typed in the admin. Dropping them destroys
 * it permanently — there is no other copy, and unlike a schema change the
 * content cannot be recreated by re-running the migration.
 *
 * ⛔ A comment telling the operator to back up first is documentation, not a
 * protection: it is read only by whoever already thought to look. The check has
 * to be in the code path that does the damage.
 *
 * The guard deliberately does not save the content anywhere on its way out —
 * not to a log, an exception message, a file or a spare table. Copying user
 * copy into an unplanned location to "keep it safe" creates a second store
 * nobody is tracking, and the exception message would be the widest-read place
 * of all.
 */
class HomeHighlightsRollbackGuard
{
    private const TABLE = 'site_settings';

    /** ⛔ 與 migration 的欄位清單必須一致。 */
    public const COLUMNS = [
        'home_highlight_1_title',
        'home_highlight_1_body',
        'home_highlight_2_title',
        'home_highlight_2_body',
        'home_highlight_3_title',
        'home_highlight_3_body',
    ];

    /**
     * ⛔ Refuse the rollback when any highlight column holds real text.
     *
     * "Real" means non-empty after trimming: whitespace-only is what an editor
     * leaves behind after clearing a field, and it renders nothing, so it is
     * not content worth blocking a rollback for.
     *
     * A half-filled pair — a title with no body — still counts. It does not
     * render yet, but it is someone's unfinished draft, and silently discarding
     * a draft is the same loss as discarding finished copy.
     */
    public static function assertNoHighlightContent(): void
    {
        // 表或欄位還不存在時什麼都不用擋；⛔ probe 本身不得造成半套 rollback。
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $present = array_values(array_filter(
            self::COLUMNS,
            fn (string $column) => Schema::hasColumn(self::TABLE, $column),
        ));

        if ($present === []) {
            return;
        }

        $filled = [];

        foreach (DB::table(self::TABLE)->get($present) as $row) {
            foreach ($present as $column) {
                if (trim((string) ($row->{$column} ?? '')) !== '') {
                    $filled[] = $column;
                }
            }
        }

        if ($filled === []) {
            return;
        }

        // ⛔ 只列欄位名稱，不列內容：例外訊息會進 log 與終端機。
        throw new RuntimeException(
            '無法回滾首頁賣點欄位：'.implode('、', array_unique($filled)).' 仍有後台輸入的文字。'
            .'⛔ 尚未刪除任何欄位。請先在後台清空這些欄位，或改用 code-only rollback'
            .'（git revert，保留資料庫欄位）。'
        );
    }
}
