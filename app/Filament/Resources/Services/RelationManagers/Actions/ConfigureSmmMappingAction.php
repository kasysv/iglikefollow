<?php

namespace App\Filament\Resources\Services\RelationManagers\Actions;

use App\Actions\Fulfillment\ApplyProviderBoundsToVariant;
use App\Enums\FulfillmentPayloadType;
use App\Enums\IntegrationProvider;
use App\Models\FulfillmentMapping;
use App\Models\ProviderService;
use App\Models\ServiceVariant;
use App\Rules\AvailableProviderService;
use App\Support\QuantityCompatibility;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 在商品編輯頁的方案列直接設定 SMM 對應。
 *
 * 這是 M2-E-B 的「同頁操作」入口:Owner 不必先去獨立的「商品派單對照」
 * 找同一個款式。⛔ 但它不是一套比較鬆的新規則——建立／更新走的是與舊
 * mapping 頁完全相同的守門:
 *
 * - `AvailableProviderService`:submit 時重查目錄,shape 先行,不信任送來的 ID;
 * - `QuantityCompatibility`:啟用前重算雙方數量範圍;
 * - `ApplyProviderBoundsToVariant`:套用上下限的唯一實作;
 * - 套用與啟用不得同次送出;
 * - mapping 與 variant bounds 同一 transaction,一起成功或一起 rollback。
 *
 * ⛔ 畫面只給普通人看得懂的欄位:服務名稱／最低量／最高量／啟用。
 * ID、provider、category、service type、raw rate、refill、cancel、last seen
 * 與任何成本毛利一律不出現。
 *
 * ⛔ 只有 active Owner 能看到與使用(FulfillmentMappingPolicy 的商業敏感
 * 邊界);Editor 連欄位都看不到。
 */
final class ConfigureSmmMappingAction
{
    /** 選擇器 label 的固定格式;⛔ 純文字,Filament escaped 渲染,絕不 ->html()。 */
    public static function optionLabel(ProviderService $service): string
    {
        return $service->name
            .' ｜ 最低 '.$service->minimum_quantity_raw
            .' ｜ 最高 '.$service->maximum_quantity_raw;
    }

    /** 只有 active Owner 能設定 SMM 對應。 */
    public static function allowed(): bool
    {
        return Auth::user()?->isOwner() ?? false;
    }

    public static function make(): Action
    {
        return Action::make('configureSmmMapping')
            ->label('設定 SMM 服務')
            ->icon('heroicon-o-arrows-right-left')
            ->modalHeading('設定 SMM 服務')
            ->modalDescription('選擇要把這個方案派給哪一個 SMM 服務。啟用只代表對應正確，不會因此開始自動派單。')
            ->modalSubmitActionLabel('儲存')
            // ⛔ 商業敏感:非 Owner 看不到也按不到。
            ->visible(fn (): bool => self::allowed())
            ->schema(fn (ServiceVariant $record): array => self::formSchema($record))
            ->fillForm(fn (ServiceVariant $record): array => self::currentState($record))
            ->action(fn (ServiceVariant $record, array $data) => self::save($record, $data));
    }

