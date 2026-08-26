<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Models\Order;
use App\Support\OrderActivityTimeline;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/**
 * The one order timeline, at the bottom of the order page.
 *
 * ⭐ Owner 原話是把主畫面的「訂單時間表」併入**下方既有的「訂單時間線」**。
 * A1 做反了方向（移除下方、保留上方），R1 改回來：下方成為唯一的合併時間線，
 * 主畫面那個重複的 Section 移除。
 *
 * ⛔ 唯讀 presenter：資料來自 `OrderActivityTimeline`，它只讀 `order_events`
 * 與 `fulfillment_events` 兩張 append-only 表。⛔ 不新增第三個事件來源、
 * 不在開頁時寫入、不呼叫任何 provider。
 *
 * ⛔ 不用 Filament 的 relationship 表格：一個 relationship 只能指向單一張表，
 * 而時間線本質上是跨兩張表的合併呈現；改用自訂唯讀 view。
 */
class OrderEventsRelationManager extends RelationManager
{
    /**
     * ⛔ 仍宣告 `events`：Filament 需要一個 relationship 名稱才能掛載這個
     * relation manager 並判定權限，但實際呈現完全由下方的自訂 view 決定，
     * ⛔ 不使用這個 relationship 的查詢結果。
     */
    protected static string $relationship = 'events';

    protected static ?string $title = '訂單時間線';

    /** ⭐ 自訂唯讀 view，取代預設的 relationship 表格。 */
    protected string $view = 'filament.relation-managers.order-activity-timeline';

    /**
     * ⛔ 保留空表格：`RelationManager` 的抽象要求這個方法存在。回傳沒有任何
     * 欄位與動作的表格，⛔ 不會產生第二份時間線。
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    /**
     * The merged timeline rows for the view.
     *
     * 每列帶穩定唯一 key（`order:{id}`／`fulfillment:{id}`），排序固定為
     * `created_at → id → source`——⛔ 兩者都由 presenter 決定，這裡不另做一套。
     *
     * @return list<array<string, mixed>>
     */
    public function getTimelineEntries(): array
    {
        return OrderActivityTimeline::for($this->getOwnerRecord());
    }

    /** ⛔ 唯讀：不提供新增、編輯或刪除。 */
    public function isReadOnly(): bool
    {
        return true;
    }

    public function getOwnerRecord(): Order
    {
        /** @var Order */
        return parent::getOwnerRecord();
    }
}
