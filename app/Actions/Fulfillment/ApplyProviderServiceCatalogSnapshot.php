<?php

namespace App\Actions\Fulfillment;

use App\Enums\IntegrationProvider;
use App\Models\ProviderService;
use App\Services\Fulfillment\TheMostPanelServiceCatalogParser;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Apply one complete `services` response body to the local catalog.
 *
 * ⛔ The only door is a raw JSON body. The first version accepted a DTO array
 * and trusted `instanceof` as proof of validation — the reviewer built DTOs by
 * hand and wrote a control-character name, an invalid rate and inverted
 * quantity bounds straight past the parser. Nothing can hand this action
 * pre-parsed data any more: it parses the body itself, with the one parser,
 * before any database work begins. A parse failure therefore leaves the
 * database untouched by construction.
 *
 * ⛔ Snapshots must move forward in time. Before anything is written, the
 * provider's rows are locked and the newest successful observation time is
 * read; a snapshot at or before that moment is refused whole. Without this, a
 * delayed or replayed older response would overwrite newer names, rates and
 * capabilities and leave `first_seen_at` after `last_seen_at` — the reviewer
 * demonstrated exactly that. The same rule lives in DB triggers as a second
 * layer.
 *
 * ⛔ CATALOG-A still gives this action **no caller**: no Artisan command, no
 * HTTP route, no Filament button, no scheduler. Tests invoke it directly with
 * fictional raw JSON and an explicitly injected observation time.
 *
 * ⛔ Cross-process single-flight is deliberately deferred to CATALOG-B. The
 * monotonic gate refuses stale *sequential* snapshots; it does NOT serialize
 * two concurrent first syncs, and must not be claimed to.
 *
 * Semantics:
 *  - all-or-nothing inside a single transaction; any failure keeps the
 *    previous state;
 *  - present services are upserted and marked available; `first_seen_at` is
 *    set on first sight (including a pre-existing row whose seen timestamps
 *    are both null) and never rewritten; `last_seen_at` advances on every
 *    successful snapshot;
 *  - services absent from the snapshot are only marked `is_available = false`
 *    — ⛔ never deleted, so mappings and order history keep their referent;
 *  - ⛔ nothing outside `provider_services` is touched: no mapping, no
 *    fulfillment order, no retail price, no public content.
 */
class ApplyProviderServiceCatalogSnapshot
{
    /** ⛔ 固定本地錯誤訊息：不含任何 snapshot 內容或時間值。 */
    public const STALE_SNAPSHOT_MESSAGE =
        '⛔ 拒絕 stale snapshot：觀察時間未晚於既有 catalog 的最後成功觀察。';

    public function __construct(
        private readonly TheMostPanelServiceCatalogParser $parser,
    ) {}

    public function __invoke(string $rawResponseBody, DateTimeImmutable $observedAt): void
    {
        // ⛔ 唯一驗證入口：parse 失敗在任何 DB 動作之前就丟出，資料庫必然不變。
        $definitions = $this->parser->parse($rawResponseBody);

        $provider = IntegrationProvider::TheMostPanel->value;

        DB::transaction(function () use ($definitions, $observedAt, $provider) {
            /*
             * 鎖定本 provider 的既有 rows，再讀最新成功觀察時間。
             * ⛔ 先鎖後讀：不鎖的話兩個 process 會同時讀到同一個「最新」，
             * 但這只擋 stale，不解決並行 first-sync——那是 CATALOG-B 的
             * single-flight 要做的事。
             */
            $latestSeen = ProviderService::query()
                ->where('provider', $provider)
                ->lockForUpdate()
                ->get()
                ->max('last_seen_at');

            if ($latestSeen !== null && $observedAt <= $latestSeen) {
                // ⛔ 等於也拒絕：同一秒的兩份不同 body 無法排序，寧可不收。
                throw new RuntimeException(self::STALE_SNAPSHOT_MESSAGE);
            }

            $ids = [];

            foreach ($definitions as $definition) {
                $ids[] = $definition->providerServiceId;

                $existing = ProviderService::query()
                    ->where('provider', $provider)
                    ->where('provider_service_id', $definition->providerServiceId)
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

                /*
                 * ⛔ first_seen_at 只記第一次，之後不得改寫。既有 row 兩個
                 * seen timestamps 皆 null（從未被真實觀察過）時，第一次合法
                 * snapshot 要一起補上——DB temporal guard 也不允許只有一邊。
                 */
                if ($existing->first_seen_at === null) {
                    $attributes['first_seen_at'] = $observedAt;
                }

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
