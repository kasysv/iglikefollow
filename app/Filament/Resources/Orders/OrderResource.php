<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Read-only order records.
 *
 * ⛔ No create, edit, delete or bulk action. An order reflects what a customer
 * agreed to and what a payment provider confirmed; letting support tick "paid"
 * by hand would defeat the verification the lifecycle exists to enforce.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $navigationLabel = '訂單';

    protected static string|UnitEnum|null $navigationGroup = '訂單管理';

    protected static ?string $modelLabel = '訂單';

    protected static ?string $pluralModelLabel = '訂單';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PaymentAttemptsRelationManager::class,
            RelationManagers\FulfillmentOrdersRelationManager::class,
            /*
             * ⛔ `OrderEventsRelationManager` 已不再掛載。
             *
             * Owner 要求「訂單時間表」與「訂單時間線」合併為一個。原本的
             * relation manager 只列 `order_events`，與主畫面那個已經合併了
             * 履約事件的時間表並存——同一頁兩份時間線，客服會不確定哪一個
             * 才是完整的，而兩處各自演進就會開始不一致。
             *
             * 合併後的唯一呈現位置是 `ViewOrder` 的「訂單時間線」Section，
             * 資料來自唯讀的 `OrderActivityTimeline`（order events ＋
             * fulfillment events，穩定排序、每列帶唯一 key）。
             *
             * ⛔ 類別本身保留未刪：它不再被掛載，但刪掉會影響既有測試與任何
             * 外部引用，而本輪的目標是「只呈現一份」，不是清理程式碼。
             */
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
