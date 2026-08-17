<?php

namespace App\Filament\Resources\FulfillmentMappings\Schemas;

use App\Enums\FulfillmentPayloadType;
use App\Enums\IntegrationProvider;
use App\Models\ServiceVariant;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FulfillmentMappingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('商品款式')
                ->description('這筆設定屬於哪一個販售款式。每個款式對同一個供應商只能有一筆設定。')
                ->schema([
                    Select::make('service_variant_id')
                        ->label('商品款式')
                        ->helperText('顯示為「平台／服務／款式」。')
                        ->options(fn () => ServiceVariant::query()
                            ->with('service.platform')
                            ->orderBy('service_id')
                            ->orderBy('sort_order')
                            ->get()
                            ->mapWithKeys(fn (ServiceVariant $v) => [
                                $v->id => ($v->service?->platform?->name ?? '—')
                                    .'／'.($v->service?->name ?? '—')
                                    .'／'.$v->label,
                            ]))
                        ->searchable()
                        ->required()
                        ->validationMessages(['required' => '必須選擇一個商品款式。']),
                ]),

            Section::make('供應商設定')
                ->description('⚠️ 供應商代碼屬於商業敏感資訊，只有擁有者看得到。')
                ->schema([
                    Select::make('provider')
                        ->label('供應商')
                        ->options([IntegrationProvider::TheMostPanel->value => 'TheMostPanel'])
                        ->default(IntegrationProvider::TheMostPanel->value)
                        ->required(),

                    TextInput::make('provider_service_id')
                        ->label('供應商服務代碼')
                        ->helperText('供應商後台的 service ID。填錯會派到別的服務，請務必核對。')
                        ->required()
                        ->maxLength(64),

                    Select::make('payload_type')
                        ->label('資料型別')
                        ->helperText('目前只支援「連結＋數量」。')
                        ->options(collect(FulfillmentPayloadType::cases())
                            ->mapWithKeys(fn (FulfillmentPayloadType $t) => [$t->value => $t->label()]))
                        ->default(FulfillmentPayloadType::LinkQuantity->value)
                        ->required(),

                    Toggle::make('is_enabled')
                        ->label('啟用這筆對應')
                        /*
                         * ⛔ 這裡必須講清楚兩件事的差別。
                         *
                         * 啟用只代表「這個對應是正確的」，不代表系統會開始下單；
                         * 自動派單另有總開關，且本階段一律關閉。把兩者混為一談，
                         * 就會有人以為打開這個就開始花錢了——或者反過來。
                         */
                        ->helperText('啟用只表示這筆對應正確，不會因此開始自動派單；自動派單另有總開關，本階段一律關閉。'),
                ])->columns(2),
        ]);
    }
}
