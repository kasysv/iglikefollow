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
 * The one orchestrator that may turn a fetch into a catalog snapshot.
 *
 * Fixed sequence, nothing optional:
 *
 *   catalog flag → database lock → source (its own gates + one credential
 *   read + at most one HTTP request) → observation time → CATALOG-A snapshot
 *   apply → safe result → lock release.
 *
 * ⛔ The lock comes before the source, therefore before any credential read
 * or HTTP. Two syncs racing to "first" is exactly the case CATALOG-A's
 * monotonic gate cannot order, so the second must never get as far as
 * sending anything.
 *
 * ⛔ The lock is database-backed and single-flight only for processes sharing
 * this database. That is the local guarantee B1 needs; a multi-server
 * deployment must re-verify that every worker points at the same lock
 * connection before this claim extends to it.
 *
 * ⛔ Nothing here logs. A caught exception object drags SQL bindings along —
 * and bindings can hold provider names and rates; a logged provider message
 * is where an echoed API key would live. Every failure leaves this method as
 * a fixed local code, and the previous snapshot stays in place.
 */
class SyncTheMostPanelServiceCatalog
{
    public const LOCK_KEY = 'themostpanel:catalog-sync';

    /** 15 分鐘 TTL：process crash 後自動過期；⛔ 禁止 forceRelease 或手動清 lock。 */
    public const LOCK_SECONDS = 900;

    public function __construct(
        private readonly TheMostPanelServiceCatalogSource $source,
        private readonly ApplyProviderServiceCatalogSnapshot $applySnapshot,
    ) {}

    public function __invoke(): ProviderServiceCatalogSyncResult
    {
        /*
         * ⛔ catalog 專用閘，先於一切——連 lock 都不碰。transport 總閘
         * （read-only flag）由 source 自己把關；兩個開關缺一不可。
         */
        if (! (bool) config('integrations.themostpanel_catalog_sync.enabled', false)) {
            return ProviderServiceCatalogSyncResult::refused('blocked_catalog_sync_disabled');
        }

        try {
            $lock = Cache::store('database')->lock(self::LOCK_KEY, self::LOCK_SECONDS);
            $acquired = $lock->get();
        } catch (Throwable) {
            // ⛔ lock backend 不可用：fail closed，且在 credential 之前停止。
            return ProviderServiceCatalogSyncResult::refused('blocked_lock_unavailable');
        }

        if (! $acquired) {
            // non-blocking：不等待、不送 HTTP、不讀 credential。
            return ProviderServiceCatalogSyncResult::refused('blocked_sync_in_progress');
        }

        try {
            return $this->fetchAndApply();
        } finally {
            try {
                // owner-safe：只釋放自己持有的 lock；過期的交給 TTL。
                $lock->release();
            } catch (Throwable) {
                // lock 已過期或已被回收——TTL 已處理，⛔ 不得 forceRelease。
            }
        }
    }

    private function fetchAndApply(): ProviderServiceCatalogSyncResult
    {
        /*
         * ⛔ source contract 是 never throws——但 orchestrator 不能把 contract
         * 當保證。壞掉或被替換的實作丟出的例外會帶著 class 與 message 一路
         * 上抛到 CLI；這裡收斂成固定碼，lock 照常在 finally 釋放。
         */
        try {
            $fetch = $this->source->fetchServices();
        } catch (Throwable) {
            return ProviderServiceCatalogSyncResult::refused(
                ProviderServiceCatalogSyncResult::SOURCE_FAILED
            );
        }

        if (! $fetch->wasFetched()) {
            // source 的 blocked／failed code 原樣轉出：它們本來就是本地 allowlist。
            return ProviderServiceCatalogSyncResult::refused(
                $fetch->outcome,
                $fetch->httpStatus,
                $fetch->elapsedMs,
            );
        }

        /*
         * observation time 在完整 body 成功收到之後取得；Date facade 讓測試
         * 可控。⛔ 最終仍由 CATALOG-A 正規化成 app-timezone 秒級值。
         */
        $observedAt = Date::now()->toImmutable();

        try {
            ($this->applySnapshot)($fetch->consumeBody(), $observedAt);
        } catch (TheMostPanelCatalogParseException $e) {
            /*
             * ⛔ 帶出 parser 自己的本地 allowlisted reason 與文件欄位名——
             * 且只帶這兩樣。B3 把它丟掉之後,沒有人能說出回應是「不是清單」、
             * 「缺欄位」還是「型別錯誤」;typed factory 是唯一入口,
             * provider 原文沒有路可走。
             */
            return ProviderServiceCatalogSyncResult::rejectedByParser(
                $e,
                $fetch->httpStatus,
                $fetch->elapsedMs,
            );
        } catch (Throwable $e) {
            $stale = $e instanceof RuntimeException
                && $e->getMessage() === ApplyProviderServiceCatalogSnapshot::STALE_SNAPSHOT_MESSAGE;

            // ⛔ 任何 apply 失敗都由 transaction 保住 before state；不外流例外。
            return ProviderServiceCatalogSyncResult::refused(
                $stale ? 'catalog_stale_refused' : 'catalog_apply_failed',
                $fetch->httpStatus,
                $fetch->elapsedMs,
            );
        }

        return ProviderServiceCatalogSyncResult::applied($fetch->httpStatus, $fetch->elapsedMs);
    }
}
