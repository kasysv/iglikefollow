<?php

namespace App\Filament\Resources\ServiceVariants\Schemas;

use App\Filament\Support\ImageField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ServiceVariantForm
{
    /**
     * @param  bool  $withOwner  false when rendered inside a Service's relation
     *                           manager, where the owning service is fixed by
     *                           the parent record and must not be re-selectable.
     */
    public static function configure(Schema $schema, bool $withOwner = true): Schema
    {
        return $schema->components([
            Section::make('這是什麼服務項目')
                ->description('服務項目就是同一個服務分類底下的不同選擇，例如粉絲分成「一般粉絲」「真人粉絲」「台灣粉絲」，各有各的價格。')
                ->schema(array_values(array_filter([
                    $withOwner ? Select::make('service_id')
                        ->label('所屬服務分類')
                        ->helperText('這個服務項目屬於哪個服務分類。')
                        ->relationship('service', 'name')
                        ->required()
                        ->searchable() : null,

                    TextInput::make('label')
                        ->label('服務項目名稱')
                        ->helperText('客人在服務頁上看到的名稱，例如：真人粉絲。')
                        ->required()
                        ->maxLength(255),

                    // 這段會顯示在服務頁「服務項目簡介」框，改用 Textarea 方便寫完整說明。
                    Textarea::make('description')
                        ->label('服務項目說明')
                        ->helperText('會顯示在服務頁服務項目卡片下方的「服務項目簡介」框，客人切換服務項目時跟著換。說明這款和別款差在哪。⚠️ 不要寫「互動率較高」「保證不掉」這類沒有證據的話。')
                        ->rows(3)
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Toggle::make('is_featured')
                        ->label('設為預設服務項目')
                        ->helperText('打開後，客人進服務頁時會預先選中這一款。每個服務分類建議只開一個。'),
                ])))->columns(2),

            Section::make('價格與數量')
                ->description('客人可以自己輸入數量，但必須在你設定的範圍內。金額由系統用「單價 × 數量」自動計算。')
                ->schema([
                    TextInput::make('unit_price')
                        ->label('單價')
                        ->helperText('一個多少錢，可以用小數。例如 0.59 代表一個粉絲 0.59 元；買 1000 個就是 590 元。')
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->prefix('NT$'),

                    TextInput::make('quantity_unit')
                        ->label('數量單位')
                        ->helperText('計算的單位。粉絲和讚用「個」，留言用「則」，觀看用「次」，自動讚用「篇」。')
                        ->required()
                        ->default('個')
                        ->maxLength(16),

                    // 交叉驗證一律用 closure 讀取表單即時狀態。
                    // ⛔ 不可用 'lte:max_quantity' 這種字串規則：欄位真正的 key 是
                    // data.max_quantity（Relation Manager 更是 mountedActions.0.data.*），
                    // 字串規則找不到對應欄位，會讓完全合法的數量也被判定失敗。
                    TextInput::make('min_quantity')
                        ->label('最少買多少')
                        ->helperText('低於這個數字客人就不能下單。')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->rules([
                            fn ($get) => function (string $attribute, $value, $fail) use ($get) {
                                $max = $get('max_quantity');

                                if (filled($max) && (int) $value > (int) $max) {
                                    $fail('最少買多少不能大於最多買多少。');
                                }
                            },
                        ]),

                    TextInput::make('max_quantity')
                        ->label('最多買多少')
                        ->helperText('超過這個數字客人就不能下單。')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->rules([
                            fn ($get) => function (string $attribute, $value, $fail) use ($get) {
                                $min = $get('min_quantity');

                                if (filled($min) && (int) $value < (int) $min) {
                                    $fail('最多買多少不能小於最少買多少。');
                                }
                            },
                        ]),

                    TextInput::make('step_quantity')
                        ->label('數量間隔')
                        ->helperText('客人只能買這個數字的倍數。填 100 代表只能買 100、200、300…；填 1 代表任何數字都可以。')
                        ->numeric()
                        ->required()
                        ->default(1)
                        ->minValue(1),

                    // 預設數量必須真的買得到，⛔ 否則客人一進頁面就是無效狀態。
                    TextInput::make('default_quantity')
                        ->label('預設數量')
                        ->helperText('客人打開頁面時，數量欄位預先帶的數字。必須在最少與最多之間，而且是「數量間隔」的倍數。')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->rules([
                            fn ($get) => function (string $attribute, $value, $fail) use ($get) {
                                $min = $get('min_quantity');
                                $max = $get('max_quantity');
                                $step = (int) $get('step_quantity');

                                if (filled($min) && (int) $value < (int) $min) {
                                    $fail('預設數量不能小於最少買多少。');

                                    return;
                                }

                                if (filled($max) && (int) $value > (int) $max) {
                                    $fail('預設數量不能大於最多買多少。');

                                    return;
                                }

                                if ($step > 0 && ((int) $value) % $step !== 0) {
                                    $fail("預設數量必須是數量間隔（{$step}）的倍數。");
                                }
                            },
                        ]),

                    TextInput::make('currency')
                        ->label('幣別')
                        ->helperText('目前只用台幣 TWD。')
                        ->default('TWD')
                        ->required()
                        ->maxLength(3),
                ])->columns(2),

            Section::make('編號與圖片')
                ->description('這些是選填的，主要給你自己對帳和之後串接系統用。')
                ->schema([
                    TextInput::make('sku')
                        ->label('商品編號')
                        ->helperText('你自己的商品代號，方便對帳。不能和其他服務項目重複。')
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),

                    TextInput::make('external_sku')
                        ->label('外部系統編號')
                        ->helperText('之後串接你後台 API 時用來對應的代號。⚠️ 現在還沒有連任何外部系統，可以先留空。')
                        ->maxLength(255),

                    ImageField::upload('image_path', '4:3')->label('服務項目圖片'),
                    ImageField::alt('image_alt', 'image_path')->label('圖片說明文字（alt）'),
                ])->columns(2),

            Section::make('發布狀態')
                ->schema([
                    Select::make('status')
                        ->label('狀態')
                        ->helperText('只有「已發布」的服務項目客人才買得到。草稿可以先建好慢慢改。只有擁有者可以改。')
                        ->options([
                            'draft' => '草稿（買不到）',
                            'published' => '已發布（可購買）',
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
