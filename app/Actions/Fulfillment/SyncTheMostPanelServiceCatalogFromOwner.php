<?php

namespace App\Actions\Fulfillment;

use App\Data\Fulfillment\ProviderServiceCatalogSyncResult;
use App\Enums\IntegrationProvider;
use App\Models\AdminAuditLog;
use App\Models\IntegrationSetting;
use App\Models\ProviderService;
use App\Services\Fulfillment\TheMostPanelOwnerCatalogSource;
use App\Services\Integrations\LiveIntegration;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Throwable;

/** Owner-only audited entry point used by the Filament sync button. */
class SyncTheMostPanelServiceCatalogFromOwner
{
    public const AUDIT_ACTION = 'themostpanel_catalog_sync';

    public function __construct(
        private readonly TheMostPanelCatalogSyncCoordinator $coordinator,
        private readonly TheMostPanelOwnerCatalogSource $source,
    ) {}

    public function handle(): ProviderServiceCatalogSyncResult
    {
        $user = Auth::user();
        abort_unless($user?->isOwner() ?? false, 403);

        $startedAt = Date::now();

        try {
            $settingId = LiveIntegration::row(IntegrationProvider::TheMostPanel)?->getKey() ?? 0;
            $audit = AdminAuditLog::query()->create([
                'user_id' => $user->getKey(),
                'auditable_type' => IntegrationSetting::class,
                'auditable_id' => $settingId,
                'action' => self::AUDIT_ACTION,
                'before' => null,
                'after' => [
                    'state' => 'started',
                    'provider' => IntegrationProvider::TheMostPanel->value,
                    'started_at' => $startedAt->toIso8601String(),
                ],
                'ip_address' => request()->ip(),
            ]);
        } catch (Throwable) {
            return ProviderServiceCatalogSyncResult::refused('blocked_audit_unavailable');
        }

        $result = $this->coordinator->handle($this->source);
        $completedAt = Date::now();

        $serviceCount = $result->applied
            ? ProviderService::query()
                ->where('provider', IntegrationProvider::TheMostPanel)
                ->count()
            : null;

        try {
            $audit->update([
                'after' => array_filter([
                    'state' => $result->applied ? 'completed' : 'refused',
                    'provider' => IntegrationProvider::TheMostPanel->value,
                    'outcome' => $result->outcome,
                    'applied' => $result->applied,
                    'service_count' => $serviceCount,
                    'started_at' => $startedAt->toIso8601String(),
                    'completed_at' => $completedAt->toIso8601String(),
                ], fn ($value) => $value !== null),
            ]);
        } catch (Throwable) {
            // The pre-request `started` row remains. Never log the exception.
        }

        return $result;
    }
}
