<?php

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\MockCheckoutController;
use App\Http\Controllers\OrderLookupController;
use App\Http\Controllers\StorefrontController;
use App\Http\Middleware\NeverIndex;
use App\Support\IndexingPolicy;
use Illuminate\Support\Facades\Route;

Route::get('/', [StorefrontController::class, 'home'])->name('home');

/*
 * R5:共通 FAQ 頁。indexable 且為 global FAQ 的唯一完整 owner;
 * ⛔ 主導覽連結一律指向這個乾淨路徑,不用 query／fragment 當主形式。
 */
Route::get('/faq', [StorefrontController::class, 'faq'])->name('faq');

/*
 * 免會員訂單查詢結果。
 *
 * ⛔ POST only：查詢條件含 Email 與手機，⛔ 絕不能進 URL、query string 或
 * redirect location——那會留在瀏覽器歷史、referrer header 與沿途每一個
 * proxy log 裡。結果直接 render，不 redirect。
 *
 * ⛔ `NeverIndex`：這一頁含客人的訂單內容，永遠不得被索引；controller 另外
 * 設 `Cache-Control: private, no-store`，連瀏覽器本機都不落磁碟。
 *
 * ⛔ 嚴格 throttle：兩項門檻擋得住隨機猜測，但擋不住有人拿一份 Email 名單
 * 慢慢試。10 次／分鐘讓那件事變得不划算。
 */
Route::post('/order-lookup', OrderLookupController::class)
    ->middleware(['throttle:10,1', NeverIndex::class])
    ->name('order-lookup');

Route::get('/services/{platform}', [StorefrontController::class, 'platform'])
    ->name('platform');

Route::get('/services/{platform}/{service}', [StorefrontController::class, 'service'])
    ->name('service');

/*
 * D-103 canonical 商品頁。⛔ 唯一主要形式是尾斜線 `/product/{slug}/`
 * (route() 產生後由 Service::primaryUrl() 補尾斜線;Laravel 路由匹配
 * 對尾斜線寬容,controller 內把非尾斜線 302 收斂到主形式)。
 */
Route::get('/product/{product}', [StorefrontController::class, 'product'])
    ->name('product');

// 兩頁式結帳：服務頁只選商品，/checkout 才填履約、聯絡、發票與付款。
// ⛔ 全部僅限 local／testing，controller 內另有 environment 檢查。
// never-index 以 middleware 套用整組，⛔ 驗證失敗丟出的 redirect 也必須帶到。
Route::middleware(NeverIndex::class)->group(function () {
    Route::post('/checkout/start', [CheckoutController::class, 'start'])
        ->middleware('throttle:20,1')
        ->name('checkout.start');

    Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout');

    Route::post('/checkout/return', [CheckoutController::class, 'back'])
        ->middleware('throttle:20,1')
        ->name('checkout.return');

    Route::post('/checkout/mock', [MockCheckoutController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('checkout.mock');
});

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
