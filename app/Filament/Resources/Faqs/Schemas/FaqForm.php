<?php

namespace App\Filament\Resources\Faqs\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class FaqForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('常見問題')
                ->description('顯示在頁面下方的問答。可以決定要放在全站、某個平台，還是某個服務底下。')
                ->schema([
                    Select::make('scope')
                        ->label('顯示位置')
                        ->helperText('全站＝首頁的常見問題；平台＝只在該平台頁顯示；服務＝只在該服務頁顯示。')
                        ->options([
                            'global' => '全站（首頁）',
                            'platform' => '指定平台頁',
                            'service' => '指定服務頁',
                        ])
                        ->default('global')
                        ->required()
                        ->live(),

                    // scope 與 FK 組合必須一致；⛔ 不允許歸屬不相干的平台與服務。
                    Select::make('platform_id')
                        ->label('哪個平台')
                        ->relationship('platform', 'name')
                        ->searchable()
                        ->visible(fn ($get) => $get('scope') === 'platform')
                        ->required(fn ($get) => $get('scope') === 'platform'),

                    Select::make('service_id')
                        ->label('哪個服務')
                        ->relationship('service', 'name')
                        ->searchable()
                        ->visible(fn ($get) => $get('scope') === 'service')
                        ->required(fn ($get) => $get('scope') === 'service'),

                    TextInput::make('question')
                        ->label('問題')
                        ->helperText('客人會問的問題，例如：需要提供密碼嗎？')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Textarea::make('answer')
                        ->label('回答')
                        ->helperText('⚠️ 只能打純文字。HTML 標籤不會生效，會原樣顯示。')
                        ->required()
                        ->rows(4)
                        ->columnSpanFull(),
                ])->columns(2),

            Section::make('發布狀態')
                ->schema([
                    Select::make('status')
                        ->label('狀態')
                        ->helperText('草稿＝只有後台看得到；已發布＝公開顯示。只有擁有者可以改。')
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
                        ->helperText('數字越小排越上面。0 是第一題。')
                        ->numeric()
                        ->default(0)
                        ->required(),
                ])->columns(2),
        ]);
    }
}
