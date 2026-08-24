<?php

namespace App\Providers;

use App\Contracts\FulfillmentGateway;
use App\Contracts\InvoiceGateway;
use App\Contracts\TheMostPanelReadOnlyProbe;
use App\Contracts\TheMostPanelServiceCatalogSource;
use App\Models\AdminAuditLog;
use App\Models\Faq;
use App\Models\FulfillmentEvent;
use App\Models\FulfillmentMapping;
use App\Models\FulfillmentOrder;
use App\Models\IntegrationSetting;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Platform;
use App\Models\ProviderService;
use App\Models\Service;
use App\Models\ServiceContentSection;
use App\Models\ServiceVariant;
use App\Models\SiteSetting;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Observers\FulfillmentEventIntegrityObserver;
use App\Observers\FulfillmentMappingAuditObserver;
use App\Observers\FulfillmentOrderIntegrityObserver;
use App\Observers\IntegrationSettingObserver;
use App\Observers\InvoiceIntegrityObserver;
use App\Observers\LastOwnerObserver;
use App\Observers\PublishObserver;
use App\Observers\VariantIntegrityObserver;
use App\Policies\AdminAuditLogPolicy;
use App\Policies\FaqPolicy;
use App\Policies\FulfillmentMappingPolicy;
use App\Policies\FulfillmentOrderPolicy;
use App\Policies\IntegrationSettingPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\OrderPolicy;
use App\Policies\PlatformPolicy;
use App\Policies\ProviderServicePolicy;
use App\Policies\ServiceContentSectionPolicy;
use App\Policies\ServicePolicy;
use App\Policies\ServiceVariantPolicy;
use App\Policies\UserPolicy;
use App\Services\Fulfillment\DisabledFulfillmentGateway;
use App\Services\Fulfillment\FakeFulfillmentGateway;
use App\Services\Fulfillment\TheMostPanelCurlCapability;
use App\Services\Fulfillment\TheMostPanelFulfillmentGateway;
use App\Services\Fulfillment\TheMostPanelReadOnlyHttpProbe;
use App\Services\Fulfillment\TheMostPanelStagingCredentialSource;
use App\Services\Invoices\EcpayInvoiceGateway;
use App\Services\Invoices\FakeInvoiceGateway;
use App\Services\Invoices\InvoiceSandboxGuard;
use App\Support\CatalogRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Platform::class => PlatformPolicy::class,
        Service::class => ServicePolicy::class,
        ServiceVariant::class => ServiceVariantPolicy::class,
        ServiceContentSection::class => ServiceContentSectionPolicy::class,
        Faq::class => FaqPolicy::class,
        User::class => UserPolicy::class,
        AdminAuditLog::class => AdminAuditLogPolicy::class,
        // 訂單唯讀：⛔ policy 一律拒絕 create／update／delete。
        Order::class => OrderPolicy::class,
        // 發票唯讀且僅限 Owner；⛔ 沒有重送或作廢入口。
        Invoice::class => InvoicePolicy::class,
        IntegrationSetting::class => IntegrationSettingPolicy::class,
        // 履約對應僅限 Owner；⛔ 不可刪除，只能停用。
        FulfillmentMapping::class => FulfillmentMappingPolicy::class,
        // 履約紀錄唯讀；⛔ 沒有重送、取消或手動標記完成的入口。
        FulfillmentOrder::class => FulfillmentOrderPolicy::class,
        // 供應商服務目錄僅限 Owner 唯讀；⛔ 後台沒有任何寫入或同步入口。
        ProviderService::class => ProviderServicePolicy::class,
    ];

    /**
     * Models whose admin changes are recorded to the audit log.
     *
     * SiteSetting and User are included because settings edits and role or
     * activation changes are exactly the actions an owner needs to be able to
     * review after the fact.
     */
    private const AUDITED = [
        Platform::class,
        Service::class,
        ServiceVariant::class,
        ServiceContentSection::class,
        Faq::class,
        SiteSetting::class,
        User::class,
    ];

    /**
     * Models that carry publish state.
     *
     * Sections and FAQs have no slug or first_published_at, so only the
     * owner-only status rule applies to them; the observer keys its slug and
     * parent locks off the attributes themselves.
     */
    private const PUBLISHABLE = [
        Platform::class,
        Service::class,
        ServiceVariant::class,
        ServiceContentSection::class,
        Faq::class,
    ];

    public function register(): void
    {
        $this->bindInvoiceGateway();
        $this->bindFulfillmentGateway();

        /*
         * ⛔ 唯讀探針與 FulfillmentGateway 是兩個完全獨立的綁定。
         *
         * 這個 contract 只有 services／balance／status，沒有 submit()。分開綁
         * 定，是為了讓「查詢供應商回應格式」永遠不可能順手變成「可以下單」。
         * 它自己的所有閘門都在 probe 內部，且預設全部關閉。
         */
        $this->app->singleton(TheMostPanelReadOnlyProbe::class, TheMostPanelReadOnlyHttpProbe::class);

        /*
         * ⛔ catalog source 綁到同一個 hardened transport 類別，不是第二套
         * HTTP client。contract 只有無參數的 fetchServices()——沒有任意
         * action、沒有交易 method；它與 FulfillmentGateway 也毫無關係。
         */
        $this->app->singleton(TheMostPanelServiceCatalogSource::class, TheMostPanelReadOnlyHttpProbe::class);
    }

    /**
     * Decide, in one place, what actually places supplier orders.
     *
     * ⛔ Production gets the disabled gateway — bound, not thrown. Fulfilment
     * runs from a queued job after a real payment; throwing here would turn a
     * configuration mistake into a failing job on an order the customer has
     * already paid for. The correct outcome is that nothing is dispatched and
     * the row waits for a person, which is exactly what the disabled gateway
     * produces.
     *
     * ⛔ There is no `themostpanel` branch, so no config value can produce an
     * HTTP client — M4A does not contain one. Real dispatch needs verified
     * service ids, a proven status contract and a reconciliation procedure,
     * none of which exist yet.
     */
    private function bindFulfillmentGateway(): void
    {
        $this->app->singleton(FulfillmentGateway::class, function () {
            if ($this->app->environment('production')) {
                return new DisabledFulfillmentGateway;
            }

            if (
                config('fulfillment.driver') === 'fake'
                && $this->app->environment('local', 'testing')
            ) {
                return new FakeFulfillmentGateway;
            }

            /*
             * ⛔ DISPATCH-ADAPTER-A:themostpanel adapter 只在 testing 可
             * 解析,而且它的 credential source 與 transport 都必須由測試
             * 明確綁定——沒有任何 production implementation 存在,local
             * 也不在此分支內,`.env` 無法把它變成 live driver。
             */
            if (
                config('fulfillment.driver') === 'themostpanel'
                && $this->app->environment('testing')
            ) {
                return $this->app->make(TheMostPanelFulfillmentGateway::class);
            }

            /*
             * ⛔ M4C:staging 是唯一的 production-code 路徑,而且要 driver
             * ＋staging dispatch flag 同時成立;credential source 固定為
             * staging 實作(加密 setting 列),capability 由 runtime 實測。
             * local、production 與未知 environment 永遠落到 Disabled。
             */
            if (
                config('fulfillment.driver') === 'themostpanel'
                && $this->app->environment('staging')
                && (bool) config('fulfillment.staging.themostpanel_dispatch_enabled', false)
                // ⛔ R1(P0-2):global dispatch 總開關也必須成立——container
                // 本身就不交出 live-capable gateway,不只靠 action gate。
                && (bool) config('fulfillment.dispatch_enabled', false)
            ) {
                return new TheMostPanelFulfillmentGateway(
                    new TheMostPanelStagingCredentialSource,
                    TheMostPanelCurlCapability::fromRuntime(),
                );
            }

            // ⛔ 預設就是不派單。
            return new DisabledFulfillmentGateway;
        });
    }

    /**
     * Decide, in one place, what actually issues invoices.
     *
     * ⛔ Production code depends on the InvoiceGateway contract, never on the
     * Fake, so the only way the Fake can run is this binding. Outside local and
     * testing an unconfigured gateway fails closed rather than silently falling
     * back to something that issues nothing — a site that believes it is
     * invoicing while nothing reaches the tax authority is worse than one that
     * refuses to start.
     *
     * ⛔ M4C:不再讀 `INVOICE_GATEWAY`。要不要開發票由 Owner 在後台切換,
     * 而「用哪一個 adapter」是這裡的技術決定,兩者不該由同一個 env 變數混著管。
     */
    private function bindInvoiceGateway(): void
    {
        $this->app->singleton(InvoiceGateway::class, function () {
            /*
             * Owner 已開啟發票通道且設定齊全時,使用真實的綠界 adapter。
             *
             * ⛔ 這一步在環境判斷之前:`InvoiceSandboxGuard::setting()` 本身
             * 已經要求環境可以外呼(staging／production),所以 local／testing
             * 永遠走不到這裡——不需要另一個會與它漂移的環境檢查。
             */
            if (InvoiceSandboxGuard::setting() !== null) {
                return $this->app->make(EcpayInvoiceGateway::class);
            }

            /*
             * ⛔ 只有 local／testing 可以用 Fake。
             *
             * staging／production 在沒有可用設定時必須拋出,不得默默降級成
             * 一個什麼都不開的 Fake:一個以為自己在開發票、實際上什麼都沒送到
             * 財政部的網站,比一個明白拒絕啟動的網站糟得多。
             */
            if ($this->app->environment('local', 'testing')) {
                return new FakeInvoiceGateway;
            }

            throw new RuntimeException(
                '尚未提供可用的發票 gateway；⛔ 不得以 Fake 代替。'
            );
        });
    }

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        /*
         * ⛔ 履約 model 不加進 self::AUDITED。
         *
         * 通用 AuditObserver 會把每個變更欄位原值寫進稽核 JSON，包含
         * provider_service_id——那正是最不該擴散的值。這裡改用只記錄
         * allowlist 欄位、並把服務代碼固定成 [redacted] 的專用 observer。
         */
        FulfillmentMapping::observe(FulfillmentMappingAuditObserver::class);

        // ⛔ 不可逆狀態與 append-only 的 model 層防線；DB 層另有 trigger。
        FulfillmentOrder::observe(FulfillmentOrderIntegrityObserver::class);
        FulfillmentEvent::observe(FulfillmentEventIntegrityObserver::class);

        foreach (self::AUDITED as $model) {
            $model::observe(AuditObserver::class);
        }

        foreach (self::PUBLISHABLE as $model) {
            $model::observe(PublishObserver::class);
        }

        ServiceVariant::observe(VariantIntegrityObserver::class);
        User::observe(LastOwnerObserver::class);
        // ⛔ 啟用限制寫在 model 層：前端 disabled 擋不住偽造的 Livewire payload。
        IntegrationSetting::observe(IntegrationSettingObserver::class);
        // ⛔ 狀態轉移與金額規則同樣在 model 層；資料庫 constraint 是第二層。
        Invoice::observe(InvoiceIntegrityObserver::class);

        // ⛔ ScheduleInvoiceForPaidOrder 不在這裡註冊：Laravel 會依 handle() 的
        // 型別自動探索 app/Listeners，再手動 listen 一次會讓同一張訂單排兩個工作。

        // Header 與 footer 的平台導覽一律從資料庫讀取，⛔ 不再讀 config fixture。
        // 公司名稱同樣由後台設定驅動，⛔ 不寫死在版型裡。
        View::composer('layouts.app', function ($view) {
            $view->with('navPlatforms', app(CatalogRepository::class)->navigablePlatforms());

            if (! array_key_exists('siteName', $view->getData())) {
                $view->with('siteName', SiteSetting::current()?->displayName() ?? 'IGLIKEFOLLOW');
            }
        });
    }
}
