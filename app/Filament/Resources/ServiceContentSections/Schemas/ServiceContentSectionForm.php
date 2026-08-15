<?php

namespace App\Filament\Resources\ServiceContentSections\Schemas;

use App\Filament\Support\ImageField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ServiceContentSectionForm
{
    /**
     * @param  bool  $withOwner  false inside a Service's relation manager, where
     *                           the owning service is fixed by the parent record.
     */
    public static function configure(Schema $schema, bool $withOwner = true): Schema
    {
        return $schema->components([
            Section::make('內容段落')
                ->description('這是服務頁下方的長文區塊，用來寫詳細說明、購買須知這類內容。一個服務可以有很多段，照排序由上往下顯示。')
                ->schema(array_values(array_filter([
                    $withOwner ? Select::make('service_id')
                        ->label('所屬服務分類')
                        ->helperText('這段內容要顯示在哪個服務的頁面上。')
                        ->relationship('service', 'name')
                        ->required()
                        ->searchable() : null,

                    TextInput::make('heading')
                        ->label('段落標題')
                        ->helperText('這段的小標題，例如「購買前須知」。前台會用中標題樣式顯示。')
                        ->required()
                        ->maxLength(255),

                    Textarea::make('body')
                        ->label('段落內容')
                        ->helperText('⚠️ 只能打純文字。打 HTML 標籤（例如 <b>）不會變粗體，會原樣顯示出來。換行會保留，可以分段寫。')
                        ->required()
                        ->rows(8)
                        ->columnSpanFull(),

                    ImageField::upload('image_path')->label('段落配圖'),
                    ImageField::alt('image_alt', 'image_path')->label('圖片說明文字（alt）'),
                ])))->columns(2),

            Section::make('發布狀態')
                ->schema([
                    Select::make('status')
                        ->label('狀態')
                        ->helperText('草稿＝只有後台看得到；已發布＝公開顯示。只有擁有者可以改。')
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
                        ->helperText('數字越小排越上面。0 是第一段。')
                        ->numeric()
                        ->default(0)
                        ->required(),
                ])->columns(2),
        ]);
    }
}
