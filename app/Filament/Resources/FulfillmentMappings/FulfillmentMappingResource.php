<?php

namespace App\Filament\Resources\FulfillmentMappings;

use App\Filament\Resources\FulfillmentMappings\Pages\CreateFulfillmentMapping;
use App\Filament\Resources\FulfillmentMappings\Pages\EditFulfillmentMapping;
use App\Filament\Resources\FulfillmentMappings\Pages\ListFulfillmentMappings;
use App\Filament\Resources\FulfillmentMappings\Schemas\FulfillmentMappingForm;
use App\Filament\Resources\FulfillmentMappings\Tables\FulfillmentMappingsTable;
use App\Models\FulfillmentMapping;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Owner-only supplier mappings.
 *
 * ⛔ No delete page and no delete action. A mapping is referenced by every
 * fulfilment row created from it; removing one would leave those rows unable to
 * say where they were sent. `is_enabled` is how a mapping is taken out of use.
 */
class FulfillmentMappingResource extends Resource
{
    protected static ?string $model = FulfillmentMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = '履約對應';

    protected static ?string $modelLabel = '履約對應';

    protected static ?string $pluralModelLabel = '履約對應';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return FulfillmentMappingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FulfillmentMappingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFulfillmentMappings::route('/'),
            'create' => CreateFulfillmentMapping::route('/create'),
            'edit' => EditFulfillmentMapping::route('/{record}/edit'),
        ];
    }

    // ⛔ 只能停用，不能刪除。
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
