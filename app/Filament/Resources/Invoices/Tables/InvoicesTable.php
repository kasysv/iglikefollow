<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order.reference')
                    ->label('訂單編號')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->formatStateUsing(fn (InvoiceStatus $state) => $state->label())
                    ->color(fn (InvoiceStatus $state) => $state->color()),

                TextColumn::make('amount')
                    ->label('金額')
                    ->formatStateUsing(fn ($state) => 'NT$'.number_format((int) $state))
                    ->sortable(),

                // ⛔ 遮罩顯示：對帳認得出是哪一張，但不完整回顯。
                TextColumn::make('invoice_number')
                    ->label('發票號碼')
                    ->formatStateUsing(fn ($state, Invoice $record) => $record->maskedInvoiceNumber() ?? '—'),

                TextColumn::make('issued_at')
                    ->label('開立時間')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('尚未開立')
                    ->sortable(),

                TextColumn::make('failure_message')
                    ->label('狀態說明')
                    ->placeholder('—')
                    ->limit(40)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('狀態')
                    ->options(fn () => collect(InvoiceStatus::cases())
                        ->mapWithKeys(fn (InvoiceStatus $s) => [$s->value => $s->label()])
                        ->all()),
            ])
            ->defaultSort('created_at', 'desc')
            // ⛔ 只有檢視：沒有重送、作廢、折讓或刪除。
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }
}
