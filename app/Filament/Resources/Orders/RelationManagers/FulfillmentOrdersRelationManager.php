<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Enums\FulfillmentAttentionReason;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Where this order stands with the supplier, on the order page itself.
 *
 * ⛔ Read-only, and deliberately without a retry or cancel button. Support
 * staff looking at a customer's order must be able to see what happened without
 * being able to assert what happened.
 */
class FulfillmentOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'fulfillmentOrders';

    protected static ?string $title = '履約紀錄';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                // ⛔ SMM 完整服務名稱：Owner／Editor 皆可見，服務代碼才是 Owner-only。
                TextColumn::make('smm_service_name')
                    ->label('SMM 服務名稱')
                    ->state(fn ($record) => $record->displayServiceName()),
                TextColumn::make('orderItem.service_name')->label('本站分類')->wrap(),
                TextColumn::make('orderItem.quantity')->label('數量')->numeric(),

                /*
                 * ⭐ 顯示 provider 原文；badge 顏色仍由內部 enum 決定。
                 * ⛔ 顏色不由原文推導——那等於用未經狀態機驗證的文字控制呈現。
                 */
                TextColumn::make('provider_status')
                    ->label('SMM 狀態')
                    ->badge()
                    ->state(fn ($record): string => $record->displayProviderStatus())
                    ->color(fn ($record) => $record->status->color()),

                // ⭐ 起始值：與剩餘同規則（null＝尚未取得、0＝確實是 0）。
                TextColumn::make('provider_start_count')
                    ->label('起始值')
                    ->state(fn ($record): string => $record->displayStartCount()),

                TextColumn::make('provider_remains')
                    ->label('剩餘數量（Remains）')
                    ->state(fn ($record): string => $record->displayRemains()),

                TextColumn::make('attention_code')
                    ->label('待處理原因')
                    // ⛔ 本地 enum 訊息。
                    ->formatStateUsing(fn (?FulfillmentAttentionReason $state) => $state?->message())
                    ->wrap(),

                // ⛔ 服務代碼只有 Owner 看得到。
                TextColumn::make('provider_service_id_snapshot')
                    ->label('服務代碼')
                    ->visible(fn () => Auth::user()?->isOwner() ?? false),

                TextColumn::make('provider_order_id')->label('供應商單號')->placeholder('—'),
                TextColumn::make('submitted_at')->label('送出時間')->dateTime('Y-m-d H:i')->placeholder('—'),
            ])
            ->defaultSort('id')
            // ⛔ 完全不提供新增、編輯、刪除、重送或批次動作。
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
