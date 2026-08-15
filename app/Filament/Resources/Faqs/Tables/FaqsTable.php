<?php

namespace App\Filament\Resources\Faqs\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class FaqsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->label('排序')->sortable(),
                TextColumn::make('question')->label('問題')->searchable()->wrap()->weight('bold'),
                TextColumn::make('scope')->label('顯示位置')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'global' => '全站',
                        'platform' => '平台頁',
                        'service' => '服務頁',
                        default => $state,
                    }),
                TextColumn::make('platform.name')->label('平台')->placeholder('—'),
                TextColumn::make('service.name')->label('服務')->placeholder('—'),
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
                SelectFilter::make('scope')->label('顯示位置')->options([
                    'global' => '全站',
                    'platform' => '平台頁',
                    'service' => '服務頁',
                ]),
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
