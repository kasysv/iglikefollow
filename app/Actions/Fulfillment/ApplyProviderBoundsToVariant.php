<?php

namespace App\Actions\Fulfillment;

use App\Enums\IntegrationProvider;
use App\Models\ProviderService;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Support\ProviderBoundsTarget;
use Illuminate\Validation\ValidationException;

/**
 * Apply the provider's last-observed catalog min/max as this variant's
 * quantity defaults — an explicit Owner decision, never a background sync.
 *
 * ⛔ Nothing here trusts the form: the provider service is re-queried by
 * provider + service ID + `is_available = true` at apply time, so a stale,
 * unavailable or tampered ID fails closed with a fixed message that echoes
 * no submitted value.
 *
 * ⛔ Only `min_quantity`, `max_quantity` and `default_quantity` are written,
 * through Eloquent so VariantIntegrityObserver and the audit observer run.
 * Unit price, currency, SKU, status, step and the mapping's enabled flag are
 * untouched. The caller is responsible for wrapping this together with the
 * mapping save in one transaction; any observer rejection propagates and
 * rolls the whole submit back.
 */
final class ApplyProviderBoundsToVariant
{
    /** @throws ValidationException 任一防線失敗;訊息固定,不回顯提交值。 */
    public function apply(ServiceVariant $variant, mixed $providerServiceId, ?User $actor): ProviderBoundsTarget
    {
        if ($actor === null || ! $actor->isOwner()) {
            // ⛔ policy 已擋整個 resource;這裡是 action 層的第二道帶。
            $this->fail('只有擁有者可以套用供應商上下限。');
        }

        if (! is_string($providerServiceId) && ! is_int($providerServiceId)) {
            $this->fail('無法套用:供應商服務代碼格式不正確。');
        }

        // ⛔ 儲存前依 provider、service ID、available=true 重查,不信任隱藏值。
        $service = ProviderService::query()
            ->where('provider', IntegrationProvider::TheMostPanel->value)
            ->where('provider_service_id', (string) $providerServiceId)
            ->where('is_available', true)
            ->first();

        if ($service === null) {
            $this->fail('無法套用:供應商服務已不在可用目錄,請重新選擇。');
        }

        $target = ProviderBoundsTarget::compute($variant, $service);

        if (! $target->ok) {
            $this->fail($target->label());
        }

        $variant->min_quantity = $target->targetMin;
        $variant->max_quantity = $target->targetMax;
        $variant->default_quantity = $target->targetDefault;
        // ⛔ step、價格、SKU、status 一律不動;observer 失敗就讓例外往上傳。
        $variant->save();

        return $target;
    }

    /** @throws ValidationException */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages([
            'data.apply_provider_bounds' => $message,
        ]);
    }
}
