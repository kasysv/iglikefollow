<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SiteSetting;
use App\Support\CatalogRepository;
use App\Support\CheckoutSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class StorefrontController extends Controller
{
    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly CheckoutSession $checkout,
    ) {}

    public function home(): View
    {
        $settings = SiteSetting::current();

        return view('storefront.home', [
            'platforms' => $this->catalog->navigablePlatforms(),
            'faqs' => $this->catalog->globalFaqs(),
            'settings' => $settings,
            // M2-C:首頁 self-canonical(全站仍 noindex,canonical 只是宣告主形式)。
            'canonical' => url('/').'/',
            // CTA 目的地由設定的固定目標決定，⛔ 不再取「排序第一個」而隨排序漂移。
            'ctaUrl' => $settings?->ctaUrl() ?? route('home').'#platforms',
        ]);
    }

    public function platform(Request $request, string $platform): View|Response
    {
        $preview = $this->wantsPreview($request);

        $record = $preview
            ? $this->catalog->findPlatformForPreview($platform)
            : $this->catalog->findPlatform($platform);

        abort_if($record === null, 404);

        $view = view('storefront.platform', [
            'platform' => $record,
            'faqs' => $this->catalog->platformFaqs($record),
            'isPreview' => $preview,
            // ⛔ preview 不輸出可索引 canonical。
            'canonical' => $preview ? null : route('platform', $record->slug),
        ]);

        return $preview ? $this->noindex($view) : $view;
    }

    /**
     * D-103 canonical 商品頁:`/product/{slug}/`。
     *
     * ⛔ 唯一一致形式=尾斜線;非尾斜線請求在本機以 302 收斂到主形式
     * (正式 301 屬 M5,不在本輪)。slug shape 不合 allowlist 一律 404。
     */
    public function product(Request $request, string $product): View|Response|RedirectResponse
    {
        $record = $this->catalog->findServiceByProductSlug($product);

        abort_if($record === null, 404);

        $canonical = $record->primaryUrl();

        $path = (string) parse_url($request->getRequestUri(), PHP_URL_PATH);

        /*
         * ⛔ 測試 client 的 prepareUrlForRequest 會剝掉尾斜線,永遠送出
         * 非尾斜線 URI;在 unit test runtime 跳過此收斂以免自我迴圈。
         * 真實 HTTP(Apache/PHPStudy)行為由 local smoke 驗證:非尾斜線
         * 302 → 尾斜線 canonical。
         */
        if (! app()->runningUnitTests() && ! str_ends_with($path, '/')) {
            return redirect()->to($canonical, 302);
        }

        return $this->renderServicePage($request, $record, preview: false, canonical: $canonical);
    }

    public function service(Request $request, string $platform, string $service): View|Response|RedirectResponse
    {
        $preview = $this->wantsPreview($request);

        if ($preview) {
            $record = $this->catalog->findServiceForPreview($platform, $service);

            abort_if($record === null, 404);

            return $this->renderServicePage($request, $record, preview: true, canonical: null);
        }

        $record = $this->catalog->findService($platform, $service);

        /*
         * ⛔ D-103:商品級 /services/... 不得形成可索引第二頁。published
         * 且已有 product slug 的 guest request 以單次 302 收斂到唯一
         * canonical;沒有 product slug(draft 已由 findService 排除;
         * 過渡期尚未指派 slug 的 published 服務)且非授權 preview 回 404。
         */
        abort_if($record === null, 404);

        if (filled($record->product_slug)) {
            return redirect()->to($record->primaryUrl(), 302);
        }

        abort(404);
    }

    /** 商品頁共用渲染;product canonical 與 authenticated preview 都走這裡。 */
    private function renderServicePage(Request $request, Service $record, bool $preview, ?string $canonical): View|Response
    {
        $resumed = $this->resumedSelection($request, $record)
            ?? $this->selectionFromOldInput($record);

        $view = view('storefront.service', [
            'service' => $record,
            'platform' => $record->platform,
            'isPreview' => $preview,
            'canonical' => $canonical,
            'resumedVariantId' => $resumed['variant']->id ?? null,
            'resumedQuantity' => $resumed['quantity'] ?? null,
        ]);

        return $preview ? $this->noindex($view) : $view;
    }

    /**
     * The selection to restore after "返回修改".
     *
     * Gated on a one-shot session marker rather than a query parameter, so the
     * product page keeps a single crawlable URL. The marker is consumed on
     * read: refreshing the clean URL afterwards shows the featured item again.
     *
     * @return array{variant: ServiceVariant, quantity: int}|null
     */
    private function resumedSelection(Request $request, Service $record): ?array
    {
        if (! $this->checkout->pullResume($request)) {
            return null;
        }

        $selection = $this->checkout->resolve($request);

        // ⛔ 別的服務的選擇不得套用到這一頁。
        return $selection !== null && $selection['variant']->service->is($record)
            ? $selection
            : null;
    }

    /**
     * The selection to restore after a failed /checkout/start.
     *
     * A rejected quantity should not also discard the chosen item, but the old
     * variant is re-checked against this service's published list first: an
     * unknown, draft, archived or foreign id is ignored rather than trusted.
     *
     * @return array{variant: ServiceVariant, quantity: int}|null
     */
    private function selectionFromOldInput(Service $record): ?array
    {
        $oldVariant = old('variant');

        if ($oldVariant === null) {
            return null;
        }

        $variant = $this->catalog->findPurchasableVariant($oldVariant);

        if ($variant === null || ! $variant->service->is($record)) {
            return null;
        }

        $quantity = old('quantity');

        return [
            'variant' => $variant,
            'quantity' => is_numeric($quantity) ? (int) $quantity : $variant->default_quantity,
        ];
    }

    /**
     * Draft preview is available only to an authenticated admin user.
     *
     * Guests never see draft content, so ?preview=1 is inert in public hands.
     */
    private function wantsPreview(Request $request): bool
    {
        if (! $request->boolean('preview')) {
            return false;
        }

        $user = $request->user();

        return $user !== null && ($user->isOwner() || $user->isEditor());
    }

    /**
     * Preview responses carry their own noindex header.
     *
     * They must not rely on the site-wide IndexingPolicy: once the public site
     * is opened to indexing, a preview URL would otherwise become indexable and
     * could leak unpublished content into search results.
     */
    private function noindex(View $view): Response
    {
        return response($view)->withHeaders([
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
