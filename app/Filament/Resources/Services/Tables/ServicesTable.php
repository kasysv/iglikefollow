<?php

namespace App\Filament\Resources\Services\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->label('排序')->sortable(),
                TextColumn::make('platform.name')->label('平台')->searchable()->sortable(),
                TextColumn::make('name')->label('服務名稱')->searchable()->weight('bold'),
                TextColumn::make('slug')->label('網址代碼')->searchable()->color('gray'),
                TextColumn::make('variants_count')->label('款式數')->counts('variants'),
                IconColumn::make('is_featured')->label('主打')->boolean(),
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
                TextColumn::make('updated_at')->label('最後修改')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                SelectFilter::make('platform')->label('平台')->relationship('platform', 'name'),
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
