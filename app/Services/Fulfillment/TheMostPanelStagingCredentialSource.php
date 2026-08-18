<?php

namespace App\Services\Fulfillment;

use App\Contracts\TheMostPanelDispatchCredentialSource;
use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use Throwable;

/**
 * The one production-code credential source for staging dispatch.
 *
 * ⛔ Reads exactly the `themostpanel / production` setting row — the same
 * encrypted, write-only storage the Owner fills from the admin — and nothing
 * else. The key is returned only when every condition holds: exactly one
 * row, `is_enabled` true, the secret present, and the ciphertext decryptable.
 * A missing row, duplicate rows, a disabled row, a missing field or a
 * decryption failure all return null — and none of them writes a reason, a
 * value or an exception anywhere. "Why" is a question for the readiness page,
 * which answers it from presence flags without ever decrypting.
 *
 * ⛔ null makes the adapter fail closed BEFORE any network I/O; that contract
 * lives in the gateway and is already regression-tested.
 */
class TheMostPanelStagingCredentialSource implements TheMostPanelDispatchCredentialSource
{
    public function apiKey(): ?string
    {
        try {
            $settings = IntegrationSetting::query()
                ->where('provider', IntegrationProvider::TheMostPanel)
                ->where('environment', IntegrationEnvironment::Production)
                ->get();

            // ⛔ 缺列或多列都是「無法辨識哪一份是真的」:null。
            if ($settings->count() !== 1) {
                return null;
            }

            $setting = $settings->first();

            // ⛔ disabled(未解密前檢查)與缺欄位:null。
            if (! $setting->is_enabled || ! $setting->hasSecret('ApiKey')) {
                return null;
            }

            $key = $setting->secret('ApiKey');

            return is_string($key) && trim($key) !== '' ? $key : null;
        } catch (Throwable) {
            /*
             * ⛔ 解密異常(壞 ciphertext、APP_KEY 不符…)一律 null:
             * 不記 log、不帶原因、不讓任何值或 exception 訊息離開這裡。
             */
            return null;
        }
    }
}
