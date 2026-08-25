<?php

namespace App\Filament\Resources\FulfillmentOrders\Tables;

use App\Enums\FulfillmentAttentionReason;
use App\Enums\FulfillmentStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class FulfillmentOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('orderItem.order.reference')->label('訂單編號')->searchable(),
                TextColumn::make('orderItem.platform_name')->label('平台'),
                // ⛔ 完整 SMM 服務名稱：Owner／Editor 皆可見；服務代碼在下方仍 Owner-only。
                TextColumn::make('smm_service_name')
                    ->label('SMM 服務名稱')
                    ->state(fn ($record) => $record->displayServiceName()),
                TextColumn::make('orderItem.service_name')->label('本站分類')->toggleable(),
                TextColumn::make('orderItem.quantity')->label('數量')->numeric(),

                TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->formatStateUsing(fn (FulfillmentStatus $state) => $state->label())
                    ->color(fn (FulfillmentStatus $state) => $state->color()),

                TextColumn::make('attention_code')
                    ->label('待處理原因')
                    // ⛔ 顯示本地 enum 的訊息，不是 provider 傳來的文字。
                    ->formatStateUsing(fn (?FulfillmentAttentionReason $state) => $state?->message())
                    ->wrap()
                    ->toggleable(),

                /*
                 * ⛔ 供應商服務代碼只有 Owner 看得到。
                 *
                 * 它是商業敏感資訊，客服看訂單狀態不需要知道我們從哪裡進貨。
                 */
                TextColumn::make('provider_service_id_snapshot')
                    ->label('服務代碼')
                    ->visible(fn () => Auth::user()?->isOwner() ?? false)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('provider_order_id')->label('供應商單號')->toggleable(),
                TextColumn::make('submitted_at')->label('送出時間')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('created_at')->label('建立時間')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('狀態')
                    ->options(collect(FulfillmentStatus::cases())
                        ->mapWithKeys(fn (FulfillmentStatus $s) => [$s->value => $s->label()])),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([ViewAction::make()])
            // ⛔ 沒有任何批次動作：這張表完全唯讀。
            ->toolbarActions([]);
    }
}
