<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('姓名')->searchable()->weight('bold'),
                TextColumn::make('email')->label('電子信箱')->searchable()->color('gray'),
                TextColumn::make('role')->label('權限角色')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'owner' => '擁有者',
                        'editor' => '編輯',
                        default => $state,
                    })
                    ->color(fn (string $state) => $state === 'owner' ? 'success' : 'gray'),
                IconColumn::make('is_active')->label('啟用中')->boolean(),
                TextColumn::make('created_at')->label('建立時間')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')->label('角色')->options([
                    'owner' => '擁有者',
                    'editor' => '編輯',
                ]),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
