<?php

namespace App\Filament\Resources\Services\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class FaqsRelationManager extends RelationManager
{
    protected static string $relationship = 'faqs';

    protected static ?string $title = '常見問題';

    public function form(Schema $schema): Schema
    {
        // 這裡建立的 FAQ 一律屬於本服務，故不顯示 scope 與平台選單。
        return $schema->components([
            TextInput::make('question')
                ->label('問題')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Textarea::make('answer')
                ->label('回答')
                ->helperText('⚠️ 只能打純文字。HTML 標籤不會生效，會原樣顯示。')
                ->required()
                ->rows(4)
                ->columnSpanFull(),

            Select::make('status')
                ->label('狀態')
                ->options([
                    'draft' => '草稿（不公開）',
                    'published' => '已發布（公開）',
                    'archived' => '已下架',
                ])
                ->default('draft')
                ->required()
                ->disabled(fn () => ! Auth::user()?->isOwner())
                ->dehydrated(),

            TextInput::make('sort_order')
                ->label('排序')
                ->numeric()
                ->default(0)
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('question')
            ->columns([
                TextColumn::make('sort_order')->label('排序')->sortable(),
                TextColumn::make('question')->label('問題')->searchable()->wrap()->weight('bold'),
                TextColumn::make('status')->label('狀態')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'published' => '已發布',
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
                    ->label('新增問題')
                    ->mutateDataUsing(fn (array $data): array => $this->ownedBy($data)),
            ])
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data): array => $this->ownedBy($data)),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    /**
     * Pin both the owning service and the scope.
     *
     * scope must stay consistent with the foreign key: a row carrying
     * service_id but scope 'global' would surface this service's FAQ on every
     * page of the site.
     */
    private function ownedBy(array $data): array
    {
        $data['service_id'] = $this->getOwnerRecord()->getKey();
        $data['scope'] = 'service';
        $data['platform_id'] = null;

        return $data;
    }
}
