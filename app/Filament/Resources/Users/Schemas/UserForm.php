<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->maxLength(255)
                ->unique(ignoreRecord: true),

            // ⛔ 密碼只在此輸入，不預設、不顯示、不寫入文件或 log。
            TextInput::make('password')
                ->password()
                ->revealable(false)
                // 本機開發最低長度為 8；⚠️ 正式部署前應調回 12 並考慮加上複雜度規則。
                ->minLength(8)
                ->dehydrateStateUsing(fn (?string $state) => filled($state) ? Hash::make($state) : null)
                ->dehydrated(fn (?string $state) => filled($state))
                ->required(fn (string $operation) => $operation === 'create')
                ->helperText('至少 8 字元；留空則不變更。'),

            Select::make('role')
                ->options(array_combine(User::ROLES, User::ROLES))
                ->default(User::ROLE_EDITOR)
                ->required(),

            Toggle::make('is_active')->default(true),
        ]);
    }
}
