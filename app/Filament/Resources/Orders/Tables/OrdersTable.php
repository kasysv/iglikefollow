<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('訂單編號')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                TextColumn::make('items.variant_label')
                    ->label('服務項目')
                    ->listWithLineBreaks()
                    ->limitList(2),

                TextColumn::make('total_amount')
                    ->label('金額')
                    ->formatStateUsing(fn ($state) => 'NT$'.number_format($state))
                    ->sortable(),

                TextColumn::make('order_status')
                    ->label('訂單狀態')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                    ->color(fn (OrderStatus $state) => $state->color()),

                TextColumn::make('payment_status')
                    ->label('付款狀態')
                    ->badge()
                    ->formatStateUsing(fn (PaymentStatus $state) => $state->label())
                    ->color(fn (PaymentStatus $state) => $state->color()),

                // ⛔ 列表只顯示遮罩後的 Email，不顯示完整地址或電話。
                TextColumn::make('customer_email')
                    ->label('通知 Email')
                    ->formatStateUsing(fn ($state, Order $record) => $record->maskedEmail())
                    ->color('gray'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('order_status')
                    ->label('訂單狀態')
                    ->options(OrderStatus::options()),
                SelectFilter::make('payment_status')
                    ->label('付款狀態')
                    ->options(PaymentStatus::options()),
            ])
            // ⛔ 只提供檢視；不提供編輯、刪除或任何批次動作。
            ->recordActions([ViewAction::make()->label('檢視')])
            ->toolbarActions([]);
    }
}
