<?php

namespace App\Http\Controllers;

use App\Support\CatalogRepository;
use App\Support\CheckoutSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The two-page checkout: the service page selects a product, /checkout
 * collects fulfilment, contact, invoice and payment details.
 *
 * Everything here is a local mock. No order is created, nothing is charged,
 * no invoice is issued and no external API is called.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly CheckoutSession $checkout,
    ) {}

    /**
     * Accept a product selection from the service page.
     *
     * The variant is re-checked against the published allow-list and the
     * quantity re-validated here, so a crafted POST cannot smuggle a draft
     * product or an out-of-range quantity into the session. Price and amount
     * are never read from the request.
     */
    public function start(Request $request): RedirectResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $purchasable = $this->catalog->purchasableVariants();

        $validated = $request->validate([
            'variant' => ['required', Rule::in($purchasable->keys()->all())],
            'quantity' => ['required', 'integer'],
        ], [
            'variant.required' => '請選擇一個服務項目。',
            'variant.in' => '這個服務項目目前無法購買，請重新選擇。',
            'quantity.required' => '請輸入數量。',
        ]);

        $variant = $purchasable->get((int) $validated['variant']);
        $quantity = (int) $validated['quantity'];

        if (! $variant->quantityIsValid($quantity)) {
            throw ValidationException::withMessages([
                'quantity' => "數量必須介於 {$variant->min_quantity} 至 {$variant->max_quantity} 之間，且為 {$variant->step_quantity} 的倍數。",
            ]);
        }

        $this->checkout->put($request, $variant, $quantity);

        // ⛔ 商品與個資都不放進 query string。
        return redirect()->route('checkout');
    }

    /**
     * The checkout form.
     *
     * A missing, expired or no-longer-valid selection sends the customer back
     * to the service page with an explanation rather than erroring.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $selection = $this->checkout->resolve($request);

        if ($selection === null) {
            return $this->recover($request);
        }

        $variant = $selection['variant'];

        return $this->noindex(view('storefront.checkout', [
            'variant' => $variant,
            'service' => $variant->service,
            'platform' => $variant->service->platform,
            'quantity' => $selection['quantity'],
            'amount' => $selection['amount'],
            'returnUrl' => $selection['return_url'],
        ]));
    }

    /**
     * Where to send a customer whose selection is no longer usable.
     *
     * Falls back to the homepage picker when even the originating service is
     * gone, so this can never dead-end or 500.
     */
    private function recover(Request $request): RedirectResponse
    {
        $stored = $request->session()->get(CheckoutSession::KEY);
        $this->checkout->forget($request);

        $variant = isset($stored['variant_id'])
            ? $this->catalog->findPurchasableVariant($stored['variant_id'])
            : null;

        $target = $variant !== null
            ? route('service', [$variant->service->platform->slug, $variant->service->slug])
            : route('home').'#platforms';

        return redirect()->to($target)
            ->with('checkout_notice', '選購資料已過期或商品已變更，請重新確認服務項目與數量。');
    }

    /**
     * Checkout must never be indexed.
     *
     * The header is set here and the view also emits a noindex meta tag, so
     * neither a crawler following a shared link nor a cached page can surface
     * an order form in search results.
     */
    private function noindex(View $view): Response
    {
        return response($view)->withHeaders([
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
