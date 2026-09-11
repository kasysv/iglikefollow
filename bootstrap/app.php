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
         | M5A：URL 正規化掛在 web group，在 controller 之前給出最終答案。
         |
         | 15 條 legacy path（例如 `/shop/`、`/product/ig粉絲/`）在新站沒有
         | 對應 route，靠 `Route::fallback()` 接住——fallback 本身就是 web
         | route，所以這個 middleware 一樣會跑到，不需要排在 session 之前。
         |
         | R3：改用 `appendToGroup()`，排在 EncryptCookies 與 StartSession
         | 之後。
         |
         | 原本用 `prependToGroup()` 會讓它成為 web group 的第一道，
         | 而它需要 `$request->user()` 判斷 Owner／Editor 的 preview 豁免。
         | 在 StartSession 之前 session 還沒載入，`user()` 永遠是 null，
         | 於是真正登入的 Owner 帶 `?preview=1` 會被 301 走、預覽功能失效。
         |
         | 既有測試用 `actingAs()` 預先把 User 放進 guard，所以看不出差異；
         | GPT 以「只帶 session cookie 的新 request」反證了真實行為。
         |
         | 註：舊註解寫「prepend 等於路由解析前」並不正確——group middleware
         | 本來就在路由比對之後才執行。
         */
        $middleware->appendToGroup('web', CanonicalUrlRedirect::class);

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
