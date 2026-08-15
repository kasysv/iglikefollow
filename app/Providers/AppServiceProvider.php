<?php

namespace App\Providers;

use App\Models\AdminAuditLog;
use App\Models\Faq;
use App\Models\Platform;
use App\Models\Service;
use App\Models\ServiceContentSection;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Policies\AdminAuditLogPolicy;
use App\Policies\FaqPolicy;
use App\Policies\PlatformPolicy;
use App\Policies\ServiceContentSectionPolicy;
use App\Policies\ServicePolicy;
use App\Policies\ServiceVariantPolicy;
use App\Policies\UserPolicy;
use App\Support\CatalogRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

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
    ];

    /** Content models whose admin changes are recorded to the audit log. */
    private const AUDITED = [
        Platform::class,
        Service::class,
        ServiceVariant::class,
        ServiceContentSection::class,
        Faq::class,
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        foreach (self::AUDITED as $model) {
            $model::observe(AuditObserver::class);
        }

        // Header 與 footer 的平台導覽一律從資料庫讀取，⛔ 不再讀 config fixture。
        View::composer('layouts.app', function ($view) {
            $view->with('navPlatforms', app(CatalogRepository::class)->navigablePlatforms());
        });
    }
}
