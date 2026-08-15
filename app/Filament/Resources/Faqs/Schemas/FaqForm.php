<?php

namespace App\Filament\Resources\Faqs\Schemas;

use App\Models\Faq;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class FaqForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('scope')
                ->options(array_combine(Faq::SCOPES, Faq::SCOPES))
                ->default('global')
                ->required()
                ->live(),

            // scope 與 FK 組合必須一致；⛔ 不允許歸屬不相干的平台與服務。
            Select::make('platform_id')
                ->relationship('platform', 'name')
                ->searchable()
                ->visible(fn ($get) => $get('scope') === 'platform')
                ->required(fn ($get) => $get('scope') === 'platform'),

            Select::make('service_id')
                ->relationship('service', 'name')
                ->searchable()
                ->visible(fn ($get) => $get('scope') === 'service')
                ->required(fn ($get) => $get('scope') === 'service'),

            TextInput::make('question')->required()->maxLength(255),
            Textarea::make('answer')->required()->rows(4)->columnSpanFull()
                ->helperText('純文字；⛔ 前台以固定模板輸出，不接受 HTML 或 script。'),

            Select::make('status')
                ->options([
                    'draft' => 'draft',
                    'published' => 'published',
                    'archived' => 'archived',
                ])
                ->default('draft')
                ->required()
                ->disabled(fn () => ! Auth::user()?->isOwner())
                ->dehydrated(),

            TextInput::make('sort_order')->numeric()->default(0)->required(),
        ]);
    }
}
