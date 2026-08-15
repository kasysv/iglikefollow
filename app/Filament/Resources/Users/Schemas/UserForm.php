<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('後台帳號')
                ->description('可以登入後台的人。⚠️ 網站訪客不需要帳號，這裡只管理後台使用者。')
                ->schema([
                    TextInput::make('name')
                        ->label('姓名')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label('電子信箱')
                        ->helperText('登入後台時使用的帳號。')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),

                    // ⛔ 密碼只在此輸入，不預設、不顯示、不寫入文件或 log。
                    TextInput::make('password')
                        ->label('密碼')
                        ->password()
                        ->revealable(false)
                        // 本機開發最低長度為 8；⚠️ 正式部署前應調回 12 並考慮加上複雜度規則。
                        ->minLength(8)
                        ->dehydrateStateUsing(fn (?string $state) => filled($state) ? Hash::make($state) : null)
                        ->dehydrated(fn (?string $state) => filled($state))
                        ->required(fn (string $operation) => $operation === 'create')
                        ->helperText('至少 8 個字。編輯時留空就代表不修改密碼。'),

                    Select::make('role')
                        ->label('權限角色')
                        ->helperText('擁有者＝什麼都能做，包含發布內容與管理帳號；編輯＝只能建立和修改草稿，不能發布，也不能改網址代碼。')
                        ->options([
                            User::ROLE_OWNER => '擁有者（完整權限）',
                            User::ROLE_EDITOR => '編輯（只能編草稿）',
                        ])
                        ->default(User::ROLE_EDITOR)
                        ->required(),

                    Toggle::make('is_active')
                        ->label('啟用中')
                        ->helperText('關閉後這個人就無法登入後台，但資料會保留。')
                        ->default(true),
                ])->columns(2),
        ]);
    }
}
