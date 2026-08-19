<?php

namespace App\Filament\Resources\FulfillmentMappings\Pages;

use App\Actions\Fulfillment\ApplyProviderBoundsToVariant;
use App\Filament\Resources\FulfillmentMappings\FulfillmentMappingResource;
use App\Models\FulfillmentMapping;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateFulfillmentMapping extends CreateRecord
{
    protected static string $resource = FulfillmentMappingResource::class;

    /** 這一次提交是否套用供應商上下限;⛔ 非持久化,不進 mapping 欄位。 */
    private bool $applyProviderBounds = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->applyProviderBounds = ($data['apply_provider_bounds'] ?? false) === true;
        unset($data['apply_provider_bounds']);

        /*
         * ⛔ form rule 已擋「套用＋啟用」同送;這裡是 page 層的第二道帶,
         * 防 rule 被繞過(直接注入 state)。
         */
        if ($this->applyProviderBounds && ($data['is_enabled'] ?? false) === true) {
            throw ValidationException::withMessages([
                'data.apply_provider_bounds' => '不能在同一次提交中同時套用上下限並啟用對應。',
            ]);
        }

        return $data;
    }

    /**
     * ⛔ mapping 建立與 variant 上下限套用必須同一 transaction:任何一邊
     * 失敗(含 VariantIntegrityObserver 拒絕),兩邊一起 rollback,不留
     * 半套狀態。全程 Eloquent,observer 與 audit 照常執行。
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            /** @var FulfillmentMapping $mapping */
            $mapping = parent::handleRecordCreation($data);

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
