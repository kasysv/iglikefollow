<?php

namespace App\Filament\Pages;

use App\Filament\Support\ImageField;
use App\Models\Platform;
use App\Models\Service;
use App\Models\SiteSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Singleton settings page (spec §3 site_settings).
 *
 * Exactly one row exists; it is created on first save and never deleted.
 */
class ManageSiteSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = '網站設定';

    protected static ?int $navigationSort = 7;

    protected static ?string $title = '網站設定';

    protected string $view = 'filament.pages.manage-site-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->isOwner() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(SiteSetting::current()?->toArray() ?? []);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('公司名稱')
                    ->description('這個名稱會出現在網站標題列、頁首 Logo 的替代文字與頁尾。')
                    ->schema([
                        TextInput::make('company_name')
                            ->label('公司名稱')
                            // 這個欄位是必填，⛔ 說明不可再寫「留空會自動使用預設值」。
                            ->helperText('必填。例如：IGLIKEFOLLOW。')
                            ->required()
                            ->maxLength(255),
                    ]),

                Section::make('首頁最上方')
                    ->description('客人打開首頁第一眼看到的區塊，由上往下依序是小標籤、大標題、介紹文字。')
                    ->schema([
                        TextInput::make('home_eyebrow')
                            ->label('小標籤')
                            ->helperText('大標題上方的一行小字。例如：Social growth services。')
                            ->maxLength(255),

                        TextInput::make('home_h1')
                            ->label('首頁大標題')
                            ->helperText('首頁最大的那句標題，也是 Google 判斷首頁主題的重點。例如：多平台社群服務，一次選好。')
                            ->required()
                            ->maxLength(255),

                        Textarea::make('home_intro')
                            ->label('首頁介紹文字')
                            ->helperText('大標題下方的說明。這段同時會被當成首頁在 Google 搜尋結果的說明文字，建議開頭就講重點。')
                            ->rows(4)
                            ->columnSpanFull(),

                        ImageField::upload('company_image_path')->label('首頁形象圖片'),
                        ImageField::alt('company_image_alt', 'company_image_path')->label('圖片說明文字（alt）'),
                    ])->columns(2),

                Section::make('首頁三個特色')
                    ->description('大標題下方那條橫列，用來講三個購買理由。⚠️ 標題與說明兩格都要填，只填一格該項不會顯示。三項全部留空則使用預設文字。')
                    ->schema([
                        TextInput::make('home_highlight_1_title')
                            ->label('第 1 項 標題')
                            ->helperText('例如：免會員結帳。')
                            ->maxLength(255),

                        TextInput::make('home_highlight_1_body')
                            ->label('第 1 項 說明')
                            ->helperText('例如：不需註冊即可下單。')
                            ->maxLength(255),

                        TextInput::make('home_highlight_2_title')
                            ->label('第 2 項 標題')
                            ->maxLength(255),

                        TextInput::make('home_highlight_2_body')
                            ->label('第 2 項 說明')
                            ->maxLength(255),

                        TextInput::make('home_highlight_3_title')
                            ->label('第 3 項 標題')
                            ->maxLength(255),

                        TextInput::make('home_highlight_3_body')
                            ->label('第 3 項 說明')
                            ->maxLength(255),
                    ])->columns(2),

                Section::make('首頁主要按鈕')
                    ->description('首頁大標題下方那顆黑色按鈕。⚠️ 只能連到本站頁面，不能填外部網址。')
                    ->schema([
                        TextInput::make('primary_cta_label')
                            ->label('按鈕文字')
                            ->helperText('例如：選擇平台服務。留空會用預設文字。')
                            ->maxLength(255),

                        Select::make('primary_cta_route')
                            ->label('按鈕連到哪裡')
                            ->helperText('選「首頁的選擇平台區塊」最安全，會捲到首頁下方的平台列表。')
                            ->options([
                                'home' => '首頁的「選擇平台」區塊',
                                'platform' => '指定的平台頁',
                                'service' => '指定的服務頁',
                            ])
                            ->default('home')
                            ->live(),

                        // ⛔ 指定固定目標，不使用「排序第一個」，避免排序一改按鈕就跑掉。
                        Select::make('primary_cta_platform_id')
                            ->label('指定平台')
                            ->helperText('只列出已發布的平台。')
                            ->options(fn () => Platform::query()->where('status', 'published')
                                ->orderBy('sort_order')->pluck('name', 'id'))
                            ->searchable()
                            ->visible(fn ($get) => $get('primary_cta_route') === 'platform')
                            ->required(fn ($get) => $get('primary_cta_route') === 'platform')
                            ->validationMessages(['required' => '選擇「指定的平台頁」時，必須挑一個平台。']),

                        Select::make('primary_cta_service_id')
                            ->label('指定服務')
                            ->helperText('只列出「服務與其所屬平台都已發布」的項目。')
                            // 平台未發布時該服務頁不可公開存取，⛔ 不可列出必然回退首頁的選項。
                            ->options(fn () => Service::query()->where('status', 'published')
                                ->whereHas('platform', fn ($q) => $q->where('status', 'published'))
                                ->with('platform')->orderBy('sort_order')->get()
                                ->mapWithKeys(fn (Service $s) => [$s->id => ($s->platform?->name ?? '—').'／'.$s->name]))
                            ->searchable()
                            ->visible(fn ($get) => $get('primary_cta_route') === 'service')
                            ->required(fn ($get) => $get('primary_cta_route') === 'service')
                            ->validationMessages(['required' => '選擇「指定的服務頁」時，必須挑一個服務。']),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label('儲存')->submit('save'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $setting = SiteSetting::current();

        if ($setting) {
            $setting->update($data);
        } else {
            SiteSetting::create($data);
        }

        $this->dispatch('saved');
    }
}
