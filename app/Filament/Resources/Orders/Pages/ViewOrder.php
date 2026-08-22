<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Support\Money;
use App\Support\OrderOperationsSummary;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    /** ⛔ 沒有編輯、刪除或「標記為已付款」的動作。 */
    protected function getHeaderActions(): array
    {
        return [];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            /*
             * M4C:四條線的 read-only 摘要——訂單、付款、發票、履約各自獨立。
             * ⛔ 「尚未建立」就是不存在,不推論成敗;多筆規則見
             * OrderOperationsSummary;沒有任何重送/標記/測試按鈕。
             */
            Section::make('交易流程')
                ->description('四條線各自獨立;「尚未建立」代表該紀錄不存在,不代表成功或失敗。')
                ->schema([
                    TextEntry::make('ops_order')->label('訂單')
                        ->state(fn (Order $record): string => OrderOperationsSummary::for($record)['order']),
                    TextEntry::make('ops_payment')->label('付款')
                        ->state(fn (Order $record): string => OrderOperationsSummary::for($record)['payment']),
                    TextEntry::make('ops_invoice')->label('發票')
                        ->state(fn (Order $record): string => OrderOperationsSummary::for($record)['invoice']),
                    TextEntry::make('ops_fulfillment')->label('履約')
                        ->state(fn (Order $record): string => OrderOperationsSummary::for($record)['fulfillment']),
                ])->columns(2),

            Section::make('訂單')
                ->schema([
                    TextEntry::make('reference')->label('訂單編號')->copyable()->weight('bold'),
                    TextEntry::make('created_at')->label('建立時間')->dateTime('Y-m-d H:i:s'),
                    TextEntry::make('order_status')->label('訂單狀態')->badge()
                        ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                        ->color(fn (OrderStatus $state) => $state->color()),
                    TextEntry::make('payment_status')->label('付款狀態')->badge()
                        ->formatStateUsing(fn (PaymentStatus $state) => $state->label())
                        ->color(fn (PaymentStatus $state) => $state->color()),
                    TextEntry::make('total_amount')->label('應付金額')
                        ->formatStateUsing(fn ($state) => 'NT$'.number_format($state)),
                    TextEntry::make('paid_at')->label('付款完成時間')->dateTime('Y-m-d H:i:s')
                        ->placeholder('尚未付款'),
                ])->columns(3),

            Section::make('商品快照')
                ->description('下單當下的內容。⛔ 之後在後台改價、改名或下架都不會改變這裡。')
                ->schema([
                    TextEntry::make('items.platform_name')->label('平台'),
                    TextEntry::make('items.service_name')->label('服務分類'),
                    TextEntry::make('items.variant_label')->label('服務項目'),
                    TextEntry::make('items.sku')->label('商品編號')->placeholder('—'),
                    TextEntry::make('items.quantity')->label('數量')
                        ->formatStateUsing(fn ($state) => number_format((int) $state)),
                    // 完整四位小數快照，⛔ 不四捨五入成兩位顯示。
                    // ⛔ 標示為「計價率」：這不是客人付的錢，實際收款是下方的整數金額。
                    TextEntry::make('items.unit_price_mills')->label('單價（計價率）')
                        ->helperText('每一單位的計價率，不是實際收款金額。')
                        ->formatStateUsing(fn ($state) => 'NT$'.Money::format((int) $state).' / 單位'),
                    TextEntry::make('items.amount')->label('應付金額（整數台幣）')
                        ->formatStateUsing(fn ($state) => 'NT$'.number_format((int) $state)),
                    TextEntry::make('items.target_value')->label('交付對象'),
                ])->columns(3),

            Section::make('聯絡與發票')
                ->description('⛔ 僅顯示遮罩後的資料；完整 Email、手機、載具與統編不在後台回顯。')
                ->schema([
                    TextEntry::make('customer_email')->label('通知 Email')
                        ->formatStateUsing(fn ($state, Order $record) => $record->maskedEmail()),
                    TextEntry::make('customer_phone')->label('聯絡手機')
                        ->formatStateUsing(fn ($state, Order $record) => $record->maskedPhone() ?? '—'),
                    TextEntry::make('invoice_kind')->label('發票類型')
                        ->formatStateUsing(fn ($state, Order $record) => $record->invoiceSummary()),
                ])->columns(3),

            /*
             * M2-E-B:發票收進訂單頁,客服不必再切到獨立發票清單。
             * ⛔ 全部唯讀:沒有開立、重送、作廢或「標記已開立」按鈕——
             * 那些只屬於發票狀態機。
             * ⛔ 遮罩規則沿用 Invoice::maskedInvoiceNumber() 與
             * maskedProviderReference(),不因為換個畫面就放寬回顯。
             * 沒有發票時每一欄都顯示「尚未開立」,不推論成功或失敗。
             */
            Section::make('電子發票')
                ->description('⛔ 唯讀；發票號碼與供應商單號僅顯示遮罩後的值。「尚未開立」代表沒有這筆紀錄，不代表開立失敗。')
                ->schema([
                    TextEntry::make('invoice_status')->label('發票狀態')
                        ->state(fn (Order $record): string => $record->invoice?->status->label() ?? '尚未開立'),
                    TextEntry::make('invoice_number_masked')->label('發票號碼')
                        ->state(fn (Order $record): string => $record->invoice?->maskedInvoiceNumber() ?? '尚未開立'),
                    TextEntry::make('invoice_issued_at')->label('開立時間')
                        ->state(fn (Order $record): string => $record->invoice?->issued_at?->format('Y-m-d H:i:s') ?? '尚未開立'),
                    TextEntry::make('invoice_reference_masked')->label('供應商單號')
                        ->state(fn (Order $record): string => $record->invoice?->maskedProviderReference() ?? '尚未開立'),
                    TextEntry::make('invoice_attempts')->label('開立嘗試')
                        ->state(fn (Order $record): string => $record->invoice === null
                            ? '尚未開立'
                            : $record->invoice->attempts()->count().' 次'),
                    TextEntry::make('invoice_note')->label('狀態說明')
                        ->columnSpanFull()
                        ->state(fn (Order $record): string => OrderOperationsSummary::for($record)['invoice']),
                ])->columns(3),
        ]);
    }
}
