<?php

namespace App\Filament\Resources\Platforms;

use App\Filament\Resources\Platforms\Pages\CreatePlatform;
use App\Filament\Resources\Platforms\Pages\EditPlatform;
use App\Filament\Resources\Platforms\Pages\ListPlatforms;
use App\Filament\Resources\Platforms\Schemas\PlatformForm;
use App\Filament\Resources\Platforms\Tables\PlatformsTable;
use App\Models\Platform;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PlatformResource extends Resource
{
    protected static ?string $model = Platform::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PlatformForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlatformsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatforms::route('/'),
            'create' => CreatePlatform::route('/create'),
            'edit' => EditPlatform::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
