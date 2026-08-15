<?php

namespace App\Http\Controllers;

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

        // 只有從 /checkout 按「返回修改」（?resume=1）才帶回原本的選擇。
        // ⛔ 一般瀏覽不得套用 session：否則客人下次進來會看到上次選的項目，
        // 而不是這個服務的預設項目。
        $selection = $request->boolean('resume')
            ? $this->checkout->resolve($request)
            : null;

        $resumed = $selection !== null && $selection['variant']->service->is($record)
            ? $selection
            : null;

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
