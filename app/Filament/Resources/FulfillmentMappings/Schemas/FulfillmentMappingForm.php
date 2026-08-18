<?php

namespace App\Filament\Resources\FulfillmentMappings\Schemas;

use App\Enums\FulfillmentPayloadType;
use App\Enums\IntegrationProvider;
use App\Models\FulfillmentMapping;
use App\Models\ProviderService;
use App\Models\ServiceVariant;
use App\Rules\AvailableProviderService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                        /*
                         * ⛔ Provider 是固定值,不是選擇:disabled 讓 client
                         * 輸入被忽略,dehydrated 讓 server 端 state(create
                         * 的 default／edit 的既有值)照常寫入。
                         */
                        ->disabled()
                        ->dehydrated()
                        // ⛔ server-side belt:即使 state 被竄改也只接受固定值。
                        ->rule('in:'.IntegrationProvider::TheMostPanel->value)
                        ->required(),

                    Select::make('provider_service_id')
                        ->label('供應商服務代碼')
                        ->helperText('只能從已觀察且可用的供應商服務目錄選擇。填錯會派到別的服務，請務必核對。')
                        /*
                         * 選項只列 available catalog rows;label 帶 ID／名稱
                         * ／分類方便辨識。⛔ provider-controlled text 只當
                         * 純文字 label,Filament escaped 渲染,絕不 ->html()。
                         *
                         * 編輯 stale mapping 時,舊 ID 以「已不在可用目錄」
                         * 附註列出,讓 Owner 看得見歷史值——能否保存由
                         * server-side rule 決定,不由選單決定。
                         */
                        ->options(function (?FulfillmentMapping $record): array {
                            $options = ProviderService::query()
                                ->where('provider', IntegrationProvider::TheMostPanel->value)
                                ->where('is_available', true)
                                ->orderBy('provider_service_id')
                                ->get()
                                ->mapWithKeys(fn (ProviderService $service) => [
                                    $service->provider_service_id => $service->provider_service_id
                                        .'｜'.$service->name
                                        .'｜'.$service->category,
                                ])
                                ->all();

                            $current = $record?->provider_service_id;

                            if ($current !== null && $current !== '' && ! isset($options[$current])) {
                                $options[$current] = $current.'（已不在可用目錄）';
                            }

                            return $options;
                        })
                        ->searchable()
                        ->required()
                        /*
                         * ⛔ Submit-time server-side re-validation:選單只是
                         * 方便,不是邊界。啟用中(is_enabled true)一律要求
                         * available;只有停用時才可保留編輯前的 stale ID。
                         */
                        ->rules(fn (Get $get, ?FulfillmentMapping $record): array => [
                            new AvailableProviderService(
                                $get('is_enabled') ? null : $record?->provider_service_id,
                            ),
                        ]),

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
