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
     * 後台顯示已設定密鑰時使用的固定遮罩。
     *
     * ⛔ 常數定義在這裡而不是只在 Filament 頁面上:擋下它是寫入層的責任。
     * 只在頁面上比對,等於這道防線只保護走過那個頁面的請求。
     */
    public const MASK = '********';

    public function __construct(private readonly RecordCredentialAudit $audit) {}

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

                /*
                 * ⛔ 遮罩字串永遠不會被當成新密鑰寫入。
                 *
                 * 後台顯示已設定的欄位為固定的 `********`。真正的密鑰不會回灌
                 * 到輸入框,所以正常操作根本不會送出這個字串——但一份手寫的
                 * Livewire payload、一個自動填表的瀏覽器擴充,或某天有人「照著
                 * 畫面上看到的」貼回來,都可能送進來。真的存下去,結果是這個
                 * 通道帶著八個星號去簽章,對方回一個看不懂的錯誤,而後台顯示
                 * 「已設定」。
                 *
                 * ⛔ no-op 而不是丟例外:這不是 Owner 的操作錯誤,他很可能只是
                 * 改了另一個欄位就按儲存。當成「沒有變更」處理最接近他的意圖。
                 */
                if ($value === self::MASK) {
                    continue;
                }

                if (($credentials[$key] ?? null) === $value) {
                    continue;
                }

                $credentials[$key] = $value;
                $changed[] = $key;
            }

            if ($changed !== []) {
                $setting->credentials = $credentials;
                $setting->save();

                // ⛔ 稽核與寫入必須同生共死。分開做的話，稽核失敗時金鑰已經改掉，
                // 留下一次「沒有人知道發生過」的憑證變更——正是稽核要防的事。
                $this->audit->handle($provider, $environment, $changed);
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
