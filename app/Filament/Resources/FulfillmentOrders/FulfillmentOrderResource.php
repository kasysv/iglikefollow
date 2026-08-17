<?php

namespace App\Filament\Resources\FulfillmentOrders;

use App\Filament\Resources\FulfillmentOrders\Pages\ListFulfillmentOrders;
use App\Filament\Resources\FulfillmentOrders\Pages\ViewFulfillmentOrder;
use App\Filament\Resources\FulfillmentOrders\Tables\FulfillmentOrdersTable;
use App\Models\FulfillmentOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Read-only fulfilment records.
 *
 * ⛔ No create, edit, delete, bulk action, retry or cancel. Every one of those
 * would be a claim about what a supplier did that clicking a button here cannot
 * make true. Rows needing judgement stop in `submission_unknown` and wait for
 * someone to check with the provider.
 */
class FulfillmentOrderResource extends Resource
{
    protected static ?string $model = FulfillmentOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = '履約紀錄';

    protected static ?string $modelLabel = '履約紀錄';

    protected static ?string $pluralModelLabel = '履約紀錄';

    protected static ?int $navigationSort = 11;

    public static function table(Table $table): Table
    {
        return FulfillmentOrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFulfillmentOrders::route('/'),
            'view' => ViewFulfillmentOrder::route('/{record}'),
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
