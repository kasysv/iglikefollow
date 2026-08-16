<?php

namespace App\Filament\Resources\Platforms\Schemas;

use App\Filament\Support\ImageField;
use App\Models\Platform;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class PlatformForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('基本資料')
                ->description('平台就是 Instagram、Facebook 這種社群網站。')
                ->schema([
                    TextInput::make('name')
                        ->label('平台名稱')
                        ->helperText('例如：Instagram。會顯示在選單與麵包屑。')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('slug')
                        ->label('網址代碼')
                        ->helperText('網址中的英文代碼。填 instagram 就會變成 /services/instagram。只能用小寫英文、數字與連字號；發布後不能再改。')
                        ->required()
                        ->maxLength(255)
                        ->rule('regex:/^[a-z0-9]+(-[a-z0-9]+)*$/')
                        // 重複的網址代碼要在表單上說清楚，⛔ 不能等資料庫丟出 UNIQUE 例外。
                        ->unique(ignoreRecord: true)
                        ->validationMessages([
                            'unique' => '這個網址代碼已經有其他平台在用了，請換一個。',
                            'regex' => '網址代碼只能用小寫英文、數字與連字號，例如 instagram。',
                        ])
                        ->disabled(fn (?Platform $record) => $record?->isSlugLocked()
                            || ! Auth::user()?->isOwner())
                        ->dehydrated(),

                    TextInput::make('tagline')
                        ->label('一句話介紹')
                        ->helperText('顯示在平台頁標題下方，簡短說明這個平台提供什麼服務。')
                        ->maxLength(255)
                        ->columnSpanFull(),
                ])->columns(2),

            Section::make('平台頁面內容')
                ->description('平台總覽頁最上方的文字。顯示順序：小標籤 → 頁面大標題 → 一句話介紹（在上面「基本資料」設定）→ 詳細介紹。')
                ->schema([
                    TextInput::make('eyebrow')
                        ->label('小標籤')
                        ->helperText('大標題上方的小字，通常放平台名稱。')
                        ->maxLength(255),

                    TextInput::make('h1')
                        ->label('頁面大標題')
                        ->helperText('平台頁最上方的大標題。留空會自動用「平台名稱＋社群成長服務」。')
                        ->maxLength(255),

                    Textarea::make('intro')
                        ->label('詳細介紹')
                        ->helperText('顯示在平台頁最上方，接在「一句話介紹」下面。可以換行分段，前台會保留換行。')
                        ->rows(5)
                        ->columnSpanFull(),

                    ImageField::upload('hero_image_path')->label('主視覺圖片'),
                    ImageField::alt('hero_image_alt', 'hero_image_path')->label('圖片說明文字（alt）'),
                ])->columns(2),

            Section::make('搜尋引擎設定')
                ->description('這些文字會出現在 Google 搜尋結果上。留空會自動產生。')
                ->schema([
                    TextInput::make('seo_title')
                        ->label('搜尋結果標題')
                        ->helperText('Google 上顯示的標題。建議 30 字以內，把服務名稱放最前面。')
                        ->maxLength(255),

                    TextInput::make('meta_description')
                        ->label('搜尋結果說明')
                        ->helperText('Google 標題下方的說明。建議 70 字以內，開頭就要講到重點。')
                        ->maxLength(255),
                ])->columns(2),

            Section::make('發布狀態')
                ->schema([
                    Select::make('status')
                        ->label('狀態')
                        ->helperText('草稿＝只有後台看得到；已發布＝公開顯示；已下架＝從網站移除但資料保留。只有擁有者可以改。')
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
                        ->helperText('數字越小排越前面。0 是第一個。')
                        ->numeric()
                        ->default(0)
                        ->required(),
                ])->columns(2),
        ]);
    }
}
