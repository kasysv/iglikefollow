<?php

namespace App\Filament\Resources\ProviderServices\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * ⛔ Every text column here is provider-controlled and rendered as plain,
 * escaped text — TextColumn's default. Nothing calls `->html()`, and nothing
 * ever may: a service name is exactly where a hostile catalog would put
 * markup.
 */
class ProviderServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')->label('供應商')->badge(),
                // 只有 Owner 看得到這張表，⛔ 所以這一欄不再另外遮罩。
                TextColumn::make('provider_service_id')->label('服務代碼')->copyable(),
                TextColumn::make('name')->label('名稱')->searchable()->wrap(),
                TextColumn::make('service_type')->label('型別原文'),
                TextColumn::make('category')->label('分類原文')->searchable(),
                TextColumn::make('rate_raw')->label('rate（供應商原始值）'),
                TextColumn::make('minimum_quantity_raw')->label('最小量原文'),
                TextColumn::make('maximum_quantity_raw')->label('最大量原文'),
                // ⛔ 只是文件欄位觀察，不代表本站提供補量／取消操作。
                IconColumn::make('supports_refill')->label('refill')->boolean(),
                IconColumn::make('supports_cancel')->label('cancel')->boolean(),
                IconColumn::make('is_available')->label('可用')->boolean(),
                TextColumn::make('last_seen_at')->label('最後觀察')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('id', 'desc')
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
