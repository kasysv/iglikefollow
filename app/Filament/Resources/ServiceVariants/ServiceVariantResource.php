<?php

namespace App\Filament\Resources\ServiceVariants;

use App\Filament\Resources\ServiceVariants\Pages\CreateServiceVariant;
use App\Filament\Resources\ServiceVariants\Pages\EditServiceVariant;
use App\Filament\Resources\ServiceVariants\Pages\ListServiceVariants;
use App\Filament\Resources\ServiceVariants\Schemas\ServiceVariantForm;
use App\Filament\Resources\ServiceVariants\Tables\ServiceVariantsTable;
use App\Models\ServiceVariant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class ServiceVariantResource extends Resource
{
    protected static ?string $model = ServiceVariant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = '商品方案與價格';

    protected static string|UnitEnum|null $navigationGroup = '商品與價格';

    protected static ?string $modelLabel = '服務項目';

    protected static ?string $pluralModelLabel = '服務項目';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return ServiceVariantForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServiceVariantsTable::configure($table);
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
            'index' => ListServiceVariants::route('/'),
            'create' => CreateServiceVariant::route('/create'),
            'edit' => EditServiceVariant::route('/{record}/edit'),
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
