<?php

namespace App\Filament\Resources\Platforms\Schemas;

use App\Filament\Support\ImageField;
use App\Models\Platform;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class PlatformForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),

            TextInput::make('slug')
                ->required()
                ->maxLength(255)
                // 只允許 ASCII kebab-case
                ->rule('regex:/^[a-z0-9]+(-[a-z0-9]+)*$/')
                ->helperText('小寫英數與連字號；首次發布後鎖定。')
                // slug 首次發布後鎖定；只有 owner 能改未發布草稿。
                ->disabled(fn (?Platform $record) => $record?->isSlugLocked()
                    || ! Auth::user()?->isOwner())
                ->dehydrated(),

            TextInput::make('eyebrow')->maxLength(255),
            TextInput::make('h1')->maxLength(255),
            TextInput::make('tagline')->maxLength(255),
            Textarea::make('intro')->rows(4)->columnSpanFull(),

            ImageField::upload('hero_image_path'),
            ImageField::alt('hero_image_alt'),

            TextInput::make('seo_title')->maxLength(255),
            TextInput::make('meta_description')->maxLength(255),

            Select::make('status')
                ->options([
                    'draft' => 'draft',
                    'published' => 'published',
                    'archived' => 'archived',
                ])
                ->default('draft')
                ->required()
                // ⛔ 只有 owner 能發布／下架。
                ->disabled(fn () => ! Auth::user()?->isOwner())
                ->dehydrated(),

            TextInput::make('sort_order')->numeric()->default(0)->required(),
        ]);
    }
}
