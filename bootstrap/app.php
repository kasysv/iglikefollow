<?php

use App\Http\Middleware\AddRobotsHeader;
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
