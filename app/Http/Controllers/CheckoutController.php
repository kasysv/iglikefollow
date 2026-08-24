<?php

namespace App\Http\Controllers;

use App\Services\Payments\PaymentGatewayRegistry;
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
 * Nothing here creates an order, charges anything, issues an invoice or calls
 * an external API — this controller only carries a *selection* as far as the
 * form. Paying is a separate surface with its own guards.
 */
class CheckoutController extends Controller
{
    /**
     * Where the selection surfaces may run.
     *
     * ⛔ M4C:`production` 已加入。Owner 的網站是要真的營運的,而選商品這一步
     * 在正式站 404 就等於沒有網站。這個清單管的是「選購介面在哪裡可以出現」,
     * 不是「錢可不可以移動」。
     *
     * ⛔ 錢可不可以移動由完全不同的地方決定:`LiveIntegration`(環境可外呼＋
     * Owner 已開啟＋credential 齊全)。所以放寬這個清單不會、也不可能讓一筆
     * 交易在 Owner 沒有開關的情況下成立。
     *
     * ⛔ 這個清單保留下來而不是直接刪掉,是因為它仍然有作用:任何未列出的
     * 環境(例如某個一次性的 CLI env)仍然 404,而不是靜默地提供一個結帳頁。
     */
    private const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'staging', 'production'];

    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly CheckoutSession $checkout,
        private readonly PaymentGatewayRegistry $payments,
    ) {}

    /**
     * One place that answers "may the selection flow run here".
     *
     * ⛔ Kept as a single method rather than three copies of the same
     * `abort_unless`: a guard repeated three times is a guard with three
     * chances to drift apart, and this one is the boundary that keeps
     * production closed.
     */
    private function assertSelectionSurfaceAvailable(): void
    {
        abort_unless(app()->environment(self::ALLOWED_ENVIRONMENTS), 404);
    }

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
        $this->assertSelectionSurfaceAvailable();

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
                // ⛔ M3A:只說整數與上下限,不再提倍數。
                'quantity' => "數量必須是介於 {$variant->min_quantity} 至 {$variant->max_quantity} 之間的整數。",
            ]);
        }

        $this->checkout->put($request, $variant, $quantity);

        // ⛔ 商品與個資都不放進 query string。
        return $this->noindexRedirect(redirect()->route('checkout'));
    }

    /**
     * The checkout form.
     *
     * A missing, expired or no-longer-valid selection sends the customer back
     * to the service page with an explanation rather than erroring.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $this->assertSelectionSurfaceAvailable();

        $selection = $this->checkout->resolve($request);

        if ($selection === null) {
            return $this->noindexRedirect($this->recover($request));
        }

        $variant = $selection['variant'];

        /*
         * 可用的付款方式由 registry 決定,⛔ 不在 view 裡自己算一次。
         *
         * 兩邊各算一次的結果是:畫面上顯示得出來、送出後被後端拒絕——而客人
         * 已經填完整張表單、按下「前往付款」了。`payments.start` 讀的是同一個
         * 方法,所以前後端不可能不一致。
         */
        return $this->noindex(view('storefront.checkout', [
            'variant' => $variant,
            'service' => $variant->service,
            'platform' => $variant->service->platform,
            'quantity' => $selection['quantity'],
            'amount' => $selection['amount'],
            'availablePayments' => $this->payments->availableProviders(),
            // ⛔ mock 只存在於 local／testing,它會直接把訂單標成付款成功。
            'mockAvailable' => app()->environment(['local', 'testing']),
        ]));
    }

    /**
     * "返回修改": go back to the service page with the selection intact.
     *
     * The destination is rebuilt from the re-resolved session, never from the
     * request, so this cannot become an open redirect. A one-shot marker
     * carries the intent instead of a query parameter, which would otherwise
     * create a second crawlable URL for the same product page.
     */
    public function back(Request $request): RedirectResponse
    {
        $this->assertSelectionSurfaceAvailable();

        $selection = $this->checkout->resolve($request);

        if ($selection === null) {
            return $this->noindexRedirect($this->recover($request));
        }

        $this->checkout->markResume($request);

        return $this->noindexRedirect(
            redirect()->to($selection['return_url'].'#checkout')
        );
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
            ? $variant->service->primaryUrl()
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

    /**
     * Redirects carry the header too.
     *
     * A 302 is a crawlable response in its own right, so the whole checkout
     * flow — start, return and recovery — is marked unconditionally rather
     * than relying on the site-wide IndexingPolicy.
     */
    private function noindexRedirect(RedirectResponse $redirect): RedirectResponse
    {
        return $redirect->withHeaders([
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
