<?php

namespace App\Filament\Resources\ProviderServices;

use App\Filament\Resources\ProviderServices\Pages\ListProviderServices;
use App\Filament\Resources\ProviderServices\Tables\ProviderServicesTable;
use App\Models\ProviderService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Read-only view of what the supplier's catalog last declared.
 *
 * ⛔ List only. No create, edit, delete, bulk action or export. The only
 * legitimate writer is the complete snapshot action exposed from the
 * Owner-only integration settings page; this resource itself stays read-only.
 */
class ProviderServiceResource extends Resource
{
    protected static ?string $model = ProviderService::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $navigationLabel = 'TheMostPanel 服務目錄';

    protected static string|UnitEnum|null $navigationGroup = '履約與串接';

    protected static ?string $modelLabel = '供應商服務';

    protected static ?string $pluralModelLabel = '供應商服務';

    protected static ?int $navigationSort = 2;

    /*
     * M2-E-B:只從側邊導航隱藏,⛔ route、Resource、Model、policy 與資料
     * 全部保留——這是日常畫面的精簡,不是功能下架。直接輸入網址仍可進入
     * (授權與 noindex 照舊),也因此可以隨時回滾。
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return ProviderServicesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProviderServices::route('/'),
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
