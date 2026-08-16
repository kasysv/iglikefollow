<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Models\OrderEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** 訂單時間線；⛔ 唯讀，不提供新增、編輯或刪除。 */
class OrderEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = '訂單時間線';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('時間')->dateTime('Y-m-d H:i:s'),

                TextColumn::make('type')->label('事件')
                    ->formatStateUsing(fn ($state, OrderEvent $record) => $record->label())
                    ->weight('bold'),

                TextColumn::make('summary')->label('說明')->wrap()->color('gray'),
            ])
            ->defaultSort('id')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
