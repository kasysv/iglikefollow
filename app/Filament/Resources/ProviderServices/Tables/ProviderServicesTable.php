<?php

namespace App\Filament\Resources\ProviderServices\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * ⛔ Every text column here is provider-controlled and rendered as plain,
 * escaped text — TextColumn's default. Nothing calls `->html()`, and nothing
 * ever may: a service name is exactly where a hostile catalog would put
 * markup.
 *
 * M2-E-B narrowed the main screen to what a non-technical operator needs in
 * order to recognise a service: name, minimum and maximum. Search is by name
 * only. The raw observation columns (service ID, category, type, rate,
 * refill, cancel, last seen) are deliberately absent from this screen — they
 * remain in the database and on the model; this is a display decision, not a
 * data change. Still strictly read-only: no export, sync, test, create, edit
 * or delete.
 */
class ProviderServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * M2-E-B:主畫面只留普通人看得懂的三欄——服務名稱／最低量／最高量。
             * ⛔ 服務代碼、provider、分類、型別、rate、refill、cancel 與最後
             * 觀察時間一律不在主畫面出現;這張表是給人「找服務」用的,不是
             * 傾印供應商原始資料。搜尋也只用名稱。
             * ⛔ 仍然唯讀:沒有列動作、批次動作、匯出、同步或測試。
             */
            ->columns([
                TextColumn::make('name')->label('服務名稱')->searchable()->wrap(),
                TextColumn::make('minimum_quantity_raw')->label('最低量'),
                TextColumn::make('maximum_quantity_raw')->label('最高量'),
            ])
            ->filters([
                TernaryFilter::make('is_available')->label('可用'),
            ])
            // 預設以服務名稱排序:主畫面沒有服務代碼可排。
            ->defaultSort('name', 'asc')
            // ⛔ 「尚未同步」而非「帳戶沒有服務」：本機從未觀察過真實 catalog。
            ->emptyStateHeading('尚未同步')
            ->emptyStateDescription(
                '本機尚未同步供應商服務目錄；這裡沒有資料代表尚未同步，不代表帳戶沒有服務。'
            )
            // ⛔ 沒有列動作、沒有批次動作：這是唯讀觀察，不是管理介面。
            ->recordActions([])
            ->toolbarActions([]);
    }
}
