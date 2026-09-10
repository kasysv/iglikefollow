<?php

namespace App\Http\Middleware;

use App\Support\CanonicalUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One request in, one final answer out — never a chain.
 *
 * ⛔⛔ 本輪最重要的性質：**任何來源最多一跳**。
 *
 * ⭐ 為什麼這件事重要到要用 middleware 而不是散在各個 route：
 * 舊站的 `http://iglikefollow.com/product/ig粉絲/` 同時有四個問題
 * （HTTP、apex host、legacy path、尾斜線）。若每個問題各轉一次，
 * 使用者要跑四趟才會到達目的地——⛔ 每一跳都是延遲、都是掉失的
 * 連結權重，而 Googlebot 對過長的 redirect chain 會直接放棄。
 *
 * ⭐ 所以這裡把 origin 與 path 的正規化**合併成同一個 Location**：
 * 先各自算出最終形式，再一次組出來。
 *
 * ⛔ 只處理 GET／HEAD：⛔ 絕不碰 checkout／payment 的 POST 語意
 * （施工單 §2.4）。對 POST 發 301 會讓瀏覽器改用 GET 重送，
 * 那會把一筆結帳請求變成一個什麼都沒做的頁面請求。
 */
class CanonicalUrlRedirect
{
    public function handle(Request $request, Closure $next): Response
    {
        // ⛔ 只收斂 SEO 可見的讀取方法。
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $normalised = CanonicalUrl::normalisePath($request->getPathInfo());

        /*
         * ⛔⛔ 410 優先於一切轉址。
         *
         * ⭐ `/cart` 與 `/my-account` 在新站**不存在**，Owner 明確指定 410。
         * ⛔ 若讓它們先掉進尾斜線正規化，`/cart/` 會先被轉成 `/cart`
         * 再回 410——那就是兩跳，而且第一跳毫無意義。
         */
        if (CanonicalUrl::isGone($normalised)) {
            abort(410);
        }

        /*
         * ⛔⛔ 授權過的 Owner／Editor preview **不得**被收斂。
         *
         * ⭐ 我實測確認過這是一個真的迴歸：middleware 在 controller 之前跑，
         * 所以 Owner 帶 `?preview=1` 開商品級 `/services/...` 時，
         * ⛔ 會被 301 走，preview 功能整個消失（施工單 §2.3 要求維持 200）。
         *
         * ⛔ 但 guest 自己加 `?preview=1` **仍然**要被收斂：
         * ⭐ 授權判斷與 controller 的 `wantsPreview()` 同一組條件
         * （已登入 ＋ Owner／Editor），⛔ 不是只看 query 有沒有那個字。
         */
        if ($this->isAuthorisedPreview($request)) {
            return $next($request);
        }

        $targetPath = CanonicalUrl::finalPathFor($normalised)
            ?? CanonicalUrl::trailingSlashRedirect($request->getPathInfo(), $normalised);

        $originRedirect = CanonicalUrl::productionOriginRedirect($request);

        // ⛔ 兩者都不需要處理：交給正常路由。
        if ($targetPath === null && $originRedirect === null) {
            return $next($request);
        }

        /*
         * ⭐ 合併成單一 Location。
         *
         * ⛔ path 沒有要改時沿用**目前的** path（已正規化），
         * ⛔ 不能拿原始 raw path——那會讓 `//shop` 這種形式逃過正規化。
         */
        $path = $targetPath ?? ($normalised === '/' ? '/' : $normalised);

        $origin = $originRedirect ?? CanonicalUrl::origin();

        /*
         * ⛔⛔ 永久轉址一律**丟棄 query 與 fragment**（施工單 §2.2）。
         *
         * ⭐ 舊站是 WooCommerce，外部連結上掛著 `?add-to-cart=123`、
         * `?variation_id=...`、`?orderby=...` 這類參數。把它們帶到新站
         * 沒有任何意義——新站不認得，⛔ 但它們會產生無限多個「不同」的
         * URL 變體，每一個都是一條要被爬、要被去重的垃圾 URL。
         */
        /*
         * ⛔ 與 canonical／sitemap 使用**同一種** percent-encoded 形式。
         * ⭐ 三處若各用各的寫法，同一頁會出現兩種 URL 表示法。
         */
        $location = $originRedirect === null
            ? CanonicalUrl::to($path)
            : rtrim($origin, '/').'/'.ltrim(CanonicalUrl::encodePath($path), '/');

        /*
         * ⛔ 301 永久轉址。
         *
         * ⭐ 用 301 而不是 302：舊 URL 確實**永久**搬走了，
         * 我們要的正是讓搜尋引擎把權重轉移過去。
         * ⛔ 302 會讓 Google 繼續保留舊 URL，權重不會轉移。
         */
        return redirect()->to($location, 301);
    }

    /**
     * Only a signed-in Owner/Editor asking for preview is exempt.
     *
     * ⛔ 與 `StorefrontController::wantsPreview()` 同一組條件；
     * ⛔ guest 帶 `?preview=1` 一律不算，仍會被永久收斂。
     */
    private function isAuthorisedPreview(Request $request): bool
    {
        if (! $request->boolean('preview')) {
            return false;
        }

        $user = $request->user();

        return $user !== null && ($user->isOwner() || $user->isEditor());
    }
}
