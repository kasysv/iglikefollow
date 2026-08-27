<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Support\OrderOperationsIndicators;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * ⛔⛔ 逐列的勾叉都要讀 items → latestFulfillmentOrder 與 invoice。
             *
             * ⭐ 不預先載入的話，25 列的列表會變成上百次查詢——而且是那種
             * 「本機看不出來、資料一多才爆」的慢。⛔ 這是新增欄位時最容易
             * 一起帶進來的問題，所以與欄位在同一個 commit 裡處理。
             *
             * ⛔ 只加 eager loading，⛔ 不為勾叉新增或修改任何 DB 欄位。
             */
            ->modifyQueryUsing(fn ($query) => $query->with([
                'invoice',
                'items.latestFulfillmentOrder',
            ]))
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

                /*
                 * ⛔⛔ 這裡**只保留付款狀態**，⛔ 移除了原本的「訂單狀態」欄。
                 *
                 * ⭐ 兩者在列表上大量重複：`OrderStatus` 只有待付款／已付款／
                 * 取消／逾期，仍然是付款生命週期的粗略版本；而 `PaymentStatus`
                 * 已經包含已建立／付款中／成功／失敗／取消／逾期／需人工對帳。
                 * ⛔ 兩欄並排會讓客服要比對兩個講同一件事的欄位。
                 *
                 * ⛔ 只移除**列表的呈現**：DB 欄位、enum、狀態機、篩選
                 * 與訂單詳情頁全部不動（下方 `order_status` 篩選仍在）。
                 */
                TextColumn::make('payment_status')
                    ->label('付款狀態')
                    ->badge()
                    ->formatStateUsing(fn (PaymentStatus $state) => $state->label())
                    ->color(fn (PaymentStatus $state) => $state->color()),

                /*
                 * ⛔ 只呈現**已保存**的發票狀態：最新一張為 `Issued` 才勾。
                 * ⛔ 不查 provider、⛔ 不推定。無發票、待開立、開立中、失敗、
                 * 需人工對帳、已作廢一律叉。
                 */
                IconColumn::make('invoice_issued')
                    ->label('發票')
                    ->boolean()
                    ->state(fn (Order $record): bool => OrderOperationsIndicators::invoiceIssued($record)),

                /*
                 * ⛔⛔ SMM 欄**不能只用勾叉**。
                 *
                 * ⭐ `Partial`／`Canceled` 必須看得見，且不得與「還有商品沒送出」
                 * 的叉互相覆蓋——三種指示可以同時出現，所以用自訂 view
                 * 而不是單一 `IconColumn`（後者一格只能有一個圖示與一個 tooltip）。
                 *
                 * ⛔ 平常不露文字：exact token 只在 tooltip／`aria-label` 裡。
                 */
                ViewColumn::make('smm_indicators')
                    ->label('SMM')
                    ->view('filament.tables.columns.smm-indicators'),

                /*
                 * ⭐ Owner 要求顯示**完整** Email 並可複製：客服要能直接拿它
                 * 去聯絡客人，遮罩後的地址在後台反而讓人得再點進訂單一次。
                 *
                 * ⛔ 只改**後台列表**這一處呈現：DB 的 `encrypted` cast 不變，
                 * ⛔ 公開頁、log 與匯出都不得因此出現明文。
                 * ⛔ `Order::maskedEmail()` 本身保留不刪：它仍是遮罩的唯一定義，
                 * 仍有測試把關，⛔ 只是這一欄不再呼叫它。
                 */
                TextColumn::make('customer_email')
                    ->label('通知 Email')
                    ->copyable()
                    ->color('gray'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                /*
                 * ⛔ 篩選保留兩者：移除的只有列表的重複**欄位**，
                 * ⛔ 不是把 `order_status` 這個維度整個拿掉。
                 */
                SelectFilter::make('order_status')
                    ->label('訂單狀態')
                    ->options(OrderStatus::options()),
                SelectFilter::make('payment_status')
                    ->label('付款狀態')
                    ->options(PaymentStatus::options()),
            ])
            /*
             * ⛔⛔ 移除右側的「檢視」按鈕，但**整列仍可點進同一個訂單**。
             *
             * ⭐ Filament 預設就會把列連到 resource 的 view page，
             * 所以拿掉按鈕之後客服並沒有失去入口——但這件事必須有測試釘住，
             * ⛔ 否則哪天預設改變就會變成「列表再也進不去訂單」。
             *
             * ⛔ 仍然沒有編輯、刪除或任何批次動作。
             */
            ->recordActions([])
            ->toolbarActions([]);
    }
}
