<?php

use App\Http\Middleware\AddRobotsHeader;
use App\Http\Middleware\CanonicalUrlRedirect;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')->group(__DIR__.'/../routes/payments.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        /*
         | ⭐ M5A：URL 正規化必須在**路由解析之前**發生。
         |
         | ⛔ 15 條 legacy path（例如 `/shop/`、`/product/ig粉絲/`）在新站
         | 沒有對應 route。若等到路由解析完才處理，它們會先變成 404，
         | ⛔ 而 404 之後再想轉址就只能靠 exception handler——那是一條把
         | 「找不到」與「已搬家」混在一起的路。
         |
         | ⭐ `prependToGroup('web')` 讓它成為 web group 的第一道，
         | 在 controller 之前就給出最終答案。
         */
        $middleware->prependToGroup('web', CanonicalUrlRedirect::class);

        $middleware->append(AddRobotsHeader::class);

        /*
         | ⛔ 只有綠界回呼豁免 CSRF，而且是精確路徑，不是萬用字元。
         |
         | 綠界的伺服器不可能帶著我們的 session token，所以 CSRF 在這條路由上
         | 沒有意義；它的安全性完全來自 CheckMacValue 驗證。用 `payments/*`
         | 之類的萬用字元會連帶把其他付款路由的保護一起關掉。
         */
        $middleware->validateCsrfTokens(except: [
            'payments/ecpay/callback',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
