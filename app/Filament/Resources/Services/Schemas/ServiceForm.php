<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Filament\Support\ImageField;
use App\Models\Service;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('基本資料')
                ->description('服務就是「賣什麼」，例如 Instagram 粉絲、貼文讚。實際價格與數量請到「服務項目」設定。')
                ->schema([
                    Select::make('platform_id')
                        ->label('所屬平台')
                        ->helperText('這個服務屬於哪個社群平台。')
                        ->relationship('platform', 'name')
                        ->required()
                        ->searchable(),

                    TextInput::make('name')
                        ->label('服務分類名稱')
                        ->helperText('例如：Instagram 粉絲。這是後台與前台的正式名稱。')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('slug')
                        ->label('網址代碼')
                        ->helperText('網址中的英文代碼。填 followers 就會變成 /services/instagram/followers。同一平台內不能重複；發布後不能再改。')
                        ->required()
                        ->maxLength(255)
                        ->rule('regex:/^[a-z0-9]+(-[a-z0-9]+)*$/')
                        // 唯一範圍是「同一平台內」，與資料庫的 (platform_id, slug) 一致；
                        // ⛔ 不可只驗 slug，否則 IG 與 FB 就不能各有一個 followers。
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule, $get) => $rule->where('platform_id', $get('platform_id')),
                        )
                        ->validationMessages([
                            'unique' => '同一個平台底下已經有這個網址代碼了，請換一個。',
                            'regex' => '網址代碼只能用小寫英文、數字與連字號，例如 followers。',
                        ])
                        ->disabled(fn (?Service $record) => $record?->isSlugLocked()
                            || ! Auth::user()?->isOwner())
                        ->dehydrated(),

                    TextInput::make('summary')
                        ->label('一句話說明')
                        ->helperText('顯示在服務頁標題下方，說明這個服務在做什麼。')
                        ->maxLength(255)
                        ->columnSpanFull(),
                ])->columns(2),

            Section::make('客人要填什麼')
                ->description('決定客人下單時要提供哪種資料。設錯客人會不知道該給帳號還是網址。')
                ->schema([
                    Select::make('input_kind')
                        ->label('需要的資料類型')
                        ->helperText('帳號＝整個帳號經營（例如買粉絲）；貼文網址＝指定單篇貼文；影片網址＝指定影片；粉專網址＝Facebook 專頁或社團。')
                        ->options([
                            'account' => '帳號（例如 username）',
                            'post_url' => '貼文網址',
                            'video_url' => '影片網址',
                            'page_url' => '粉專／社團網址',
                        ])
                        ->default('account')
                        ->required(),

                    TextInput::make('input_label')
                        ->label('輸入欄位標題')
                        ->helperText('結帳表單上顯示的欄位名稱，例如「Instagram 帳號」或「Instagram 貼文網址」。')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('input_hint')
                        ->label('輸入範例')
                        ->helperText('欄位裡的灰色提示文字，給客人參考格式。例如：instagram.com/p/xxxxxxxxx')
                        ->maxLength(255),

                    TextInput::make('delivery_summary')
                        ->label('交付方式說明')
                        ->helperText('一句話說明怎麼交付。例如：貼一條貼文連結，送一次，一次完畢。')
                        ->maxLength(255),
                ])->columns(2),

            Section::make('在平台頁上怎麼顯示')
                ->description('這些是 Instagram／Facebook 總覽頁上，這張服務卡片的內容。')
                ->schema([
                    TextInput::make('card_title')
                        ->label('卡片標題')
                        ->helperText('留空就用服務名稱。')
                        ->maxLength(255),

                    TextInput::make('card_blurb')
                        ->label('卡片一句話')
                        ->helperText('卡片上的簡短說明，比「一句話說明」更短。例如：建立帳號整體規模。')
                        ->maxLength(255),

                    TextInput::make('goal')
                        ->label('適合的目標')
                        ->helperText('用客人聽得懂的話分類，會出現在「你想達成什麼目標？」區塊。例如：帳號規模、單篇互動、自動經營、影片曝光。')
                        ->maxLength(255),

                    Toggle::make('is_featured')
                        ->label('設為主打服務')
                        ->helperText('打開後這個服務會變成平台頁最上方的大卡片。每個平台建議只開一個。'),

                    TextInput::make('h1')
                        ->label('服務頁大標題')
                        ->helperText('服務頁最上方的大標題。留空會用服務名稱。')
                        ->maxLength(255),

                    Textarea::make('intro')
                        ->label('詳細介紹')
                        ->helperText('較長的服務說明。')
                        ->rows(4)
                        ->columnSpanFull(),

                    ImageField::upload('card_image_path', '4:3')->label('卡片圖片'),
                    ImageField::alt('card_image_alt', 'card_image_path')->label('卡片圖片說明文字（alt）'),
                    ImageField::upload('hero_image_path')->label('服務頁主視覺'),
                    ImageField::alt('hero_image_alt', 'hero_image_path')->label('主視覺說明文字（alt）'),
                ])->columns(2),

            Section::make('搜尋引擎設定')
                ->description('這些文字會出現在 Google 搜尋結果上。留空會自動產生。')
                ->schema([
                    TextInput::make('seo_title')
                        ->label('搜尋結果標題')
                        ->helperText('Google 上顯示的標題。建議 30 字以內，服務名稱放最前面。')
                        ->maxLength(255),

                    TextInput::make('meta_description')
                        ->label('搜尋結果說明')
                        ->helperText('Google 標題下方的說明。建議 70 字以內，開頭就要講到重點，不要放公司口號。')
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
