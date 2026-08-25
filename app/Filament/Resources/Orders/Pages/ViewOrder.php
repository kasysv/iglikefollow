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
use Illuminate\Support\Facades\Auth;

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
                    TextEntry::make('items.target_value')->label('交付對象')->copyable(),
                ])->columns(3),

            /*
             * ⛔ Owner 與 Editor 都能看到完整聯絡與交付資料——客服需要這些
             * 才能真的聯絡客人、確認交付對象。OrderPolicy 本身已把兩者都
             * 擋在頁面外的角色排除掉，所以進得了這一頁就可以看到這裡。
             */
            Section::make('客戶聯絡與交付資料')
                ->description('完整資料，可直接複製聯絡客人。')
                ->schema([
                    TextEntry::make('customer_email')->label('通知 Email')->copyable(),
                    TextEntry::make('customer_phone')->label('聯絡手機')
                        ->placeholder('未提供')->copyable(),
                ])->columns(3),

            /*
             * ⛔ 發票是稅務資料，只有 Owner 看得到完整值；Editor 進得了這一頁
             * 但這個 section 對其不可見，比對照 InvoicePolicy(Owner-only)。
             * 每種發票輸入模式只顯示與其相關的欄位，不相關的 null 不堆版面。
             */
            Section::make('客戶要求的發票資料')
                ->visible(fn (): bool => Auth::user()?->isOwner() ?? false)
                ->schema([
                    /*
                     * ⛔ 明確的類型標籤,不沿用 invoiceSummary()——那個方法是
                     * 給遮罩畫面用的摘要,公司模式會把統編後 3 碼再摘要一次,
                     * 與下方完整統編欄位重複且語意是「遮罩」不是「類型」。
                     */
                    TextEntry::make('invoice_kind')->label('發票類型')
                        ->state(fn (Order $record): string => match (true) {
                            $record->invoice_kind === 'business' => '公司電子發票',
                            $record->personal_invoice_mode === 'mobile_barcode' => '個人電子發票（手機條碼載具）',
                            $record->personal_invoice_mode === 'donation' => '個人電子發票（捐贈）',
                            default => '個人電子發票（寄送至 Email）',
                        }),
                    // ⛔ 只在 personal_invoice_mode 明確為 email 時顯示,不是「非公司」就顯示。
                    TextEntry::make('invoice_email')->label('寄送 Email')
                        ->visible(fn (Order $record): bool => $record->invoice_kind !== 'business'
                            && $record->personal_invoice_mode === 'email')
                        ->state(fn (Order $record): string => (string) $record->customer_email)
                        ->copyable(),
                    TextEntry::make('carrier_number')->label('手機條碼載具')
                        ->visible(fn (Order $record): bool => $record->invoice_kind !== 'business'
                            && $record->personal_invoice_mode === 'mobile_barcode')
                        ->copyable(),
                    TextEntry::make('love_code')->label('捐贈碼')
                        ->visible(fn (Order $record): bool => $record->invoice_kind !== 'business'
                            && $record->personal_invoice_mode === 'donation')
                        ->copyable(),
                    TextEntry::make('buyer_tax_id')->label('統一編號')
                        ->visible(fn (Order $record): bool => $record->invoice_kind === 'business')
                        ->copyable(),
                    TextEntry::make('buyer_name')->label('公司抬頭')
                        ->visible(fn (Order $record): bool => $record->invoice_kind === 'business')
                        ->copyable(),
                ])->columns(3),

            /*
             * M2-E-B:發票收進訂單頁,客服不必再切到獨立發票清單。
             * ⛔ 全部唯讀:沒有開立、重送、作廢或「標記已開立」按鈕——
             * 那些只屬於發票狀態機。⛔ Owner-only:比對 InvoicePolicy,一張
             * 發票的完整號碼／隨機碼／provider 參考碼是稅務對帳資料。
             * 沒有發票時每一欄都顯示「尚未開立」,不推論成功或失敗。
             */
            Section::make('實際開立結果')
                ->description('「尚未開立」代表沒有這筆紀錄，不代表開立失敗。')
                ->visible(fn (): bool => Auth::user()?->isOwner() ?? false)
                ->schema([
                    TextEntry::make('invoice_status')->label('發票狀態')
                        ->state(fn (Order $record): string => $record->invoice?->status->label() ?? '尚未開立'),
                    TextEntry::make('invoice_amount')->label('金額（整數台幣）')
                        ->state(fn (Order $record): string => $record->invoice === null
                            ? '尚未開立'
                            : 'NT$'.number_format($record->invoice->amount)),
                    TextEntry::make('invoice_number_full')->label('發票號碼')
                        ->state(fn (Order $record): string => $record->invoice?->invoice_number ?? '尚未開立')
                        ->copyable(),
                    TextEntry::make('invoice_random_code')->label('隨機碼')
                        ->state(fn (Order $record): string => $record->invoice?->random_code ?? '尚未開立')
                        ->copyable(),
                    TextEntry::make('invoice_reference_full')->label('供應商參考碼')
                        ->state(fn (Order $record): string => $record->invoice?->provider_reference ?? '尚未開立')
                        ->copyable(),
                    TextEntry::make('invoice_issued_at')->label('開立時間')
                        ->state(fn (Order $record): string => $record->invoice?->issued_at?->format('Y-m-d H:i:s') ?? '尚未開立'),
                    TextEntry::make('invoice_voided_at')->label('作廢時間')
                        ->state(fn (Order $record): string => $record->invoice?->voided_at?->format('Y-m-d H:i:s') ?? '未作廢'),
                    TextEntry::make('invoice_allowance_at')->label('折讓時間')
                        ->state(fn (Order $record): string => $record->invoice?->allowance_at?->format('Y-m-d H:i:s') ?? '無折讓'),
                    TextEntry::make('invoice_reconciliation_required_at')->label('需人工對帳時間')
                        ->state(fn (Order $record): string => $record->invoice?->reconciliation_required_at?->format('Y-m-d H:i:s') ?? '—'),
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
