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

                /*
                 * ⭐ 失敗代碼直接列在清單上。
                 *
                 * ⛔ Owner 先前必須逐張點進去才看得到狀態，而且看到的還只是
                 * `UNKNOWN`。代碼形如 `ISSUE_RTN=10000001`，由本站固定 token
                 * 與綠界數字碼組成，⛔ 不含任何 provider 自由文字或 PII。
                 */
                TextColumn::make('failure_code')
                    ->label('失敗代碼')
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(),

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
