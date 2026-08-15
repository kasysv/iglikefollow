<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class StorefrontController extends Controller
{
    public function home(): View
    {
        return view('storefront.home', [
            'platforms' => config('catalog.platforms'),
        ]);
    }

    public function platform(string $platform): View
    {
        $data = config("catalog.platforms.{$platform}");

        abort_if($data === null, 404);

        return view('storefront.platform', [
            'platform' => $data,
        ]);
    }

    public function service(string $platform, string $service): View
    {
        $platformData = config("catalog.platforms.{$platform}");

        abort_if($platformData === null, 404);

        $serviceData = $platformData['services'][$service] ?? null;

        abort_if($serviceData === null, 404);

        return view('storefront.service', [
            'platform' => $platformData,
            'service' => $serviceData,
        ]);
    }
}
