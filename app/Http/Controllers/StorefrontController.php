<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use App\Support\CatalogRepository;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StorefrontController extends Controller
{
    public function __construct(private readonly CatalogRepository $catalog) {}

    public function home(): View
    {
        return view('storefront.home', [
            'platforms' => $this->catalog->navigablePlatforms(),
            'faqs' => $this->catalog->globalFaqs(),
            'settings' => SiteSetting::current(),
        ]);
    }

    public function platform(Request $request, string $platform): View
    {
        $preview = $this->wantsPreview($request);

        $record = $preview
            ? $this->catalog->findPlatformForPreview($platform)
            : $this->catalog->findPlatform($platform);

        abort_if($record === null, 404);

        return view('storefront.platform', [
            'platform' => $record,
            'faqs' => $this->catalog->platformFaqs($record),
            'isPreview' => $preview,
        ]);
    }

    public function service(Request $request, string $platform, string $service): View
    {
        $preview = $this->wantsPreview($request);

        $record = $preview
            ? $this->catalog->findServiceForPreview($platform, $service)
            : $this->catalog->findService($platform, $service);

        abort_if($record === null, 404);

        return view('storefront.service', [
            'service' => $record,
            'platform' => $record->platform,
            'isPreview' => $preview,
        ]);
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
}
