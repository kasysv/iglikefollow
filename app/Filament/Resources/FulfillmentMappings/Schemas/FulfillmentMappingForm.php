<?php

namespace App\Filament\Resources\FulfillmentMappings\Schemas;

use App\Enums\FulfillmentPayloadType;
use App\Enums\IntegrationProvider;
use App\Models\FulfillmentMapping;
use App\Models\ProviderService;
use App\Models\ServiceVariant;
use App\Rules\AvailableProviderService;
use App\Support\ProviderBoundsTarget;
use App\Support\QuantityCompatibility;
use Closure;
use Filament\Forms\Components\Placeholder;
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
                        // 相容性顯示需要即時反應選擇。
                        ->live()
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
                        ->live()
                        /*
                         * ⛔ Submit-time server-side re-validation:選單只是
                         * 方便,不是邊界。啟用中(is_enabled true)一律要求
                         * available 且數量相容;只有停用時才可保留編輯前的
                         * stale ID(草稿語意,UI 另行標示相容性)。
                         */
                        ->rules(fn (Get $get, ?FulfillmentMapping $record): array => [
                            new AvailableProviderService(
                                $get('is_enabled') ? null : $record?->provider_service_id,
                            ),
                            ...($get('is_enabled')
                                ? [self::quantityCompatibilityGuard($get('service_variant_id'))]
                                : []),
                        ]),

                    /*
                     * Owner 決策資訊:本站實際可購範圍 vs 供應商範圍,同畫面
                     * 對照。⛔ 內容是純文字 string(Placeholder 預設 escaped
                     * 渲染),provider text 不做 HTML;rate 只標原始值警語。
                     */
                    Placeholder::make('quantity_compatibility')
                        ->label('數量相容性')
                        ->columnSpanFull()
                        ->content(function (Get $get): string {
                            return self::compatibilitySummary(
                                $get('service_variant_id'),
                                $get('provider_service_id'),
                            );
                        }),

                    Toggle::make('apply_provider_bounds')
                        ->label('套用 API 上下限為商品預設')
                        /*
                         * ⛔ 非持久化選項:不是 mapping 欄位,只是這一次提交
                         * 的 Owner 決定。create 預設開啟;edit 由 page 的
                         * mutateFormDataBeforeFill 固定為關閉,只有 Owner
                         * 明確勾選才套用。值在 page 端取出後即從 $data 移除。
                         */
                        ->default(true)
                        ->live()
                        ->columnSpanFull()
                        ->helperText('套用「最近一次已存入本機目錄」的供應商 min/max 作為本站商品上下限預設。'
                            .'供應商最低量為 0 時,本站最低購買量會設為 1。數量間隔(step)不會被改動。'
                            .'⛔ 套用時這筆對應必須保持停用;不能在同一次提交同時套用並啟用。')
                        /*
                         * ⛔ 套用與 enable 不得同送:套用是「以 API 範圍當
                         * 預設」的草稿動作,mapping 必須維持 disabled,讓
                         * Owner 分兩步確認。server-side rule,不信任 UI。
                         */
                        ->rules(fn (Get $get): array => [
                            function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                if ($value === true && $get('is_enabled') === true) {
                                    $fail('不能在同一次提交中同時套用上下限並啟用對應;請先套用(保持停用),確認後再另行啟用。');
                                }
                            },
                        ]),

                    /*
                     * Owner 決策預覽:套用會把款式改成什麼。⛔ 純文字
                     * Placeholder(escaped 渲染),只陳述事實;非 Owner 根本
                     * 進不了這個 resource(policy 擋)。
                     */
                    Placeholder::make('provider_bounds_preview')
                        ->label('套用預覽')
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => $get('apply_provider_bounds') === true)
                        ->content(function (Get $get): string {
                            return self::boundsPreview(
                                $get('service_variant_id'),
                                $get('provider_service_id'),
                            );
                        }),

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

    /**
     * ⛔ 啟用的第二道 submit-time guard:重讀 variant 與 provider row,
     * 供應商範圍必須完整承接本站實際可購數量。不信任 Livewire 顯示資料;
     * 失敗訊息固定,不回顯任何提交值或 provider 值。
     */
    private static function quantityCompatibilityGuard(mixed $variantId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($variantId): void {
            if (! is_string($value)) {
                // shape 拒絕由 AvailableProviderService 負責,避免重複訊息。
                return;
            }

            $variant = (is_int($variantId) || (is_string($variantId) && preg_match('/\A[1-9][0-9]*\z/', $variantId) === 1))
                ? ServiceVariant::query()->find($variantId)
                : null;

            if ($variant === null) {
                $fail('啟用前必須先選擇有效的商品款式。');

                return;
            }

            $service = ProviderService::query()
                ->where('provider', IntegrationProvider::TheMostPanel->value)
                ->where('provider_service_id', $value)
                ->where('is_available', true)
                ->first();

            if ($service === null) {
                // 存在性／可用性的失敗訊息由 AvailableProviderService 負責。
                return;
            }

            $assessment = QuantityCompatibility::assess($variant, $service);

            if (! $assessment->compatible) {
                // ⛔ 固定安全標籤:reason code 對應的本地文案,無任何值。
                $fail('無法啟用:'.$assessment->label());
            }
        };
    }

    /** Owner 畫面的純文字對照;⛔ 只陳述事實,不做成本換算或推薦。 */
    private static function compatibilitySummary(mixed $variantId, mixed $serviceId): string
    {
        $variant = (is_int($variantId) || (is_string($variantId) && preg_match('/\A[1-9][0-9]*\z/', (string) $variantId) === 1))
            ? ServiceVariant::query()->with('service.platform')->find($variantId)
            : null;

        /*
         * 顯示層正規化:數字字串 option key 會被 PHP array 轉成 int,
         * Filament 的 state cast 因此讓 `$get` 回傳 int。顯示查詢接受
         * int|string;⛔ validation 層(rule/guard)拿到的是未 cast 的
         * string,shape-first 嚴格性不變。
         */
        if (is_int($serviceId)) {
            $serviceId = (string) $serviceId;
        }

        $service = is_string($serviceId) && $serviceId !== ''
            ? ProviderService::query()
                ->where('provider', IntegrationProvider::TheMostPanel->value)
                ->where('provider_service_id', $serviceId)
                ->first()
            : null;

        if ($variant === null || $service === null) {
            return '請先選擇商品款式與供應商服務,這裡會顯示雙方數量範圍與相容性。';
        }

        /*
         * ⛔ R1:effective bounds 只有一個來源——同一次 assess() 的
         * siteFirstPurchasable／siteLastPurchasable 與 label。這裡不得再用
         * `max(1, step)` 自行算另一套:GPT 反例證明那會把 step 0 的
         * corrupt 款式顯示成「實際可購 100–10000」而 checkout 直接崩潰。
         */
        $assessment = QuantityCompatibility::assess($variant, $service);

        $site = '本站:'.($variant->service?->platform?->name ?? '—')
            .'／'.($variant->service?->name ?? '—')
            .'／'.$variant->label
            .';設定 min '.$variant->min_quantity.'／max '.$variant->max_quantity.'/step '.(int) $variant->step_quantity
            .';實際可購 '.($assessment->siteFirstPurchasable === null
                ? '無(設定不合規)'
                : $assessment->siteFirstPurchasable.'–'.$assessment->siteLastPurchasable).'。';

        $provider = '供應商:'.$service->provider_service_id
            .'｜'.$service->name
            .'｜'.$service->category
            .'｜型別 '.$service->service_type
            .';min '.$service->minimum_quantity_raw.'／max '.$service->maximum_quantity_raw
            .';refill '.($service->supports_refill ? '有' : '無')
            .'／cancel '.($service->supports_cancel ? '有' : '無')
            .';最後觀察 '.($service->last_seen_at ?? '未記錄')
            .';rate '.$service->rate_raw.'(供應商原始值,幣別/計費單位未驗證,不是本站售價)。';

        $availability = $service->is_available ? '' : '⚠ 此代碼已不在可用目錄,僅可停用保留。';

        return $site.PHP_EOL.$provider.PHP_EOL
            .$assessment->label()
            .($availability === '' ? '' : PHP_EOL.$availability);
    }

    /**
     * 套用預覽:本站現值 → 目標值,純文字事實陳述。
     *
     * ⛔ 只是畫面預覽;實際套用時 ApplyProviderBoundsToVariant 會依
     * provider、service ID、available=true 重查再算一次,不信任這裡。
     */
    private static function boundsPreview(mixed $variantId, mixed $serviceId): string
    {
        $variant = (is_int($variantId) || (is_string($variantId) && preg_match('/\A[1-9][0-9]*\z/', (string) $variantId) === 1))
            ? ServiceVariant::query()->find($variantId)
            : null;

        // 顯示層正規化:同 compatibilitySummary,數字字串 key 會被 cast 成 int。
        if (is_int($serviceId)) {
            $serviceId = (string) $serviceId;
        }

        $service = is_string($serviceId) && $serviceId !== ''
            ? ProviderService::query()
                ->where('provider', IntegrationProvider::TheMostPanel->value)
                ->where('provider_service_id', $serviceId)
                ->where('is_available', true)
                ->first()
            : null;

        if ($variant === null || $service === null) {
            return '請先選擇商品款式與「可用」的供應商服務,這裡會預覽套用後的上下限。';
        }

        $target = ProviderBoundsTarget::compute($variant, $service);

        $current = '本站現值:min '.(int) $variant->min_quantity
            .'／max '.(int) $variant->max_quantity
            .'／預設 '.(int) $variant->default_quantity
            .'／間隔 '.(int) $variant->step_quantity.'。';

        $api = 'API 目錄:min '.$service->minimum_quantity_raw
            .'／max '.$service->maximum_quantity_raw
            .'(最後觀察 '.($service->last_seen_at ?? '未記錄').')。';

        if (! $target->ok) {
            return $current.PHP_EOL.$api.PHP_EOL.$target->label();
        }

        $plan = '套用後目標:min '.$target->targetMin
            .'／max '.$target->targetMax
            .'／預設 '.$target->targetDefault
            .($target->defaultAdjusted ? '(原預設不符新範圍或間隔,調整為範圍內第一個合法間隔倍數)' : '(保留原預設)')
            .'。';

        $notes = '⚠ 數量間隔(step)不會被改動。'
            .($target->minZeroLifted ? ' ⚠ 供應商最低量為 0,本站最低購買量設為 1。' : '')
            .' ⚠ 套用時這筆對應保持停用。';

        return $current.PHP_EOL.$api.PHP_EOL.$plan.PHP_EOL.$notes;
    }
}
