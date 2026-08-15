<?php

namespace App\Filament\Resources\ServiceVariants\Schemas;

use App\Filament\Support\ImageField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ServiceVariantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('service_id')
                ->relationship('service', 'name')
                ->required()
                ->searchable(),

            TextInput::make('label')->required()->maxLength(255),
            TextInput::make('sku')->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('description')->maxLength(255)
                ->helperText('⛔ 不得寫互動率、速度、保固等無第一方證據的宣稱。'),

            ImageField::upload('image_path', '4:3'),
            ImageField::alt('image_alt'),

            TextInput::make('quantity_unit')->required()->default('個')->maxLength(16),
            TextInput::make('min_quantity')->numeric()->required()->minValue(1),
            TextInput::make('max_quantity')->numeric()->required()->minValue(1),
            TextInput::make('step_quantity')->numeric()->required()->default(1)->minValue(1),
            TextInput::make('default_quantity')->numeric()->required()->minValue(1),

            TextInput::make('unit_price')->numeric()->required()->minValue(0)
                ->helperText('本機 mock 單價；正式售價待後台／API 提供。'),
            TextInput::make('currency')->default('TWD')->maxLength(3)->required(),
            TextInput::make('external_sku')->maxLength(255)
                ->helperText('為未來履約 API 預留；本輪不呼叫外部 API。'),

            Toggle::make('is_featured'),

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