    /** @return array<int, mixed> */
    private static function formSchema(ServiceVariant $variant): array
    {
        return [
            Select::make('provider_service_id')
                ->label('SMM 服務')
                ->helperText('只列出目前可用的服務；可用服務名稱搜尋。')
                /*
                 * ⛔ 只列 available=true 的本機目錄列。編輯既有對應時,若舊
                 * 服務已下架,以固定字樣列出讓 Owner 看得見歷史值——能否保存
                 * 由 server-side rule 決定,不由選單決定。
                 */
                ->options(function () use ($variant): array {
                    $options = ProviderService::query()
                        ->where('provider', IntegrationProvider::TheMostPanel->value)
                        ->where('is_available', true)
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (ProviderService $s) => [
                            $s->provider_service_id => self::optionLabel($s),
                        ])
                        ->all();

                    $current = self::existingMapping($variant)?->provider_service_id;

                    if ($current !== null && $current !== '' && ! isset($options[$current])) {
                        $options[$current] = '（此服務已下架，僅可停用保留）';
                    }

                    return $options;
                })
                ->searchable()
                ->required()
                ->live()
                /*
                 * ⛔ Select 的數字字串 option key 會被 PHP array／Livewire
                 * state 轉成 int,但 AvailableProviderService 是 shape-first
                 * (只收 canonical string)。這裡在進 validation 前把 state
                 * 正規化回 string——⛔ 只改型別,不改值、不補零、不 trim,
                 * 規則本身的嚴格性完全不放寬。
                 */
                ->dehydrateStateUsing(fn (mixed $state): mixed => is_int($state) ? (string) $state : $state)
                /*
                 * ⛔ 與舊 mapping 頁相同的 submit-time 守門:選單只是方便,
                 * 不是邊界。啟用時一律要求 available 且數量相容;停用時才可
                 * 保留編輯前的 stale ID。
                 */
                ->rules(fn (Get $get): array => [
                    new AvailableProviderService(
                        $get('is_enabled') ? null : self::existingMapping($variant)?->provider_service_id,
                    ),
                    ...($get('is_enabled') ? [self::compatibilityGuard($variant)] : []),
                ]),

            /*
             * 選中服務的資訊:只有名稱與上下限。⛔ 純文字 Placeholder
             * (escaped 渲染),不含 ID／分類／型別／rate／refill／cancel。
             */
            Placeholder::make('selected_service')
                ->label('服務名稱與可下單範圍')
                ->columnSpanFull()
                ->content(fn (Get $get): string => self::selectedSummary($get('provider_service_id'))),

            Toggle::make('apply_provider_bounds')
                ->label('套用 SMM 上下限到本站商品')
                ->live()
                ->columnSpanFull()
                ->helperText('把這個 SMM 服務的最低／最高量套用成本站方案的可購買範圍。數量間隔不會被改動。'
                    .'⛔ 套用時這筆對應必須保持停用，不能在同一次儲存同時套用並啟用。')
                /*
                 * ⛔ 與舊頁相同:套用與啟用不得同送。套用是「以 API 範圍當
                 * 預設」的草稿動作,mapping 必須維持停用,讓 Owner 分兩步確認。
                 */
                ->rules(fn (Get $get): array => [
                    function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        if ($value === true && $get('is_enabled') === true) {
                            $fail('不能在同一次儲存同時套用上下限並啟用；請先套用（保持停用），確認後再啟用。');
                        }
                    },
                ]),

