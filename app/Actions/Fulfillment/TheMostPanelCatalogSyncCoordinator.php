<?php

namespace App\Actions\Fulfillment;

use App\Contracts\TheMostPanelServiceCatalogSource;
use App\Data\Fulfillment\ProviderServiceCatalogSyncResult;
use App\Exceptions\TheMostPanelCatalogParseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use RuntimeException;
use Throwable;

/**
 * Shared single-flight coordinator for one complete TheMostPanel catalog snapshot.
 *
 * Authorization belongs to the supplied source. This class guarantees that the
 * lock is acquired before a source can read a credential or send HTTP, and that
 * a rejected snapshot leaves the existing catalog unchanged.
 */
class TheMostPanelCatalogSyncCoordinator
{
    public const LOCK_KEY = 'themostpanel:catalog-sync';

    public const LOCK_SECONDS = 900;

    public function __construct(
        private readonly ApplyProviderServiceCatalogSnapshot $applySnapshot,
    ) {}

    public function handle(TheMostPanelServiceCatalogSource $source): ProviderServiceCatalogSyncResult
    {
        try {
            $lock = Cache::store('database')->lock(self::LOCK_KEY, self::LOCK_SECONDS);
            $acquired = $lock->get();
        } catch (Throwable) {
            return ProviderServiceCatalogSyncResult::refused('blocked_lock_unavailable');
        }

        if (! $acquired) {
            return ProviderServiceCatalogSyncResult::refused('blocked_sync_in_progress');
        }

        try {
            return $this->fetchAndApply($source);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // Only release our lock. TTL handles a lost or expired lock.
            }
        }
    }

    private function fetchAndApply(TheMostPanelServiceCatalogSource $source): ProviderServiceCatalogSyncResult
    {
        try {
            $fetch = $source->fetchServices();
        } catch (Throwable) {
            return ProviderServiceCatalogSyncResult::refused(
                ProviderServiceCatalogSyncResult::SOURCE_FAILED,
            );
        }

        if (! $fetch->wasFetched()) {
            return ProviderServiceCatalogSyncResult::refused(
                $fetch->outcome,
                $fetch->httpStatus,
                $fetch->elapsedMs,
            );
        }

        $observedAt = Date::now()->toImmutable();

        try {
            ($this->applySnapshot)($fetch->consumeBody(), $observedAt);
        } catch (TheMostPanelCatalogParseException $exception) {
            return ProviderServiceCatalogSyncResult::rejectedByParser(
                $exception,
                $fetch->httpStatus,
                $fetch->elapsedMs,
            );
        } catch (Throwable $exception) {
            $stale = $exception instanceof RuntimeException
                && $exception->getMessage() === ApplyProviderServiceCatalogSnapshot::STALE_SNAPSHOT_MESSAGE;

            return ProviderServiceCatalogSyncResult::refused(
                $stale ? 'catalog_stale_refused' : 'catalog_apply_failed',
                $fetch->httpStatus,
                $fetch->elapsedMs,
            );
        }

        return ProviderServiceCatalogSyncResult::applied($fetch->httpStatus, $fetch->elapsedMs);
    }
}
