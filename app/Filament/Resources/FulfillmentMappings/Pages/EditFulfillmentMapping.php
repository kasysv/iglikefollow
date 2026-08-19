<?php

namespace App\Filament\Resources\FulfillmentMappings\Pages;

use App\Actions\Fulfillment\ApplyProviderBoundsToVariant;
use App\Filament\Resources\FulfillmentMappings\FulfillmentMappingResource;
use App\Models\FulfillmentMapping;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditFulfillmentMapping extends EditRecord
{
    protected static string $resource = FulfillmentMappingResource::class;

    /** 這一次提交是否套用供應商上下限;⛔ 非持久化,不進 mapping 欄位。 */
    private bool $applyProviderBounds = false;

    /** ⛔ 不提供刪除：既有履約紀錄需要這筆對應才能解釋自己送去了哪裡。 */
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /*
         * ⛔ 編輯既有 mapping 時「套用 API 上下限」預設一律關閉——套用是
         * 每次都要 Owner 明確做的決定,不是編輯頁的殘留狀態。form 的
         * default(true) 只屬於 create。
         */
        $data['apply_provider_bounds'] = false;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->applyProviderBounds = ($data['apply_provider_bounds'] ?? false) === true;
        unset($data['apply_provider_bounds']);

        // ⛔ page 層第二道帶:套用與啟用不得同送(form rule 之外再擋一次)。
        if ($this->applyProviderBounds && ($data['is_enabled'] ?? false) === true) {
            throw ValidationException::withMessages([
                'data.apply_provider_bounds' => '不能在同一次提交中同時套用上下限並啟用對應。',
            ]);
        }

        return $data;
    }

    /**
     * ⛔ mapping 更新與 variant 上下限套用同一 transaction;任何一邊失敗
     * (含 VariantIntegrityObserver 拒絕)全部 rollback。全程 Eloquent,
     * observer 與 audit 照常執行。
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            /** @var FulfillmentMapping $mapping */
            $mapping = parent::handleRecordUpdate($record, $data);

            if ($this->applyProviderBounds) {
                $variant = $mapping->serviceVariant()->firstOrFail();

                app(ApplyProviderBoundsToVariant::class)->apply(
                    $variant,
                    $mapping->provider_service_id,
                    Auth::user(),
                );
            }

            return $mapping;
        });
    }
}
