<?php

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\MockCheckoutController;
use App\Http\Controllers\StorefrontController;
use App\Support\IndexingPolicy;
use Illuminate\Support\Facades\Route;

Route::get('/', [StorefrontController::class, 'home'])->name('home');

Route::get('/services/{platform}', [StorefrontController::class, 'platform'])
    ->name('platform');

Route::get('/services/{platform}/{service}', [StorefrontController::class, 'service'])
    ->name('service');

// 兩頁式結帳：服務頁只選商品，/checkout 才填履約、聯絡、發票與付款。
// ⛔ 全部僅限 local／testing，controller 內另有 environment 檢查。
Route::post('/checkout/start', [CheckoutController::class, 'start'])
    ->middleware('throttle:20,1')
    ->name('checkout.start');

Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout');

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
