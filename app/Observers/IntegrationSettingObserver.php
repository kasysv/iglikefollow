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
 * ⛔ M4C＋R1:付款、發票與自動派單的「可否啟用」都不再是 code 層批准,而是
 * Owner 的後台決定。這裡仍然把守兩件事:只有正式那一列可以被開啟,而且
 * credential 必須齊全。TheMostPanel 的端點/runtime 技術條件另由 toggle
 * action 與 dispatch gate 把關。
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

        /*
         * ⛔ R1:code 層的 `enablable` allowlist 已整組移除。
         *
         * M4C 初版把付款與發票交還 Owner;Owner 隨後明確推翻「自動派單另需
         * code 批准」——TheMostPanel 總開關同樣屬於後台。留著一份能否決
         * Owner 的清單,就是把一個開關變成一次發版。
         *
         * ⛔ 這不代表開啟沒有條件:下面的正式列與 credential 完整度檢查對
         * 每一條寫入路徑生效,而 TheMostPanel 的端點/runtime 技術條件另由
         * `ToggleIntegrationChannel` 在 Owner 動作時把關、由
         * `FulfillmentDispatchGate` 在每次送出前把關——一列被偽造寫入的
         * enabled row,在缺技術條件時仍然一單都派不出去。
         */

        /*
         * ⛔ 只有正式那一列可以被開啟。
         *
         * runtime 只讀 production 列。一列開著的 sandbox 設定在後台看起來像
         * 「已啟用」,實際上永遠不會被讀到——那是一個會讓人以為已經開始收款
         * 的顯示,而顯示與事實不符正是這一輪要消除的東西。
         */
        if (! $setting->environment->isProduction()) {
            throw ValidationException::withMessages([
                'is_enabled' => "{$setting->environment->label()}不是實際營運使用的設定，無法開啟。",
            ]);
        }

        /*
         * 半套的 credential 開啟只會在對方系統得到看不懂的錯誤。
         *
         * ⛔ 明確列出缺少哪些欄位:只說「尚未全部設定」會讓 Owner 逐欄猜。
         * 訊息只含欄位名稱,⛔ 永遠不含值,也不含長度或末幾碼。
         *
         * ⛔ 從正在儲存的 model 本身算,不從資料庫重讀:這一刻的真相在記憶體
         * 裡,重讀會拿到還沒寫入的舊資料而報出錯誤的缺漏欄位。
         */
        if (! $setting->isFullyConfigured()) {
            $missing = self::missingFieldsOf($setting);

            throw ValidationException::withMessages([
                'is_enabled' => $missing === []
                    ? '必填的識別碼或金鑰尚未全部設定，無法啟用。'
                    : '尚未填寫：'.implode('、', $missing).'，無法啟用。',
            ]);
        }
    }

    /**
     * 這一列還缺哪些必填欄位。
     *
     * ⛔ 只回欄位名稱。
     *
     * @return list<string>
     */
    private static function missingFieldsOf(IntegrationSetting $setting): array
    {
        $provider = $setting->provider;
        $missing = [];

        if ($provider->identifierLabel() !== null && blank($setting->identifier)) {
            $missing[] = $provider->identifierLabel();
        }

        foreach ($provider->secretKeys() as $key) {
            if (! $setting->hasSecret($key)) {
                $missing[] = $provider->secretLabel($key);
            }
        }

        return $missing;
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
