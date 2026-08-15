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

class ServiceVariantResource extends Resource
{
    protected static ?string $model = ServiceVariant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

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