            Toggle::make('is_enabled')
                ->label('自動派單')
                ->helperText('啟用只表示這筆對應正確，不會因此開始自動派單；自動派單另有總開關，本階段一律關閉。'),
        ];
    }

    /** @return array<string, mixed> */
    private static function currentState(ServiceVariant $variant): array
    {
        $mapping = self::existingMapping($variant);

        return [
            'provider_service_id' => $mapping?->provider_service_id,
            'is_enabled' => (bool) ($mapping?->is_enabled ?? false),
            /*
             * ⛔ 新建預設開、編輯預設關:套用是每次都要 Owner 明確做的決定,
             * 不是編輯畫面的殘留狀態。
             */
            'apply_provider_bounds' => $mapping === null,
        ];
    }

    private static function existingMapping(ServiceVariant $variant): ?FulfillmentMapping
    {
        return $variant->fulfillmentMappings()
            ->where('provider', IntegrationProvider::TheMostPanel->value)
            ->first();
    }

    /**
     * 儲存:與舊 mapping 頁同一組守門與同一個 transaction。
     *
     * ⛔ public 是刻意的:授權必須能被獨立反證。`visible()` 只是畫面條件,
     * 若哪天 UI 條件寫錯,真正的保護必須仍在這裡——測試因此直接呼叫它,
     * 證明非 Owner 即使繞過畫面也存不進去。
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(ServiceVariant $variant, array $data): void
    {
        // ⛔ 授權在 action 層再擋一次,不只靠 visible()。
        if (! self::allowed()) {
            throw ValidationException::withMessages([
                'data.provider_service_id' => '沒有權限設定 SMM 對應。',
            ]);
        }

        $applyBounds = ($data['apply_provider_bounds'] ?? false) === true;
        $enable = ($data['is_enabled'] ?? false) === true;

        // ⛔ 第二道帶:即使 form rule 被繞過(直接注入 state)也擋下。
        if ($applyBounds && $enable) {
            throw ValidationException::withMessages([
                'data.apply_provider_bounds' => '不能在同一次儲存同時套用上下限並啟用對應。',
            ]);
        }

        $serviceId = $data['provider_service_id'] ?? null;

        if (! is_string($serviceId) || $serviceId === '') {
            throw ValidationException::withMessages([
                'data.provider_service_id' => AvailableProviderService::FAILED_MESSAGE,
            ]);
        }

        DB::transaction(function () use ($variant, $serviceId, $enable, $applyBounds): void {
            $mapping = self::existingMapping($variant);

            $attributes = [
                'provider_service_id' => $serviceId,
                'is_enabled' => $enable,
                'payload_type' => FulfillmentPayloadType::LinkQuantity->value,
            ];

            if ($mapping === null) {
                /*
                 * ⛔ 同一 variant + provider 只能有一筆:交給既有 unique
                 * index 與 model guard,不在這裡自行放寬。
                 */
                $variant->fulfillmentMappings()->create($attributes + [
                    'provider' => IntegrationProvider::TheMostPanel->value,
                ]);
            } else {
                $mapping->fill($attributes)->save();
            }

            if ($applyBounds) {
                // ⛔ 套用上下限的唯一實作;它會依 provider/ID/available 重查再算。
                app(ApplyProviderBoundsToVariant::class)->apply(
                    $variant->refresh(),
                    $serviceId,
                    Auth::user(),
                );
            }
        });
    }

    /**
     * 啟用時的數量相容性守門:重讀 variant 與 provider row。
     *
     * ⛔ 不信任畫面資料;失敗訊息固定,不回顯提交值或 provider 值。
     */
    private static function compatibilityGuard(ServiceVariant $variant): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($variant): void {
            if (! is_string($value)) {
                // shape 拒絕由 AvailableProviderService 負責,避免重複訊息。
                return;
            }

            $service = ProviderService::query()
                ->where('provider', IntegrationProvider::TheMostPanel->value)
                ->where('provider_service_id', $value)
                ->where('is_available', true)
                ->first();

            if ($service === null) {
                // 存在性／可用性的訊息由 AvailableProviderService 負責。
                return;
            }

            $assessment = QuantityCompatibility::assess($variant->refresh(), $service);

            if (! $assessment->compatible) {
                // ⛔ 固定安全標籤:reason code 對應的本地文案,無任何提交值。
                $fail('無法啟用：'.$assessment->label());
            }
        };
    }

    /** 選中服務的純文字摘要;⛔ 只有名稱與上下限。 */
    private static function selectedSummary(mixed $serviceId): string
    {
        // 顯示層正規化:數字字串 option key 會被 PHP array 轉成 int。
        if (is_int($serviceId)) {
            $serviceId = (string) $serviceId;
        }

        if (! is_string($serviceId) || $serviceId === '') {
            return '尚未選擇 SMM 服務。';
        }

        $service = ProviderService::query()
            ->where('provider', IntegrationProvider::TheMostPanel->value)
            ->where('provider_service_id', $serviceId)
            ->first();

        if ($service === null) {
            return '這個服務已不在可用目錄中，只能停用保留。';
        }

        return $service->name
            .'｜最低 '.$service->minimum_quantity_raw
            .'｜最高 '.$service->maximum_quantity_raw
            .($service->is_available ? '' : '（已下架，僅可停用保留）');
    }

    /** 方案列表要顯示的一行狀態;⛔ 只有名稱／上下限／啟用狀態。 */
    public static function statusFor(ServiceVariant $variant): string
    {
        $mapping = self::existingMapping($variant);

        if ($mapping === null) {
            return '未設定';
        }

        $service = ProviderService::query()
            ->where('provider', IntegrationProvider::TheMostPanel->value)
            ->where('provider_service_id', $mapping->provider_service_id)
            ->first();

        $state = $mapping->is_enabled ? '已啟用' : '已停用';

        if ($service === null) {
            return '已對應：（服務已下架）｜'.$state;
        }

        return '已對應：'.$service->name
            .'｜最低 '.$service->minimum_quantity_raw
            .'｜最高 '.$service->maximum_quantity_raw
            .'｜'.$state;
    }
}
