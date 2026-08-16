<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    /** ⛔ 沒有重送、作廢、折讓、編輯或刪除動作。 */
    protected function getHeaderActions(): array
    {
        return [];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('發票')
                ->schema([
                    TextEntry::make('order.reference')->label('訂單編號')->copyable()->weight('bold'),
                    TextEntry::make('status')->label('狀態')->badge()
                        ->formatStateUsing(fn (InvoiceStatus $state) => $state->label())
                        ->color(fn (InvoiceStatus $state) => $state->color()),
                    TextEntry::make('amount')->label('金額（整數台幣）')
                        ->formatStateUsing(fn ($state) => 'NT$'.number_format((int) $state)),
                    // ⛔ 遮罩顯示，不完整回顯。
                    TextEntry::make('invoice_number')->label('發票號碼')
                        ->formatStateUsing(fn ($state, Invoice $record) => $record->maskedInvoiceNumber() ?? '—'),
                    TextEntry::make('provider_reference')->label('provider 參考碼')
                        ->formatStateUsing(fn ($state, Invoice $record) => $record->maskedProviderReference() ?? '—'),
                    TextEntry::make('issued_at')->label('開立時間')->dateTime('Y-m-d H:i:s')
                        ->placeholder('尚未開立'),
                ])->columns(3),

            Section::make('狀態說明')
                ->description('⛔ 只顯示整理過的訊息，不含原始回應或個資。')
                ->schema([
                    TextEntry::make('failure_code')->label('狀態碼')->placeholder('—'),
                    TextEntry::make('failure_message')->label('說明')->placeholder('—'),
                    TextEntry::make('reconciliation_required_at')->label('需人工對帳時間')
                        ->dateTime('Y-m-d H:i:s')->placeholder('—'),
                ])->columns(3),

            Section::make('開立嘗試')
                ->description('每次向 provider 請求的結果。⛔ 不保存原始請求與回應內容。')
                ->schema([
                    RepeatableEntry::make('attempts')
                        ->label('')
                        ->schema([
                            TextEntry::make('status')->label('結果')
                                ->formatStateUsing(fn ($state) => $state->label()),
                            TextEntry::make('started_at')->label('開始')->dateTime('Y-m-d H:i:s'),
                            TextEntry::make('completed_at')->label('結束')->dateTime('Y-m-d H:i:s')
                                ->placeholder('尚未結束'),
                            TextEntry::make('failure_message')->label('說明')->placeholder('—'),
                        ])->columns(4),
                ]),
        ]);
    }
}
