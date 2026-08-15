<?php

use App\Http\Controllers\MockCheckoutController;
use App\Http\Controllers\StorefrontController;
use App\Support\IndexingPolicy;
use Illuminate\Support\Facades\Route;

Route::get('/', [StorefrontController::class, 'home'])->name('home');

Route::post('/checkout/mock', [MockCheckoutController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('checkout.mock');

Route::get('/robots.txt', function (IndexingPolicy $indexingPolicy) {
    $contents = $indexingPolicy->allows(request())
        ? "User-agent: *\nAllow: /"
        : "User-agent: *\nDisallow: /";

    return response($contents, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
})->name('robots');

Route::get('/api/health', function (IndexingPolicy $indexingPolicy) {
    return response()->json([
        'status' => 'ok',
        'service' => 'iglikefollow',
        'environment' => app()->environment(),
        'indexing' => $indexingPolicy->allows(request()),
    ]);
})->name('health');
