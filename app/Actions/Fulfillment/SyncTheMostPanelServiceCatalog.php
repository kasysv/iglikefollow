<?php

namespace App\Actions\Fulfillment;

use App\Contracts\TheMostPanelServiceCatalogSource;
use App\Data\Fulfillment\ProviderServiceCatalogSyncResult;

/**
 * Existing CLI catalog-sync entry point.
 *
 * Its env/config gate and its source's CLI-only gates remain unchanged. The
 * shared coordinator only removes duplicate lock/parser/snapshot mechanics.
 */
class SyncTheMostPanelServiceCatalog
{
    public const LOCK_KEY = TheMostPanelCatalogSyncCoordinator::LOCK_KEY;

    public const LOCK_SECONDS = TheMostPanelCatalogSyncCoordinator::LOCK_SECONDS;

    public function __construct(
        private readonly TheMostPanelServiceCatalogSource $source,
        private readonly TheMostPanelCatalogSyncCoordinator $coordinator,
    ) {}

    public function __invoke(): ProviderServiceCatalogSyncResult
    {
        if (! (bool) config('integrations.themostpanel_catalog_sync.enabled', false)) {
            return ProviderServiceCatalogSyncResult::refused('blocked_catalog_sync_disabled');
        }

        return $this->coordinator->handle($this->source);
    }
}
