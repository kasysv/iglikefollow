<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
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
                    TextEntry::make('items.unit_price_cents')->label('單價')
                        ->formatStateUsing(fn ($state) => 'NT$'.number_format($state / 100, 2)),
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
        ]);
    }
}
