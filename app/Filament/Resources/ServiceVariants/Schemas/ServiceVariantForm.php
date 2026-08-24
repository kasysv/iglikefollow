<?php

namespace App\Filament\Resources\ServiceVariants\Schemas;

use App\Filament\Support\ImageField;
use App\Models\ServiceVariant;
use App\Support\Money;
use App\Support\VariantFulfillmentCard;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
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
                        ->helperText('會顯示在服務頁服務項目卡片下方的「服務項目簡介」框，客人切換服務項目時跟著換。可以換行分點寫，前台會保留換行。說明這款和別款差在哪。⚠️ 不要寫「互動率較高」「保證不掉」這類沒有證據的話。')
                        ->rows(6)
                        ->maxLength(1000)
                        ->columnSpanFull(),

                    Toggle::make('is_featured')
                        ->label('設為預設服務項目')
                        ->helperText('打開後，客人進服務頁時會預先選中這一款。每個服務分類建議只開一個。'),
                ])))->columns(2),

            Section::make('價格與數量')
                ->description('客人可以自己輸入數量，但必須在你設定的範圍內。實際收款金額由系統用「單價 × 數量」自動計算，且一定是整數台幣。')
                ->schema([
                    TextInput::make('unit_price')
                        ->label('單價（計價率）')
                        ->helperText('一個多少錢，可以用小數。例如 0.59 代表一個粉絲 0.59 元；買 1000 個就是 590 元。'
                            .'⚠️ 這是計價用的單價，不是客人實際付的錢。實際收款一定是整數台幣，'
                            .'所以「單價 × 任何客人買得到的數量」都必須剛好是整數元——例如單價 0.59 就要把數量間隔設成 100。')
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->prefix('NT$')
                        ->rules([
                            fn ($get) => function (string $attribute, $value, $fail) use ($get) {
                                self::failIfAnyQuantityIsNotWholeDollars($get, $value, $fail);
                            },
                        ]),

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

                    /*
                     * ⛔ M3A:「數量間隔」輸入已移除。客人可買最少與最多之間的
                     * 任何整數,這個欄位不再有可設定的意義;留著只會讓 Owner 以為
                     * 自己還能限制倍數。legacy DB 欄位保留但不再顯示或編輯。
                     */

                    // 預設數量必須真的買得到，⛔ 否則客人一進頁面就是無效狀態。
                    TextInput::make('default_quantity')
                        ->label('預設數量')
                        ->helperText('客人打開頁面時，數量欄位預先帶的數字。必須在最少與最多之間。')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->rules([
                            fn ($get) => function (string $attribute, $value, $fail) use ($get) {
                                $min = $get('min_quantity');
                                $max = $get('max_quantity');

                                if (filled($min) && (int) $value < (int) $min) {
                                    $fail('預設數量不能小於最少買多少。');

                                    return;
                                }

                                if (filled($max) && (int) $value > (int) $max) {
                                    $fail('預設數量不能大於最多買多少。');
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

            /*
             * 履約對照卡:edit-only、Owner-only、read-only。
             * ⛔ editor 不得看到 provider service ID/名稱/raw rate;create
             * 頁無 record 不顯示。卡片內容全部來自「目前已儲存值」。
             */
            Section::make('履約對照')
                ->description('目前已儲存的履約對應、雙方上下限與價格對照。')
                ->visible(fn (?ServiceVariant $record): bool => $record !== null
                    && (Auth::user()?->isOwner() ?? false))
                ->schema([
                    View::make('filament.service-variants.fulfillment-card')
                        ->viewData(fn (?ServiceVariant $record): array => [
                            'card' => $record === null ? null : VariantFulfillmentCard::for($record),
                        ])
                        ->columnSpanFull(),
                ]),

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

    /**
     * Reject a price/step combination that cannot be charged in whole dollars.
     *
     * The same rule is enforced by VariantIntegrityObserver, which is the
     * actual guarantee — this exists so the admin sees the problem attached to
     * the price field while editing, rather than as a save failure.
     */
    private static function failIfAnyQuantityIsNotWholeDollars($get, $value, \Closure $fail): void
    {
        if (blank($value) || ! is_numeric($value)) {
            return;
        }

        try {
            $rate = Money::toMills((string) $value);
        } catch (\InvalidArgumentException) {
            // 精度或格式問題由 numeric()／欄位本身回報，這裡不重複報錯。
            return;
        }

        // ⛔ 零與負價不可販售；minValue(0) 連 0 都放行，所以這裡自己擋。
        if ($rate <= 0) {
            $fail('單價必須大於 0。免費或負價的服務項目不可販售。');

            return;
        }

        // firstUnpayableQuantity() 讀 raw unit_price，故用 setRawAttributes 塞入表單當下的值。
        // ⛔ M3A:不再需要 step_quantity——它已不影響任何購買規則。
        $probe = new ServiceVariant;
        $probe->setRawAttributes([
            'unit_price' => (string) $value,
            'min_quantity' => (int) ($get('min_quantity') ?: 1),
            'max_quantity' => (int) ($get('max_quantity') ?: 1),
        ], true);

        if ($probe->min_quantity > $probe->max_quantity) {
            return; // 範圍本身有錯，由數量欄位的規則回報。
        }

        if ($probe->firstPurchasableQuantity() === null) {
            $fail("在 {$probe->min_quantity} 到 {$probe->max_quantity} 之間沒有任何可購買的數量。");

            return;
        }

        /*
         * ⛔ M3A:小數台幣已改為 half-up 四捨五入,不再是錯誤。這裡只擋
         * 「四捨五入後不足 1 元」與「溢位」——那兩件事四捨五入救不了。
         */
        $offending = $probe->firstUnpayableQuantity();

        if ($offending !== null) {
            $amount = Money::format($rate * $offending);
            $fail("單價 {$value} × 數量 {$offending} = {$amount} 元，四捨五入後不足 1 元或超出可計算範圍，"
                .'客人無法付款。請調高單價，或提高最少購買數量。');
        }
    }
}
