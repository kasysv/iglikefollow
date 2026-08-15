<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Filament\Support\ImageField;
use App\Models\Service;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('platform_id')
                ->relationship('platform', 'name')
                ->required()
                ->searchable(),

            TextInput::make('name')->required()->maxLength(255),

            TextInput::make('slug')
                ->required()
                ->maxLength(255)
                ->rule('regex:/^[a-z0-9]+(-[a-z0-9]+)*$/')
                ->helperText('小寫英數與連字號；同一平台內唯一，首次發布後鎖定。')
                ->disabled(fn (?Service $record) => $record?->isSlugLocked()
                    || ! Auth::user()?->isOwner())
                ->dehydrated(),

            TextInput::make('card_title')->maxLength(255),
            TextInput::make('h1')->maxLength(255),
            TextInput::make('summary')->maxLength(255),
            TextInput::make('goal')->maxLength(255)->helperText('例如：帳號規模、單篇互動'),
            TextInput::make('card_blurb')->maxLength(255),
            Textarea::make('intro')->rows(4)->columnSpanFull(),

            Select::make('input_kind')
                ->options(array_combine(Service::INPUT_KINDS, Service::INPUT_KINDS))
                ->default('account')
                ->required(),

            TextInput::make('input_label')->required()->maxLength(255),
            TextInput::make('input_hint')->maxLength(255),
            TextInput::make('delivery_summary')->maxLength(255),

            ImageField::upload('card_image_path', '4:3'),
            ImageField::alt('card_image_alt'),
            ImageField::upload('hero_image_path'),
            ImageField::alt('hero_image_alt'),

            TextInput::make('seo_title')->maxLength(255),
            TextInput::make('meta_description')->maxLength(255),

            Toggle::make('is_featured')->helperText('Hub 主打服務'),

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
