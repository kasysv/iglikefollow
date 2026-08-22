<?php

namespace App\Filament\Resources\Services\RelationManagers;

use App\Filament\Resources\Services\RelationManagers\Actions\ConfigureSmmMappingAction;
use App\Filament\Resources\ServiceVariants\Schemas\ServiceVariantForm;
use App\Models\ServiceVariant;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = '服務項目';

    public function form(Schema $schema): Schema
    {
        // 共用同一份表單定義，⛔ 避免這裡少掉數量交叉驗證等規則。
        // withOwner: false ⛔ 不顯示「所屬服務」，服務項目不可從這裡改掛到別的服務。
        return ServiceVariantForm::configure($schema, withOwner: false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                TextColumn::make('sort_order')->label('排序')->sortable(),
                TextColumn::make('label')->label('服務項目名稱')->searchable()->weight('bold'),
                TextColumn::make('unit_price')->label('單價')
                    ->formatStateUsing(fn ($state, $record) => 'NT$'.number_format((float) $state, 2).'／'.$record->quantity_unit),
                TextColumn::make('min_quantity')->label('可購買範圍')
                    ->formatStateUsing(fn ($state, $record) => number_format($state).'–'.number_format($record->max_quantity)),
                IconColumn::make('is_featured')->label('預設')->boolean(),
                TextColumn::make('status')->label('狀態')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'published' => '可購買',
                        'draft' => '草稿',
                        'archived' => '已下架',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'published' => 'success',
                        'draft' => 'warning',
                        default => 'gray',
                    }),
                /*
                 * M2-E-B:在方案列直接看到 SMM 對應狀態。
                 * ⛔ 整欄對非 Owner 隱藏——對應會顯示供應商服務名稱,屬商業
                 * 敏感資訊;FulfillmentMappingPolicy 明文不讓 Editor 看到。
                 * ⛔ 只顯示名稱／最低量／最高量／啟用狀態,不含 ID、分類、
                 * 型別、rate、refill、cancel 或任何成本。
                 */
                TextColumn::make('smm_mapping')->label('SMM 服務')
                    ->visible(fn (): bool => ConfigureSmmMappingAction::allowed())
                    ->state(fn (ServiceVariant $record): string => ConfigureSmmMappingAction::statusFor($record))
                    ->wrap(),
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                CreateAction::make()
                    ->label('新增服務項目')
                    // 歸屬一律由 owner record 決定，⛔ 即使表單被塞入 service_id 也不採用。
                    ->mutateDataUsing(fn (array $data): array => $this->ownedBy($data)),
            ])
            // ⛔ 不提供 Associate／Dissociate：服務項目必須屬於這個服務，不可轉掛。
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data): array => $this->ownedBy($data)),
                // M2-E-B:同頁設定 SMM 對應;⛔ 只有 Owner 看得到(action 內另有授權檢查)。
                ConfigureSmmMappingAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    /**
     * Pin the record to the service whose page we are on.
     *
     * The select is hidden from this form, but a hand-crafted Livewire payload
     * could still carry a service_id; overwriting it here means a variant can
     * never be moved to another service through a relation manager.
     */
    private function ownedBy(array $data): array
    {
        $data['service_id'] = $this->getOwnerRecord()->getKey();

        return $data;
    }
}
