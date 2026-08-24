<?php

namespace App\Support;

use App\Enums\IntegrationProvider;
use App\Models\FulfillmentMapping;
use App\Models\ProviderService;
use App\Models\ServiceVariant;
use Throwable;

/**
 * Read-only presenter for the variant edit page's fulfillment card.
 *
 * ⛔ Display only. Nothing here writes, and nothing here decides: the card
 * shows the currently SAVED mapping, both sides' saved bounds and prices,
 * and the same compatibility verdict the mapping form enforces — so the
 * Owner sees on one screen what the guards will actually do.
 *
 * ⛔ Bounded queries only: one mapping lookup, one provider row lookup.
 * Never a full catalog scan per render.
 *
 * ⛔ No cost or margin math. The provider raw rate is TWD by the user's
 * confirmation, but its billing basis (per 1 / per 1000 / other) has no
 * contract yet — the card repeats that warning instead of calculating.
 */
final class VariantFulfillmentCard
{
    /** @return array<string, mixed> */
    public static function for(ServiceVariant $variant): array
    {
        /** @var FulfillmentMapping|null $mapping */
        $mapping = $variant->fulfillmentMappings()
            ->where('provider', IntegrationProvider::TheMostPanel->value)
            ->first();

        $provider = null;

        if ($mapping !== null && trim((string) $mapping->provider_service_id) !== '') {
            $provider = ProviderService::query()
                ->where('provider', IntegrationProvider::TheMostPanel->value)
                ->where('provider_service_id', $mapping->provider_service_id)
                ->first();
        }

        $assessment = $provider !== null
            ? QuantityCompatibility::assess($variant, $provider)
            : null;

        [$siteFirst, $siteLast] = self::effectiveBounds($variant, $assessment);

        return [
            'status' => $mapping === null ? 'none' : ($mapping->is_enabled ? 'enabled' : 'disabled'),
            'mapping' => $mapping,
            'provider' => $provider,
            'providerMissing' => $mapping !== null && $provider === null,
            'assessment' => $assessment,
            'siteFirst' => $siteFirst,
            'siteLast' => $siteLast,
            'defaultTotal' => self::defaultTotal($variant),
            // 本站已儲存事實(raw column,不經 cast 竄改顯示)。
            'sku' => (string) $variant->sku,
            'label' => (string) $variant->label,
            'unitPrice' => (string) ($variant->getRawOriginal('unit_price') ?? $variant->unit_price),
            'quantityUnit' => (string) $variant->quantity_unit,
            'min' => (int) $variant->min_quantity,
            'max' => (int) $variant->max_quantity,
            'defaultQuantity' => (int) $variant->default_quantity,
        ];
    }

    /**
     * The variant's effective purchasable range for display.
     *
     * ⛔ One algorithm only. With a provider row present, the bounds come from
     * the very assessment that decides enableability. Without one there is no
     * verdict to disagree with; the fallback mirrors QuantityCompatibility
     * exactly (structure gate first, then firstPurchasableQuantity()).
     * ⛔ M3A: no step alignment anywhere — the last purchasable quantity is the
     * maximum itself.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private static function effectiveBounds(ServiceVariant $variant, ?QuantityCompatibility $assessment): array
    {
        if ($assessment !== null) {
            return [$assessment->siteFirstPurchasable, $assessment->siteLastPurchasable];
        }

        $min = (int) $variant->min_quantity;
        $max = (int) $variant->max_quantity;

        if ($min < 1 || $max < $min) {
            return [null, null];
        }

        $first = $variant->firstPurchasableQuantity();

        if ($first === null) {
            return [null, null];
        }

        return [$first, $max];
    }

    /**
     * 本站 default quantity 的整數台幣試算;⛔ 只用既有 Money 整數算法,
     * 設定有問題就不顯示。⛔ M3A:小數台幣改為 half-up 四捨五入,由 Money 負責。
     */
    private static function defaultTotal(ServiceVariant $variant): ?int
    {
        try {
            $quantity = (int) $variant->default_quantity;

            if ($quantity < 1 || ! $variant->quantityIsValid($quantity)) {
                return null;
            }

            return $variant->amountFor($quantity);
        } catch (Throwable) {
            // 顯示層 fail-safe:不猜、不算、不擋編輯頁。
            return null;
        }
    }
}
