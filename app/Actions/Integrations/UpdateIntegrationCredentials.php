<?php

namespace App\Actions\Integrations;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only way credentials are written.
 *
 * Three rules make this safe to expose to a form:
 *
 *  - unknown keys are refused rather than ignored, so a forged payload cannot
 *    add a field that nothing validates and something later trusts;
 *  - a blank secret means "leave the stored one alone", so re-saving the page
 *    to change a MerchantID does not wipe the HashKey the admin never retyped;
 *  - each secret is replaced independently, so one key can be rotated without
 *    knowing the others.
 *
 * ⛔ Nothing here returns, logs or throws a secret value. Validation messages
 * name the field, never the content.
 */
class UpdateIntegrationCredentials
{
    /**
     * @param  array<string, string|null>  $secrets  keyed by provider secret name
     * @return list<string> the field names that actually changed
     */
    public function handle(
        IntegrationProvider $provider,
        IntegrationEnvironment $environment,
        ?string $identifier,
        array $secrets,
    ): array {
        if (! $provider->supports($environment)) {
            throw ValidationException::withMessages([
                'environment' => "{$provider->label()} 沒有「{$environment->label()}」這個環境。",
            ]);
        }

        $this->assertKeysAreAllowed($provider, $secrets);

        return DB::transaction(function () use ($provider, $environment, $identifier, $secrets) {
            $setting = IntegrationSetting::query()
                ->where('provider', $provider)
                ->where('environment', $environment)
                ->lockForUpdate()
                ->first()
                ?? new IntegrationSetting([
                    'provider' => $provider,
                    'environment' => $environment,
                ]);

            // 新建的 model 還沒有 cast 過的 provider／environment，補上。
            $setting->provider = $provider;
            $setting->environment = $environment;

            $changed = [];

            if ($identifier !== null && $identifier !== ($setting->identifier ?? '')) {
                $setting->identifier = $identifier === '' ? null : $identifier;
                $changed[] = 'identifier';
            }

            $credentials = $setting->credentials ?? [];

            foreach ($secrets as $key => $value) {
                // ⛔ 空白代表「保留原值」，不是「清空」：清除 credential 必須是
                // 明確的動作，不能因為表單沒填就悄悄發生。
                if ($value === null || trim($value) === '') {
                    continue;
                }

                $value = trim($value);

                if (($credentials[$key] ?? null) === $value) {
                    continue;
                }

                $credentials[$key] = $value;
                $changed[] = $key;
            }

            if ($changed !== []) {
                $setting->credentials = $credentials;
                $setting->save();
            }

            return $changed;
        });
    }

    /**
     * @param  array<string, string|null>  $secrets
     */
    private function assertKeysAreAllowed(IntegrationProvider $provider, array $secrets): void
    {
        $allowed = $provider->secretKeys();

        foreach (array_keys($secrets) as $key) {
            if (! in_array($key, $allowed, true)) {
                // ⛔ fail closed：未知欄位一律拒絕，不是忽略。
                throw ValidationException::withMessages([
                    'credentials' => "{$provider->label()} 不接受「{$key}」這個欄位。",
                ]);
            }
        }
    }
}
