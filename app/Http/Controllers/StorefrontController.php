<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\SiteSetting;
use App\Support\CatalogRepository;
use App\Support\CheckoutSession;
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
        ]);

        return $preview ? $this->noindex($view) : $view;
    }

    public function service(Request $request, string $platform, string $service): View|Response
    {
        $preview = $this->wantsPreview($request);

        $record = $preview
            ? $this->catalog->findServiceForPreview($platform, $service)
            : $this->catalog->findService($platform, $service);

        abort_if($record === null, 404);

        $resumed = $this->resumedSelection($request, $record)
            ?? $this->selectionFromOldInput($record);

        $view = view('storefront.service', [
            'service' => $record,
            'platform' => $record->platform,
            'isPreview' => $preview,
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
