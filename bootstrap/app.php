<?php

use App\Http\Middleware\AddRobotsHeader;
use App\Http\Middleware\CanonicalUrlRedirect;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

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
        /*
         | An unknown path must be a real 404 for every method.
         |
         | `Route::fallback()` only registers GET/HEAD, so `POST /order-lookup`
         | came back as 405 -- the existing test `test_the_old_lookup_path_is_gone`
         | caught it as `[404] but received 405`. A 405 says "this path exists,
         | you used the wrong verb", and the new site has no such resource.
         |
         | R2: only convert the 405 when the path has NO real route at all.
         | R1 converted every 405 globally, which changed the method semantics
         | of routes that genuinely exist: `POST /faq` must stay 405 with its
         | Allow header, because `/faq` really is a GET-only page.
         |
         | Handling it here rather than with a catch-all route is deliberate:
         | `routes/payments.php` is registered AFTER `routes/web.php` via the
         | `then:` callback, so a catch-all silently shadowed every payments
         | POST route (39 payment tests failed with 404).
         */
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            foreach (Route::getRoutes()->getRoutes() as $route) {
                if ($route->isFallback) {
                    continue;
                }

                if ($route->matches($request, includingMethod: false)) {
                    // A real route owns this path: keep the honest 405.
                    return null;
                }
            }

            abort(404);
        });
    })->create();
