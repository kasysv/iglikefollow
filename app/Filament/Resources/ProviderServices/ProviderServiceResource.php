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
 * ⛔ List only. No create, edit, delete, bulk action, sync button, connection
 * test or export: a catalog row is an observation, and the only legitimate
 * writer is a complete snapshot apply — which CATALOG-A deliberately gives no
 * entry point. A button here would be a claim that clicking it observed the
 * provider, which it did not.
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
