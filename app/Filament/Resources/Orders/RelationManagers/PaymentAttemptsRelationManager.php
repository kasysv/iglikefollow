<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Enums\PaymentStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Every payment attempt made against this order.
 *
 * ⛔ Read-only: an attempt records what a provider reported, so it must not be
 * editable by hand.
 */
class PaymentAttemptsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentAttempts';

    protected static ?string $title = '付款紀錄';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('時間')->dateTime('Y-m-d H:i:s'),

                TextColumn::make('provider')->label('付款方式')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'line-pay' => 'LINE Pay',
                        'ecpay' => '綠界付款',
                        default => $state,
                    }),

                TextColumn::make('reference')->label('付款參考')->copyable(),

                TextColumn::make('status')->label('狀態')->badge()
                    ->formatStateUsing(fn (PaymentStatus $state) => $state->label())
                    ->color(fn (PaymentStatus $state) => $state->color()),

                TextColumn::make('amount')->label('金額')
                    ->formatStateUsing(fn ($state) => 'NT$'.number_format($state)),

                // 只顯示遮罩後的失敗代碼，⛔ 不存也不顯示完整 provider payload。
                TextColumn::make('failure_code')->label('失敗代碼')->placeholder('—')->color('gray'),

                TextColumn::make('completed_at')->label('完成時間')
                    ->dateTime('Y-m-d H:i:s')->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            // ⛔ 完全不提供新增、編輯、刪除或批次動作。
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
