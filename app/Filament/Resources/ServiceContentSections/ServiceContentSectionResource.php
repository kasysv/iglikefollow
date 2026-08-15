<?php

namespace App\Filament\Resources\ServiceContentSections;

use App\Filament\Resources\ServiceContentSections\Pages\CreateServiceContentSection;
use App\Filament\Resources\ServiceContentSections\Pages\EditServiceContentSection;
use App\Filament\Resources\ServiceContentSections\Pages\ListServiceContentSections;
use App\Filament\Resources\ServiceContentSections\Schemas\ServiceContentSectionForm;
use App\Filament\Resources\ServiceContentSections\Tables\ServiceContentSectionsTable;
use App\Models\ServiceContentSection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ServiceContentSectionResource extends Resource
{
    protected static ?string $model = ServiceContentSection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = '內容段落';

    protected static ?string $modelLabel = '內容段落';

    protected static ?string $pluralModelLabel = '內容段落';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return ServiceContentSectionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServiceContentSectionsTable::configure($table);
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
            'index' => ListServiceContentSections::route('/'),
            'create' => CreateServiceContentSection::route('/create'),
            'edit' => EditServiceContentSection::route('/{record}/edit'),
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
