<?php

namespace App\Filament\Resources\ServiceVariants\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ServiceVariantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->label('排序')->sortable(),
                TextColumn::make('service.name')->label('所屬服務')->searchable()->sortable(),
                TextColumn::make('label')->label('服務項目名稱')->searchable()->weight('bold'),
                TextColumn::make('unit_price')->label('單價')
                    ->formatStateUsing(fn ($state, $record) => 'NT$'.number_format((float) $state, 2).'／'.$record->quantity_unit)
                    ->sortable(),
                // ⛔ M3A:範圍內任何整數皆可,不再顯示「每 X 一階」。
                TextColumn::make('min_quantity')->label('可購買範圍')
                    ->formatStateUsing(fn ($state, $record) => number_format($state).'–'.number_format($record->max_quantity)),
                IconColumn::make('is_featured')->label('預設')->boolean(),
                TextColumn::make('status')->label('狀態')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'published' => '可購買',
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
                    'published' => '可購買',
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
