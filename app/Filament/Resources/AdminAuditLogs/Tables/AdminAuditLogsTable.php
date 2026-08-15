<?php

namespace App\Filament\Resources\AdminAuditLogs\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AdminAuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable()->label('時間'),
                TextColumn::make('user.name')->searchable()->label('使用者'),
                TextColumn::make('auditable_type')->searchable()->label('模型'),
                TextColumn::make('auditable_id')->numeric()->label('ID'),
                TextColumn::make('action')->badge()->searchable()->label('動作'),
                TextColumn::make('ip_address')->searchable()->label('IP'),
            ])
            ->defaultSort('created_at', 'desc')
            // ⛔ 不提供 row actions 或 bulk delete：稽核紀錄不得從後台刪除。
            ->recordActions([])
            ->toolbarActions([]);
    }
}
