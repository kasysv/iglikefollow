<?php

namespace App\Services\Fulfillment;

use App\Contracts\TheMostPanelServiceCatalogSource;
use App\Data\Fulfillment\TheMostPanelCatalogFetchResult;
use App\Enums\IntegrationProvider;
use App\Services\Integrations\LiveIntegration;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Owner-admin authority boundary for the read-only `services` catalog call.
 *
 * This path intentionally does not use the old CLI-only env flags and does not
 * care whether automatic dispatch is enabled. It has its own complete gates:
 * active Owner, outbound-capable environment, exact endpoint, cURL capability,
 * app key, and the encrypted production credential.
 */
class TheMostPanelOwnerCatalogSource implements TheMostPanelServiceCatalogSource
{
    private const ENDPOINT = 'https://themostpanel.com/api/v2';

    public function __construct(
        private readonly ?TheMostPanelCurlCapability $capability = null,
        private readonly ?TheMostPanelServicesFetch $servicesFetch = null,
    ) {}

    public function fetchServices(): TheMostPanelCatalogFetchResult
    {
        if (! (Auth::user()?->isOwner() ?? false)) {
            return TheMostPanelCatalogFetchResult::blocked('blocked_not_owner');
        }

        if (! LiveIntegration::outboundAllowed()) {
            return TheMostPanelCatalogFetchResult::blocked('blocked_environment');
        }

        if ((string) config('integrations.themostpanel_read_only.endpoint') !== self::ENDPOINT) {
            return TheMostPanelCatalogFetchResult::blocked('blocked_endpoint');
        }

        $capability = $this->capability ?? app(TheMostPanelCurlCapability::class);

        if (! $capability->supportsOngoingTransferCap()) {
            return TheMostPanelCatalogFetchResult::blocked('blocked_unsupported_transport_cap');
        }

        $appKey = config('app.key');

        if (! is_string($appKey) || trim($appKey) === '') {
            return TheMostPanelCatalogFetchResult::blocked('blocked_no_app_key');
        }

        try {
            $setting = LiveIntegration::row(IntegrationProvider::TheMostPanel);
        } catch (Throwable) {
            return TheMostPanelCatalogFetchResult::blocked('blocked_credential_unreadable');
        }

        if ($setting === null) {
            return TheMostPanelCatalogFetchResult::blocked('blocked_no_credential');
        }

        try {
            $key = $setting->secret('ApiKey');
        } catch (Throwable) {
            return TheMostPanelCatalogFetchResult::blocked('blocked_credential_unreadable');
        }

        if (! is_string($key) || trim($key) === '') {
            return TheMostPanelCatalogFetchResult::blocked('blocked_no_credential');
        }

        return ($this->servicesFetch ?? app(TheMostPanelServicesFetch::class))->fetch($key);
    }
}
