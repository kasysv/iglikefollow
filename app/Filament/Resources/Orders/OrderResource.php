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
             * ⭐ 唯一的合併時間線，位於頁面**下方**。
             *
             * Owner 原話是把主畫面「訂單時間表」併入下方既有的「訂單時間線」。
             * A1 做反了方向（移除下方、保留上方），R1 改回來：這個 relation
             * manager 以自訂唯讀 view 呈現 `OrderActivityTimeline` 的合併資料
             * （order events ＋ fulfillment events），主畫面那個重複的
             * Section 已移除。
             */
            RelationManagers\OrderEventsRelationManager::class,
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
