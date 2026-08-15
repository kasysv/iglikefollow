<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class StorefrontController extends Controller
{
    public function home(): View
    {
        $service = config('catalog.instagram_followers');

        return view('storefront.home', [
            'service' => $service,
            'plans' => $service['plans'],
        ]);
    }
}
