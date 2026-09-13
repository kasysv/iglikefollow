<?php

namespace App\Http\Middleware;

use App\Support\CanonicalUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One request in, one final answer out — never a chain.
 *
 * 舊站的 `http://iglikefollow.com/product/ig粉絲/` 同時有四個問題
 * （HTTP、apex host、legacy path、尾斜線）。若每個問題各轉一次，
 * 使用者要跑四趟才會到達目的地，而過長的 redirect chain 會讓爬蟲提早放棄。
 * 因此 origin 與 path 的正規化合併成同一個 Location：各自算出最終形式，
 * 再一次組出來。
 *
 * 只處理 GET／HEAD：對 POST 發 301 會讓瀏覽器改用 GET 重送，
 * 那會把一筆結帳請求變成一個什麼都沒做的頁面請求。
 */
class CanonicalUrlRedirect
{
    /**
     * 完全不經過 SEO 正規化的前綴。
     *
     * R2：這些路由的 path 本身帶識別碼（LINE Pay 的 reference、
     * transactionId），query 也是 handler 需要的資料。SEO 正規化會把整個
     * path 轉小寫並丟掉 query——GPT 的 probe 重現了
     * `/payments/review-REFERENCE/status?review_token=keep-me`
     * 被改寫成 `/payments/review-reference/status`（大小寫改變、query 消失）。
     * `LinePayReturnController::identityMatches()` 需要比對 `orderId` 與
     * `transactionId`，那種改寫會讓付款確認拿不到必要資料。
     *
     * 這是「交回原 handler」，不是「豁免安全檢查」：原路由自己的
     * middleware（NeverIndex、throttle、CSRF）全部照常執行。
     */
    private const BYPASS_PREFIXES = [
        '/payments',
        /*
         * ⛔⛔ M5C：後台改走 `/ignfdash`，這一條必須跟著加。
         *
         * ⭐ 後台的 URL 帶識別碼（`/ignfdash/orders/IGLF-20260913-ABCD`）
         * 而 SEO 正規化會把整個 path 轉小寫並丟掉 query——那會讓
         * Filament 找不到 record，也會破壞 Livewire 的往返。
         *
         * ⛔ 舊 `/admin` 的保護邊界**保留**：它現在是 404，但萬一
         * 將來有人再掛東西上去，⛔ 不該因為少了這一行而被改寫。
         * ⭐ 兩條都在這裡，語意是「這些前綴交回原 handler」，
         * ⛔ 不是「豁免安全檢查」——原路由自己的 middleware 照常執行。
         */
        '/ignfdash',
        '/admin',
        '/api',
        '/up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // 只收斂 SEO 可見的讀取方法。
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        /*
         * R2：交易／後台／API 在任何其他判斷之前就交回原路由。
         *
         * 放在最前面是刻意的：只要有任何一條分支先動了 path，
         * 識別碼與 query 就已經被改寫了。
         */
        if ($this->bypassesSeoNormalisation($request->getPathInfo())) {
            return $next($request);
        }

        $normalised = CanonicalUrl::normalisePath($request->getPathInfo());

        /*
         * 410 優先於一切轉址。
         *
         * `/cart` 與 `/my-account` 在新站不存在，Owner 明確指定 410。
         * 若讓它們先掉進尾斜線正規化，`/cart/` 會先被轉成 `/cart` 再回 410
         * ——那就是兩跳，而且第一跳毫無意義。
         */
        if (CanonicalUrl::isGone($normalised)) {
            abort(410);
        }

        /*
         * 授權過的 Owner／Editor preview 不得被收斂。
         *
         * middleware 在 controller 之前跑，所以 Owner 帶 `?preview=1` 開
         * 商品級 `/services/...` 時會被 301 走，preview 功能整個消失。
         * guest 自己加 `?preview=1` 仍要被收斂：授權判斷與 controller 的
         * `wantsPreview()` 同一組條件（已登入 ＋ Owner／Editor）。
         */
        if ($this->isAuthorisedPreview($request)) {
            return $next($request);
        }

        $targetPath = CanonicalUrl::finalPathFor($normalised)
            ?? CanonicalUrl::trailingSlashRedirect($request->getPathInfo(), $normalised);

        /*
         * R2：host 收斂只對「確定有效的最終目標」生效。
         *
         * R1 的兩個缺口（GPT 以 probe 重現）：
         *
         *  1. `https://iglikefollow.com/product/ig買粉絲/` 已經帶尾斜線，
         *     所以 `$targetPath` 是 null；host 收斂就拿正規化後的 path 去組
         *     Location，而正規化會去掉尾斜線 → 301 到無斜線版本，
         *     下一次請求再補回來 → 兩跳。
         *  2. apex 上不存在的 `/unknown-review-only` 被 301 到 www 的同一個
         *     不存在路徑，只是把 404 延後一跳。
         *
         * 修正：先問 `finalCanonicalPath()`「這個 path 的有效最終形式是什麼」，
         * 未知 path 與 draft 目標一律得到 null，於是不做 host 收斂，
         * 直接往下走到真正的 404。
         */
        $effectivePath = $targetPath ?? CanonicalUrl::finalCanonicalPath($normalised);

        $originRedirect = $effectivePath === null
            ? null
            : CanonicalUrl::productionOriginRedirect($request);

        if ($targetPath === null && $originRedirect === null) {
            return $next($request);
        }

        $path = $effectivePath ?? $normalised;

        /*
         * 永久轉址丟棄 query 與 fragment。
         *
         * 舊站是 WooCommerce，外部連結上掛著 `?add-to-cart=123`、
         * `?orderby=...`。新站不認得它們，保留下來只會產生大量只有 query
         * 不同的重複 URL。交易路由已在最上方 bypass，不受這條影響。
         *
         * 與 canonical／sitemap 使用同一種 percent-encoded 形式，
         * 避免同一頁出現兩種 URL 表示法。
         */
        $location = $originRedirect === null
            ? CanonicalUrl::to($path)
            : rtrim($originRedirect, '/').CanonicalUrl::encodePath($path);

        /*
         * 301 永久轉址：舊 URL 確實永久搬走了。永久轉址是 canonical 的強訊號，
         * 暫時轉址是較弱的訊號。
         */
        return redirect()->to($location, 301);
    }

    /**
     * 交易／後台／API 路由：原始 path 與 query 原封不動交回原 handler。
     *
     * exact 比對或「前綴 ＋ `/`」，避免 `/apixyz` 這種只是字首相同的 path
     * 被誤判成 API。
     */
    private function bypassesSeoNormalisation(string $pathInfo): bool
    {
        $path = rtrim($pathInfo, '/');

        if ($path === '') {
            return false;
        }

        foreach (self::BYPASS_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 只有已登入的 Owner／Editor 要求 preview 才豁免。
     *
     * 與 `StorefrontController::wantsPreview()` 同一組條件；
     * guest 帶 `?preview=1` 一律不算，仍會被永久收斂。
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
