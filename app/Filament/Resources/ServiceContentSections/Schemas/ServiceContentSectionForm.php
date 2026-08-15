<?php

namespace App\Filament\Resources\ServiceContentSections\Schemas;

use App\Filament\Support\ImageField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ServiceContentSectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('service_id')
                ->relationship('service', 'name')
                ->required()
                ->searchable(),

            TextInput::make('heading')->required()->maxLength(255),

            // ⛔ plain text；前台以固定模板輸出 H2／段落，不接受 HTML／script。
            Textarea::make('body')->required()->rows(6)->columnSpanFull()
                ->helperText('純文字。前台會以固定安全模板輸出，HTML 標籤不會生效。'),

            ImageField::upload('image_path'),
            ImageField::alt('image_alt'),

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
