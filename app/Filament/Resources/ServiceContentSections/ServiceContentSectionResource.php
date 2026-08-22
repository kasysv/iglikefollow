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
use UnitEnum;

class ServiceContentSectionResource extends Resource
{
    protected static ?string $model = ServiceContentSection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static ?string $navigationLabel = 'SEO 內容段落';

    protected static string|UnitEnum|null $navigationGroup = '網站內容與 SEO';

    protected static ?string $modelLabel = '內容段落';

    protected static ?string $pluralModelLabel = '內容段落';

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
