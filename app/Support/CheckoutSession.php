<?php

namespace App\Support;

use App\Models\ServiceVariant;
use Illuminate\Http\Request;

/**
 * The product selection carried between the service page and /checkout.
 *
 * Only a variant id, a quantity and the originating service URL are stored.
 * No price, no total and no personal data: prices are re-read from the
 * database on every render and on final submission, so a stale session can
 * never charge an old amount, and there is nothing here worth leaking if the
 * session store is inspected.
 */
class CheckoutSession
{
    public const KEY = 'checkout.selection';

    public function __construct(private readonly CatalogRepository $catalog) {}

    public function put(Request $request, ServiceVariant $variant, int $quantity): void
    {
        $service = $variant->service;

        $request->session()->put(self::KEY, [
            'variant_id' => $variant->id,
            'quantity' => $quantity,
            // 回到商品頁用；⛔ 存 URL 而非個資。
            'return_url' => route('service', [$service->platform->slug, $service->slug]),
        ]);
    }

    /**
     * The current selection, re-validated against the live catalogue.
     *
     * Returns null when the session is missing or expired, when the variant
     * has since been unpublished, or when the stored quantity no longer fits
     * the variant's bounds — an admin may have changed them after the
     * customer left the service page. Callers redirect instead of failing.
     *
     * @return array{variant: ServiceVariant, quantity: int, amount: int, return_url: string}|null
     */
    public function resolve(Request $request): ?array
    {
        $stored = $request->session()->get(self::KEY);

        if (! is_array($stored) || ! isset($stored['variant_id'], $stored['quantity'])) {
            return null;
        }

        $variant = $this->catalog->findPurchasableVariant($stored['variant_id']);

        if ($variant === null) {
            return null;
        }

        $quantity = (int) $stored['quantity'];

        if (! $variant->quantityIsValid($quantity)) {
            return null;
        }

        return [
            'variant' => $variant,
            'quantity' => $quantity,
            // 金額每次都重算，⛔ 不從 session 讀取先前的價格。
            'amount' => $variant->amountFor($quantity),
            'return_url' => $this->returnUrl($stored, $variant),
        ];
    }

    public function forget(Request $request): void
    {
        $request->session()->forget(self::KEY);
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    private function returnUrl(array $stored, ServiceVariant $variant): string
    {
        $stored = $stored['return_url'] ?? null;

        // ⛔ 只接受本站 route 產生的 URL，不讓 session 值變成開放轉址。
        $expected = route('service', [
            $variant->service->platform->slug,
            $variant->service->slug,
        ]);

        return is_string($stored) && $stored === $expected ? $stored : $expected;
    }
}
