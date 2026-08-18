<?php

namespace App\Filament\Resources\ProviderServices\Tables;

use App\Models\ProviderService;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * ⛔ Every text column here is provider-controlled and rendered as plain,
 * escaped text — TextColumn's default. Nothing calls `->html()`, and nothing
 * ever may: a service name is exactly where a hostile catalog would put
 * markup. Filter options built from catalog values are rendered through the
 * same escaping — they are labels, never markup.
 *
 * 264 real rows made scrolling useless: the Owner reviews the catalog by
 * searching service ID / name / category and narrowing by availability,
 * category, type, refill and cancel. Still strictly read-only — no export,
 * sync, test, create, edit or delete.
 */
class ProviderServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')->label('供應商')->badge(),
                // 只有 Owner 看得到這張表，⛔ 所以這一欄不再另外遮罩。
                TextColumn::make('provider_service_id')
                    ->label('服務代碼')
                    ->copyable()
                    ->searchable()
                    /*
                     * 服務代碼是 canonical digit string:數值排序用
                     * 長度＋lexicographic,與 parser／compatibility 的
                     * overflow-safe 比較同一語意;⛔ 不 CAST 成 int。
                     * $direction 由 Filament 提供,只會是 asc／desc。
                     */
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw("length(provider_service_id) {$direction}, provider_service_id {$direction}")),
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
            ->filters([
                TernaryFilter::make('is_available')->label('可用'),
                /*
                 * 分類／型別選項取自已觀察 catalog 的 distinct 值。
                 * ⛔ provider-controlled text 只能是純文字 label,Filament
                 * 以 escaped 文字渲染選項;絕不 ->html()。
                 */
                SelectFilter::make('category')
                    ->label('分類')
                    ->options(fn (): array => ProviderService::query()
                        ->distinct()
                        ->orderBy('category')
                        ->pluck('category', 'category')
                        ->all()),
                SelectFilter::make('service_type')
                    ->label('型別')
                    ->options(fn (): array => ProviderService::query()
                        ->distinct()
                        ->orderBy('service_type')
                        ->pluck('service_type', 'service_type')
                        ->all()),
                TernaryFilter::make('supports_refill')->label('refill'),
                TernaryFilter::make('supports_cancel')->label('cancel'),
            ])
            // 預設:服務代碼由最小開始(數值序),小代碼在第一頁。
            ->defaultSort('provider_service_id', 'asc')
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
