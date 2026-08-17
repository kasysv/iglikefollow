<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * One preflight for the whole M4A table set.
 *
 * Each migration guarding only its own table is not enough. A batch rollback
 * runs them in reverse order, so `fulfillment_events` and `fulfillment_orders`
 * would be dropped *before* the `fulfillment_mappings` guard ever ran — the
 * rollback stops halfway with two tables already gone, which is worse than
 * either succeeding or failing outright.
 *
 * So every one of the three `down()` methods calls this, and it checks all
 * three. The first to run refuses, and nothing is lost.
 *
 * ⛔ Fulfilment rows record what was ordered from a supplier on a customer's
 * behalf. Losing them means losing the only local record that a paid order was
 * ever dispatched — the money is spent and nothing says where it went.
 */
class M4aRollbackGuard
{
    /** 表名 => 有資料時的說明。 */
    private const TABLES = [
        'fulfillment_events' => '履約事件時間線',
        'fulfillment_orders' => '履約紀錄（已派單證據）',
        'fulfillment_mappings' => '履約對應設定',
    ];

    /**
     * ⛔ Refuse the whole rollback if any M4A table holds data.
     *
     * The message names tables and counts only — never a provider service id, a
     * provider order id or anything from an order. An exception message reaches
     * the log and the terminal, which is the widest audience of any surface
     * here.
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
                '無法回滾 M4A：'.implode('、', $occupied).'。'
                .'⛔ 尚未刪除任何資料表。請先匯出並確認後再手動清除，'
                .'或改用 code-only rollback（git revert，保留資料表）。'
            );
        }
    }
}
