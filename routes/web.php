<?php

use App\Http\Controllers\MockCheckoutController;
use App\Http\Controllers\StorefrontController;
use Illuminate\Support\Facades\Route;

Route::get('/', [StorefrontController::class, 'home'])->name('home');

Route::post('/checkout/mock', [MockCheckoutController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('checkout.mock');

Route::get('/robots.txt', function () {
    $contents = config('seo.indexing_enabled')
        ? "User-agent: *\nAllow: /"
        : "User-agent: *\nDisallow: /";

    return response($contents, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
})->name('robots');

Route::get('/api/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'iglikefollow',
        'environment' => app()->environment(),
        'indexing' => config('seo.indexing_enabled'),
    ]);
})->name('health');
