<?php

namespace App\Providers;

use App\Contracts\InvoiceGateway;
use App\Models\AdminAuditLog;
use App\Models\Faq;
use App\Models\IntegrationSetting;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Platform;
use App\Models\Service;
use App\Models\ServiceContentSection;
use App\Models\ServiceVariant;
use App\Models\SiteSetting;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Observers\IntegrationSettingObserver;
use App\Observers\LastOwnerObserver;
use App\Observers\PublishObserver;
use App\Observers\VariantIntegrityObserver;
use App\Policies\AdminAuditLogPolicy;
use App\Policies\FaqPolicy;
use App\Policies\IntegrationSettingPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\OrderPolicy;
use App\Policies\PlatformPolicy;
use App\Policies\ServiceContentSectionPolicy;
use App\Policies\ServicePolicy;
use App\Policies\ServiceVariantPolicy;
use App\Policies\UserPolicy;
use App\Services\Invoices\FakeInvoiceGateway;
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
     */
    private function bindInvoiceGateway(): void
    {
        $this->app->singleton(InvoiceGateway::class, function () {
            if ($this->app->environment('local', 'testing')) {
                return new FakeInvoiceGateway;
            }

            if (config('integrations.invoice.gateway') === 'fake') {
                throw new RuntimeException(
                    'Fake 發票 gateway 只能用於 local 與 testing；'
                    .'正式環境必須設定真實 adapter 並經過明確批准。'
                );
            }

            throw new RuntimeException(
                '尚未提供正式的發票 gateway adapter；⛔ 不得以 Fake 代替。'
            );
        });
    }

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

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
