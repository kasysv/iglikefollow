<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\OrderOperationsIndicators;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * 首頁訂單概況：總訂單、處理中、已完成。
 *
 * ⛔⛔ 判定與訂單列表的 SMM 欄**完全同一套**（`OrderOperationsIndicators::smm()`），
 * ⛔ 不在這裡另寫 SQL 規則——兩處各寫一份，遲早會出現「列表是 O、首頁算完成」。
 *
 *  - 總訂單：已付款的訂單（⛔ 不含待付款、取消、逾期——那些不是真正成立的訂單）。
 *  - 處理中：至少一個商品已送出 SMM、供應商尚未完成（列表上的 O）。
 *  - 已完成：每個商品都是 Completed（列表上的勾）。
 *
 * ⛔ 只讀已保存的狀態，⛔ 不查 provider、⛔ 不寫入。
 */
class OrderOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

    // ⭐ 一分鐘更新一次即可；⛔ 不用預設的 5 秒輪詢。
    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        // ⛔ 與訂單列表同一條授權：看得到訂單的人才看得到訂單數字。
        return Auth::user()?->can('viewAny', Order::class) === true;
    }

    /** @return array{total: int, inProgress: int, completed: int} */
    public static function counts(): array
    {
        $total = 0;
        $inProgress = 0;
        $completed = 0;

        Order::query()
            ->where('order_status', OrderStatus::Paid)
            ->with('items.latestFulfillmentOrder')
            ->chunkById(500, function ($orders) use (&$total, &$inProgress, &$completed): void {
                foreach ($orders as $order) {
                    $total++;
                    $state = OrderOperationsIndicators::smm($order);

                    if ($state['allCompleted']) {
                        $completed++;
                    } elseif ($state['inProgress']) {
                        $inProgress++;
                    }
                }
            });

        return ['total' => $total, 'inProgress' => $inProgress, 'completed' => $completed];
    }

    protected function getStats(): array
    {
        $c = self::counts();
        $other = $c['total'] - $c['inProgress'] - $c['completed'];

        return [
            Stat::make('總訂單', number_format($c['total']))
                ->description('已付款訂單')
                ->color('primary'),
            Stat::make('處理中', number_format($c['inProgress']))
                ->description('已送出 SMM、尚未完成')
                ->color('info'),
            Stat::make('已完成', number_format($c['completed']))
                ->description($other > 0
                    ? "另有 {$other} 筆未送出、部分完成、取消或需人工處理"
                    : '全部商品皆已完成')
                ->color('success'),
        ];
    }
}
