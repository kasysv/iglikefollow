<?php

namespace App\Observers;

use App\Models\IntegrationSetting;
use Illuminate\Validation\ValidationException;

/**
 * Server-side enforcement of what may be switched on.
 *
 * A disabled button in the admin is a hint, not a control: a hand-written
 * Livewire payload or a plain `$setting->update(['is_enabled' => true])` never
 * touches the UI. Enabling a provider means this site will start signing real
 * requests with real credentials, so the rule lives on the model where every
 * write path goes through it.
 *
 * The allowlist is in config/integrations.php — in version control, where
 * turning something on is a reviewed change rather than a click.
 */
class IntegrationSettingObserver
{
    public function saving(IntegrationSetting $setting): void
    {
        $this->assertEnvironmentIsSupported($setting);
        $this->assertEnablingIsAllowed($setting);
        $this->assertNoRawResponseLeaked($setting);
    }

    private function assertEnvironmentIsSupported(IntegrationSetting $setting): void
    {
        if ($setting->provider->supports($setting->environment)) {
            return;
        }

        throw ValidationException::withMessages([
            'environment' => "{$setting->provider->label()} 沒有「{$setting->environment->label()}」這個環境。",
        ]);
    }

    private function assertEnablingIsAllowed(IntegrationSetting $setting): void
    {
        if (! $setting->is_enabled) {
            return; // 關閉永遠允許：把東西停掉不需要批准。
        }

        $allowed = (bool) config(
            "integrations.enablable.{$setting->provider->value}.{$setting->environment->value}",
            false
        );

        if (! $allowed) {
            throw ValidationException::withMessages([
                'is_enabled' => "{$setting->provider->label()}（{$setting->environment->label()}）"
                    .'尚未獲得啟用批准，無法開啟。',
            ]);
        }

        // 半套的 credential 開啟只會在對方系統得到看不懂的錯誤。
        if (! $setting->isFullyConfigured()) {
            throw ValidationException::withMessages([
                'is_enabled' => '必填的識別碼或金鑰尚未全部設定，無法啟用。',
            ]);
        }
    }

    /**
     * ⛔ 連線測試訊息只能是整理過的短句。
     *
     * 對方的 raw response 常含請求內容回音，把它當成「訊息」存下來，
     * 等於把 credential 與個資寫進一個沒人當作機密看待的欄位。
     */
    private function assertNoRawResponseLeaked(IntegrationSetting $setting): void
    {
        $message = $setting->last_test_message;

        if ($message === null) {
            return;
        }

        if (strlen($message) > 255 || preg_match('/[{<]/', $message) === 1) {
            throw ValidationException::withMessages([
                'last_test_message' => '連線測試訊息必須是整理過的短句，不得存入原始回應。',
            ]);
        }
    }
}
