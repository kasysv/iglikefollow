<?php

use App\Http\Controllers\Payments\EcpayCallbackController;
use App\Http\Controllers\Payments\LinePayReturnController;
use App\Http\Controllers\Payments\PaymentStatusController;
use App\Http\Controllers\Payments\StartPaymentController;
use App\Http\Middleware\NeverIndex;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 付款路由
|--------------------------------------------------------------------------
|
| ⛔ 全部無條件 noindex：付款頁、回呼與狀態頁都不該進索引，也不進 sitemap、
| canonical、Schema 或導覽。
|
| 命名刻意與 provider 分離（`payments.*`），⛔ 公開網址不會透露我們用了哪一家
| 金流；換 provider 也不需要改動已被分享出去的網址。
|
*/

Route::middleware(NeverIndex::class)->group(function () {
    // 客人送出結帳並前往付款服務。
    Route::post('/payments/start', [StartPaymentController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('payments.start');

    /*
     | 綠界的 server-to-server 付款結果。
     |
     | ⛔ 這是唯一豁免 CSRF 的路由，而且只有它：綠界的伺服器不可能帶著我們的
     | session token。安全性完全來自 CheckMacValue 驗證，不是來自 CSRF。
     */
    Route::post('/payments/ecpay/callback', EcpayCallbackController::class)
        ->middleware('throttle:60,1')
        ->name('payments.ecpay.callback');

    // LINE Pay 把客人導回來的位置；⛔ 這兩個不是付款證明，真正的確認在 server 端。
    Route::get('/payments/linepay/{reference}/confirm', [LinePayReturnController::class, 'confirm'])
        ->middleware('throttle:30,1')
        ->name('payments.linepay.confirm');

    Route::get('/payments/linepay/{reference}/cancel', [LinePayReturnController::class, 'cancel'])
        ->middleware('throttle:30,1')
        ->name('payments.linepay.cancel');

    // 唯讀狀態頁；reference 不可猜測，⛔ URL 不含個資。
    Route::get('/payments/{reference}/status', PaymentStatusController::class)
        ->middleware('throttle:60,1')
        ->name('payments.status');
});
