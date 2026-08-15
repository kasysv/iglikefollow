<?php

namespace App\Filament\Resources\Services\RelationManagers;

use App\Filament\Resources\ServiceContentSections\Schemas\ServiceContentSectionForm;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContentSectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'contentSections';

    protected static ?string $title = '內容段落';

    public function form(Schema $schema): Schema
    {
        // withOwner: false ⛔ 不顯示「所屬服務」，段落不可從這裡改掛到別的服務。
        return ServiceContentSectionForm::configure($schema, withOwner: false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('heading')
            ->columns([
                TextColumn::make('sort_order')->label('排序')->sortable(),
                TextColumn::make('heading')->label('段落標題')->searchable()->weight('bold'),
                TextColumn::make('body')->label('內容預覽')->limit(60)->color('gray'),
                TextColumn::make('status')->label('狀態')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'published' => '已發布',
                        'draft' => '草稿',
                        'archived' => '已下架',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'published' => 'success',
                        'draft' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                CreateAction::make()
                    ->label('新增段落')
                    ->mutateDataUsing(fn (array $data): array => $this->ownedBy($data)),
            ])
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data): array => $this->ownedBy($data)),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    /** 歸屬一律由 owner record 決定，⛔ 表單送來的 service_id 不採用。 */
    private function ownedBy(array $data): array
    {
        $data['service_id'] = $this->getOwnerRecord()->getKey();

        return $data;
    }
}
