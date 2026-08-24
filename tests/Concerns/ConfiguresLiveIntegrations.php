<?php

namespace Tests\Concerns;

use App\Enums\IntegrationEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;

/**
 * Put a test into the state Owner's real site is in: a live channel, switched on.
 *
 * M4C 之後,「這個通道可以收款嗎」只有兩個條件:環境允許外呼,且 Owner 已在
 * 後台開啟一個 credential 齊全的 production row。這個 trait 就是把測試放進
 * 那個狀態,⛔ 而不是提供第三條只有測試走得通的路。
 *
 * ⛔ 這裡沒有任何「測試專用旁路」。`runningAsLiveSite()` 真的把 APP_ENV 換成
 * staging,`enableChannel()` 真的寫一個啟用的 production row——受測的就是正式
 * 那條路徑。加一個 `LiveIntegration::fakeEnabled()` 之類的開關會比較好寫,
 * 但那樣測到的是旁路,而 production 走的是另一條沒人測的路。
 *
 * ⛔ 對外請求由 `Http::preventStrayRequests()`／`Http::fake()` 攔住,那是既有
 * 測試已經在用的機制;本 trait 不負責、也不假裝負責這件事。
 */
trait ConfiguresLiveIntegrations
{
    /**
     * 讓這個測試以「可以對外收款的正式站」身分執行。
     *
     * ⛔ 用 `$app->detectEnvironment()` 而不是只改 config:
     * `app()->environment()` 讀的是 container 內解析過的值,只改 config 不會
     * 真的改變它,測試就會在「其實還是 testing」的情況下假裝通過。
     */
    protected function runningAsLiveSite(string $env = 'staging'): void
    {
        $this->app->detectEnvironment(fn () => $env);

        $this->assertSame($env, $this->app->environment(), '環境切換未生效');

        /*
         * 一離開 `testing`,CSRF middleware 就會真的生效,POST 會得到 419。
         *
         * ⛔ 這裡只停掉 CSRF 一個 middleware,不是 `withoutMiddleware()` 全關:
         * 全關會連 environment guard 與 NeverIndex 一起關掉,那樣測到的就不是
         * 這一輪要驗的東西了。CSRF 本身另有既有測試涵蓋。
         */
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    /**
     * 寫入一個 credential 齊全、且 Owner 已開啟的 production row。
     *
     * ⛔ `is_enabled` 用 query builder 直接寫,不經過 model。observer 對
     * `themostpanel` 仍然要求 code 層批准,而這個 trait 的用途是建立「Owner
     * 已經開好」的狀態,不是測試 observer 本身——observer 有它自己的測試。
     *
     * ⛔ credential 值是明顯假造的字串。任何真實或官方公布的 sandbox 金鑰都
     * 不該進 repository:這裡的東西對每一個能讀到這個 repo 的人都是公開的。
     */
    protected function enableChannel(IntegrationProvider $provider, ?string $identifier = null): IntegrationSetting
    {
        $setting = IntegrationSetting::query()
            ->where('provider', $provider)
            ->where('environment', IntegrationEnvironment::Production)
            ->first() ?? new IntegrationSetting;

        $setting->provider = $provider;
        $setting->environment = IntegrationEnvironment::Production;

        if ($provider->identifierLabel() !== null) {
            $setting->identifier = $identifier ?? 'TEST-MERCHANT-ID';
        }

        $credentials = [];

        foreach ($provider->secretKeys() as $key) {
            $credentials[$key] = 'test-value-for-'.$key;
        }

        $setting->credentials = $credentials;
        $setting->save();

        DB::table('integration_settings')
            ->where('id', $setting->id)
            ->update(['is_enabled' => true]);

        return $setting->fresh();
    }

    /**
     * credential 齊全但 Owner 還沒開啟。
     *
     * ⛔ 這個狀態必須與「已開啟」清楚分開:填了金鑰不等於開始收款,而那正是
     * 最容易被寫錯成「有 row 就能用」的地方。
     */
    protected function configureChannelWithoutEnabling(
        IntegrationProvider $provider,
        ?string $identifier = null,
    ): IntegrationSetting {
        $setting = $this->enableChannel($provider, $identifier);

        DB::table('integration_settings')
            ->where('id', $setting->id)
            ->update(['is_enabled' => false]);

        return $setting->fresh();
    }

    /** 一次把付款與發票三個通道都放進「Owner 已開啟」狀態。 */
    protected function enableAllChannels(): void
    {
        $this->enableChannel(IntegrationProvider::EcpayPayment, '3000001');
        $this->enableChannel(IntegrationProvider::LinePay, '1234567890');
        $this->enableChannel(IntegrationProvider::EcpayInvoice, '3000001');
    }
}
