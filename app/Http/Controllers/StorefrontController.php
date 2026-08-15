<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use App\Support\CatalogRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class StorefrontController extends Controller
{
    public function __construct(private readonly CatalogRepository $catalog) {}

    public function home(): View
    {
        $settings = SiteSetting::current();

        return view('storefront.home', [
            'platforms' => $this->catalog->navigablePlatforms(),
            'faqs' => $this->catalog->globalFaqs(),
            'settings' => $settings,
            'ctaUrl' => $this->resolveCtaUrl($settings),
        ]);
    }

    /**
     * Resolve the homepage CTA destination from the stored route name.
     *
     * Only names on SiteSetting::CTA_ROUTES are honoured, and 'home' falls
     * through to the platform picker anchor. Anything unrecognised — including
     * a value written directly to the database — degrades to that same anchor
     * rather than becoming an open redirect.
     */
    private function resolveCtaUrl(?SiteSetting $settings): string
    {
        $anchor = route('home').'#platforms';
        $name = $settings?->primary_cta_route;

        if (! in_array($name, SiteSetting::CTA_ROUTES, true) || $name === 'home') {
            return $anchor;
        }

        $platform = $this->catalog->navigablePlatforms()->first();

        if ($platform === null) {
            return $anchor;
        }

        if ($name === 'platform') {
            return route('platform', $platform->slug);
        }

        $service = $platform->services()->published()->orderBy('sort_order')->first();

        return $service
            ? route('service', [$platform->slug, $service->slug])
            : $anchor;
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

        $view = view('storefront.service', [
            'service' => $record,
            'platform' => $record->platform,
            'isPreview' => $preview,
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
