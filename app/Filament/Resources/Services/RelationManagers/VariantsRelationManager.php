<?php

namespace App\Filament\Resources\Services\RelationManagers;

use App\Filament\Resources\ServiceVariants\Schemas\ServiceVariantForm;
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

    protected static ?string $title = '款式';

    public function form(Schema $schema): Schema
    {
        // 共用同一份表單定義，⛔ 避免這裡少掉數量交叉驗證等規則。
        // withOwner: false ⛔ 不顯示「所屬服務」，款式不可從這裡改掛到別的服務。
        return ServiceVariantForm::configure($schema, withOwner: false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                TextColumn::make('sort_order')->label('排序')->sortable(),
                TextColumn::make('label')->label('款式名稱')->searchable()->weight('bold'),
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
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                CreateAction::make()
                    ->label('新增款式')
                    // 歸屬一律由 owner record 決定，⛔ 即使表單被塞入 service_id 也不採用。
                    ->mutateDataUsing(fn (array $data): array => $this->ownedBy($data)),
            ])
            // ⛔ 不提供 Associate／Dissociate：款式必須屬於這個服務，不可轉掛。
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data): array => $this->ownedBy($data)),
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
