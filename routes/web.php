<?php

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\MockCheckoutController;
use App\Http\Controllers\OrderLookupController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StorefrontController;
use App\Http\Middleware\NeverIndex;
use App\Support\CanonicalUrl;
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
/*
 * ⭐ Owner 指定：獨立工具頁 `/order-check`（取代首頁的內嵌區塊）。
 *
 * ⛔ 舊的 `/order-lookup` **直接移除**，⛔ 不做 301／302、alias 或 canonical。
 * 它從未 push／deploy，外面沒有任何連結指向它——為一個從未公開過的路徑建立
 * 轉址，只會憑空增加一條要永久維護的 URL。
 *
 * ⛔⛔ 但 HMAC domain 字串裡的 `order-lookup` 是**內部密碼學 domain**，
 * 不是公開 URL：改動它會讓既有的所有 lookup hash 全部失效，客人再也查不到
 * 自己的訂單。⛔ 不得因為這次改 URL 而順手改那個常數。
 */
Route::get('/order-check', [OrderLookupController::class, 'show'])
    ->middleware(NeverIndex::class)
    ->name('order-check');

/*
 * ⛔ 結果仍在**同一個 URL** 直接 render，⛔ 不 redirect。
 *
 * redirect 會把查詢條件推進 URL 或 session flash——Email 與手機一旦進了 URL，
 * 就會留在瀏覽器歷史、referrer header 與沿途每一個 proxy log 裡。
 *
 * ⛔ 嚴格 throttle 只掛在 POST：兩項門檻擋得住隨機猜測，但擋不住有人拿一份
 * Email 名單慢慢試。10 次／分鐘讓那件事變得不划算。GET 只是表單，不需要
 * 同一個限制。
 */
Route::post('/order-check', [OrderLookupController::class, 'lookup'])
    ->middleware(['throttle:10,1', NeverIndex::class])
    ->name('order-check.lookup');

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

/*
 * ⭐ M5A：只含 14 條 indexable canonical 的 sitemap。
 *
 * ⛔ 不套 `NeverIndex`：sitemap 本身不是要被索引的「頁面」，
 * 它是給爬蟲讀的資料檔；⭐ 而它是否**該被讀取**由 robots.txt 決定
 * ——不可索引時 robots 是 `Disallow: /`，爬蟲根本不會來要它。
 */
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

Route::get('/robots.txt', function (IndexingPolicy $indexingPolicy) {
    /*
     * ⛔⛔ 只有在真正允許索引時才 `Allow`，⛔ 且同時附上 sitemap 位置。
     *
     * ⭐ `IndexingPolicy` 已同時要求 production ＋ flag ＋ **精確 host**，
     * 所以 staging host 即使 `APP_ENV=production` 且 flag 誤開，
     * ⛔ 仍然會落在 `Disallow: /`（施工單 §3）。
     *
     * ⛔ sitemap URL 用 trusted origin，⛔ 不用 request Host——
     * 否則攻擊者送一個假 Host 就能讓我們的 robots.txt 指向他的網域。
     */
    if (! $indexingPolicy->allows(request())) {
        return response("User-agent: *\nDisallow: /", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    $contents = "User-agent: *\nAllow: /\n\nSitemap: ".CanonicalUrl::to('/sitemap.xml');

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

/*
|--------------------------------------------------------------------------
| M5A fallback：舊站 path 沒有對應 route
|--------------------------------------------------------------------------
|
| ⛔⛔ 這**不是** catch-all 轉首頁。施工單 §2.4 明文禁止那件事，理由也很實際：
| 把所有未知 URL 轉去首頁會讓 Google 認為我們有大量「軟 404」，
| ⭐ 而且真正打錯字的訪客會以為自己找到了正確頁面。
|
| ⭐ 它存在的唯一理由是：`/shop/`、`/product/ig粉絲/`、`/cart/` 這些舊 path
| 在新站**沒有任何 route**，因此 web group 的 middleware 根本不會執行
| ——⛔ 我實測確認過：加了 middleware 之後它們仍然直接 404。
|
| ⛔ 所以這裡把「有沒有對應規則」的判斷交回同一個 resolver：
|  - 命中 410 清單 → 410；
|  - 命中 legacy／alias → 由 middleware 發出的 301（本 closure 不會被執行到）；
|  - ⛔ 其餘一律 **真 404**。
*/
Route::fallback(function () {
    abort(404);
});
