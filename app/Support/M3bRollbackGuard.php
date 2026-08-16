<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * One preflight for the whole M3B-A table set.
 *
 * Each migration guarding only its own table is not enough. A batch rollback
 * runs them in reverse order, so `invoice_attempts` and `invoices` are dropped
 * *before* the `integration_settings` guard ever runs — the rollback stops
 * halfway with two tables already gone and reports an error, which is worse
 * than either succeeding or failing outright.
 *
 * So every one of the three checks all three, before dropping anything. The
 * first `down()` to run refuses, and nothing is lost.
 */
class M3bRollbackGuard
{
    /** 表名 => 有資料時的說明。 */
    private const TABLES = [
        'invoice_attempts' => '開立嘗試紀錄',
        'invoices' => '發票紀錄（稅務憑證）',
        'integration_settings' => '串接設定（金鑰）',
    ];

    /**
     * ⛔ Refuse the whole rollback if any M3B-A table holds data.
     *
     * Invoices are tax records the authority also holds; provider keys usually
     * cannot be read back after they are issued. A rollback is a development
     * convenience and must give way to both.
     */
    public static function assertAllTablesAreEmpty(): void
    {
        $occupied = [];

        foreach (self::TABLES as $table => $label) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $count = DB::table($table)->count();

            if ($count > 0) {
                $occupied[] = "{$label} {$count} 筆";
            }
        }

        if ($occupied !== []) {
            throw new RuntimeException(
                '無法回滾 M3B-A：'.implode('、', $occupied).'。'
                .'⛔ 尚未刪除任何資料表。請先匯出並確認後再手動清除。'
            );
        }
    }
}
