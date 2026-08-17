<?php

namespace App\Actions\Fulfillment;

use App\Data\Fulfillment\TheMostPanelServiceDefinition;
use App\Enums\IntegrationProvider;
use App\Models\ProviderService;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Apply one complete, already-parsed `services` snapshot to the local catalog.
 *
 * ⛔ CATALOG-A gives this action **no caller**: no Artisan command, no HTTP
 * route, no Filament button, no scheduler. Tests invoke it directly with
 * fictional DTOs and an explicitly injected observation time. Wiring a real
 * sync to it is CATALOG-B work behind its own approval.
 *
 * ⛔ Cross-process single-flight is deliberately deferred to CATALOG-B. The
 * transaction below makes one snapshot atomic; it does NOT serialize two
 * concurrent syncs, and must not be claimed to. Before any real sync entry
 * point exists, a single-flight gate has to be built.
 *
 * Semantics:
 *  - all-or-nothing inside a single transaction; any failure keeps the
 *    previous state, and a parser failure never reaches this class at all;
 *  - present services are upserted and marked available, `first_seen_at` set
 *    only on first sight, `last_seen_at` on every successful snapshot;
 *  - services absent from the snapshot are only marked `is_available = false`
 *    — ⛔ never deleted, so mappings and order history keep their referent;
 *  - ⛔ nothing outside `provider_services` is touched: no mapping, no
 *    fulfillment order, no retail price, no public content.
 */
class ApplyProviderServiceCatalogSnapshot
{
    /**
     * @param  list<TheMostPanelServiceDefinition>  $definitions
     */
    public function __invoke(array $definitions, DateTimeImmutable $observedAt): void
    {
        if ($definitions === []) {
            /*
             * ⛔ 拒收空 snapshot。接受它會把整個 catalog 標成不可用——
             * 而「回應是空的」更可能是壞回應，不是帳戶真的沒有服務。
             */
            throw new InvalidArgumentException('⛔ 不接受空的 catalog snapshot。');
        }

        $ids = [];

        foreach ($definitions as $definition) {
            if (! $definition instanceof TheMostPanelServiceDefinition) {
                throw new InvalidArgumentException('⛔ snapshot 只接受已驗證的服務定義。');
            }

            if (in_array($definition->providerServiceId, $ids, true)) {
                // parser 已拒絕重複；這裡是繞過 parser 直接呼叫時的底線。
                throw new InvalidArgumentException('⛔ snapshot 內有重複的 service ID。');
            }

            $ids[] = $definition->providerServiceId;
        }

        $provider = IntegrationProvider::TheMostPanel->value;

        DB::transaction(function () use ($definitions, $ids, $observedAt, $provider) {
            foreach ($definitions as $definition) {
                $existing = ProviderService::query()
                    ->where('provider', $provider)
                    ->where('provider_service_id', $definition->providerServiceId)
                    ->lockForUpdate()
                    ->first();

                $attributes = [
                    'name' => $definition->name,
                    'service_type' => $definition->serviceType,
                    'category' => $definition->category,
                    'rate_raw' => $definition->rateRaw,
                    'minimum_quantity_raw' => $definition->minimumQuantityRaw,
                    'maximum_quantity_raw' => $definition->maximumQuantityRaw,
                    'supports_refill' => $definition->supportsRefill,
                    'supports_cancel' => $definition->supportsCancel,
                    'is_available' => true,
                    'last_seen_at' => $observedAt,
                ];

                if ($existing === null) {
                    ProviderService::query()->create($attributes + [
                        'provider' => $provider,
                        'provider_service_id' => $definition->providerServiceId,
                        'first_seen_at' => $observedAt,
                    ]);

                    continue;
                }

                // ⛔ first_seen_at 只記第一次：它是觀察史，不是同步狀態。
                $existing->update($attributes);
            }

            /*
             * 這次 snapshot 沒出現的既有服務：只標不可用。
             * ⛔ 不 delete——mapping 與歷史訂單需要它才能解釋自己買過什麼；
             * last_seen_at 也不動，它記的是「最後一次真的看到」。
             */
            ProviderService::query()
                ->where('provider', $provider)
                ->whereNotIn('provider_service_id', $ids)
                ->update(['is_available' => false]);
        });
    }
}
