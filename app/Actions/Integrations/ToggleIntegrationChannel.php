<?php

namespace App\Actions\Integrations;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use App\Services\Fulfillment\TheMostPanelCurlCapability;
use App\Services\Integrations\ProviderEndpoints;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only way an Owner switches a channel on or off.
 *
 * Owner 要的操作方式是:填完正式 credential 後,在同一個後台自己開關付款與
 * 發票通道,不必改 `.env`、部署新程式或請人改 code。這個 action 就是那個動作
 * 的唯一入口。
 *
 * 三條規則決定了它的形狀:
 *
 *  - **停用永遠允許,而且立即生效。** 把東西關掉不需要批准、不需要 credential
 *    齊全、不需要任何前置條件。要停止收款的那一刻,通常正是出了什麼事的時候。
 *  - **啟用要求 credential 齊全,而且由後端擋。** 只把按鈕變灰不算完成:
 *    一份手寫的 Livewire payload 從來不經過畫面。
 *  - **每一次切換都寫稽核,而且不含任何 secret 或密文。**
 *
 * ⛔ 這裡不呼叫綠界、LINE Pay 或任何外部服務。切換一個開關不該產生對外流量
 * ——那會讓「我看看能不能開」變成一次真實請求。
 */
class ToggleIntegrationChannel
{
    public function __construct(private readonly RecordCredentialAudit $audit) {}

    /**
     * 切換這個通道。
     *
     * @return bool 切換後的實際狀態
     */
    public function handle(IntegrationProvider $provider, bool $enable): bool
    {
        return DB::transaction(function () use ($provider, $enable) {
            $setting = IntegrationSetting::query()
                ->where('provider', $provider)
                ->where('environment', IntegrationEnvironment::Production)
                ->lockForUpdate()
                ->first();

            /*
             * ⛔ 沒有設定卻要求啟用:拒絕,而且不順手建一列空的。
             *
             * 「幫他建一列」看起來體貼,實際上是把一個沒有 credential 的通道
             * 變成資料庫裡的既存事實,而下一個讀它的人會以為 Owner 設定過了。
             */
            if ($setting === null) {
                if (! $enable) {
                    return false; // 本來就沒有,停用是 no-op。
                }

                throw ValidationException::withMessages([
                    'is_enabled' => "{$provider->label()}尚未輸入任何設定，無法啟用。",
                ]);
            }

            if ($setting->is_enabled === $enable) {
                return $enable; // 沒有變化,不寫稽核。
            }

            /*
             * ⛔ 停用走最短的路:直接寫 false,不經過 model 的 saving observer。
             *
             * observer 的檢查全部是「可不可以開啟」;讓停用也繞一圈,就會出現
             * 「因為 credential 不完整,所以不能關掉」這種荒謬的失敗——而那正是
             * 最需要能關掉的時候。
             */
            if (! $enable) {
                DB::table('integration_settings')
                    ->where('id', $setting->id)
                    ->update(['is_enabled' => false, 'updated_at' => now()]);

                $this->audit->handle($provider, IntegrationEnvironment::Production, ['is_enabled:off']);

                return false;
            }

            /*
             * ⛔ R1:自動派單的技術前提在啟用前檢查——一個開了也永遠派不出
             * 單的開關,只會讓 Owner 以為系統在工作。
             */
            $this->assertDispatchPrerequisites($provider);

            /*
             * 啟用:交給 model,讓 observer 的規則生效(只有正式列、credential
             * 必須齊全)。⛔ 不在這裡複製一份那些檢查——兩份規則會各自漂移,
             * 而漂移的那一份沒有人在測。
             */
            try {
                $setting->is_enabled = true;
                $setting->save();
            } catch (DecryptException) {
                /*
                 * ⛔ 已存的密文無法解讀(損壞、APP_KEY 換過):拒絕啟用,
                 * 用白話告訴 Owner 該怎麼辦。⛔ 不帶出 exception 訊息,
                 * 也不嘗試輸出任何密文或值。
                 */
                throw ValidationException::withMessages([
                    'is_enabled' => '已儲存的金鑰無法讀取（可能已損壞），請重新輸入金鑰後再啟用。',
                ]);
            }

            $this->audit->handle($provider, IntegrationEnvironment::Production, ['is_enabled:on']);

            return true;
        });
    }

    /**
     * TheMostPanel 開啟前的技術條件。
     *
     * ⛔ 訊息只含一般管理者看得懂的條件名稱:不含 raw exception、config 值、
     * credential 或 provider 技術細節。⛔ 這裡不解密任何東西——API Key 的
     * 完整度由 observer 的 credential 檢查負責。
     *
     * ⛔ 這些條件與 `FulfillmentDispatchGate::liveCapable()` 同源:gate 在
     * 每次送出前還會再問一次,所以就算這裡被繞過(直接寫 DB),缺技術條件時
     * 仍然一單都派不出去。
     */
    private function assertDispatchPrerequisites(IntegrationProvider $provider): void
    {
        if ($provider !== IntegrationProvider::TheMostPanel) {
            return;
        }

        if (ProviderEndpoints::theMostPanelDispatch() === null) {
            throw ValidationException::withMessages([
                'is_enabled' => '派單端點設定與版本控制的白名單不符，無法啟用自動派單。',
            ]);
        }

        if (! app(TheMostPanelCurlCapability::class)->supportsOngoingTransferCap()) {
            // ⛔ R1(curl 7.68):唯一的 runtime 硬條件是 PHP cURL 擴充存在;
            // 傳輸上限由 bounded sink 的 short write 執行,不挑 libcurl 版本。
            throw ValidationException::withMessages([
                'is_enabled' => '主機環境不支援（缺少 PHP cURL 擴充），無法啟用自動派單。',
            ]);
        }
    }
}
