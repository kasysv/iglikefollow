<?php

namespace App\Filament\Resources\ServiceContentSections\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ServiceContentSectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->label('排序')->sortable(),
                TextColumn::make('service.name')->label('所屬服務')->searchable()->sortable(),
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
            ->filters([
                SelectFilter::make('service')->label('服務')->relationship('service', 'name'),
                SelectFilter::make('status')->label('狀態')->options([
                    'draft' => '草稿',
                    'published' => '已發布',
                    'archived' => '已下架',
                ]),
                TrashedFilter::make()->label('已刪除'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
