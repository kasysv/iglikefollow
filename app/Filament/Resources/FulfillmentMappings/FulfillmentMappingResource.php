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
use UnitEnum;

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

    protected static ?string $navigationLabel = '商品派單對照';

    protected static string|UnitEnum|null $navigationGroup = '履約與串接';

    protected static ?string $modelLabel = '履約對應';

    protected static ?string $pluralModelLabel = '履約對應';

    protected static ?int $navigationSort = 1;

    /*
     * M2-E-B:只從側邊導航隱藏,⛔ route、Resource、Model、policy 與資料
     * 全部保留——這是日常畫面的精簡,不是功能下架。直接輸入網址仍可進入
     * (授權與 noindex 照舊),也因此可以隨時回滾。
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

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
