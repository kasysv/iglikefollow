<?php

namespace App\Filament\Resources\FulfillmentMappings\Tables;

use App\Enums\FulfillmentPayloadType;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FulfillmentMappingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('serviceVariant.service.platform.name')->label('平台')->sortable(),
                TextColumn::make('serviceVariant.service.name')->label('服務'),
                TextColumn::make('serviceVariant.label')->label('款式'),
                TextColumn::make('provider')->label('供應商')->badge(),
                // 只有 Owner 看得到這張表，⛔ 所以這一欄不再另外遮罩。
                TextColumn::make('provider_service_id')->label('服務代碼')->copyable(),
                TextColumn::make('payload_type')
                    ->label('型別')
                    ->formatStateUsing(fn (FulfillmentPayloadType $state) => $state->label()),
                IconColumn::make('is_enabled')->label('啟用')->boolean(),
                TextColumn::make('updated_at')->label('更新時間')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([EditAction::make()])
            // ⛔ 沒有批次動作：批次刪除正是這裡最不該存在的東西。
            ->toolbarActions([]);
    }
}
