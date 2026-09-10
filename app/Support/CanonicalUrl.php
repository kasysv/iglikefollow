<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The one place that decides what a URL's final, canonical form is.
 *
 * ⛔⛔ 這個類別是本輪的安全邊界。它回答兩個問題，⛔ 而且只由這裡回答：
 *
 *  1. **origin 是什麼**——canonical、sitemap 與永久 Location 的 scheme+host；
 *  2. **path 的最終形式是什麼**——正規化、legacy 對照、尾斜線規則。
 *
 * ⛔⛔ origin **絕不**來自 request 的 Host header。
 *
 * ⭐ 那是本輪最容易出的安全漏洞：攻擊者送一個
 * `Host: evil.test` 的請求，如果我們用它組 Location 或 canonical，
 * 就等於幫對方把我們的 SEO 權重與使用者導去他的網域。
 * ⛔ 所以 origin 一律讀 trusted 的 `APP_URL`（`config('app.url')`），
 * ⭐ 那是部署時由我們自己設定的值，攻擊者碰不到。
 *
 * ⛔ 也因此 local／testing／staging 各自用自己的 `APP_URL`：
 * ⛔ staging 不會被強制導向正式站（那會讓測試環境的流量流到正式站），
 * ⭐ 而正式站的 apex／HTTP 收斂由 `productionOrigin()` 另外處理。
 */
final class CanonicalUrl
{
    /**
     * ⛔ 首頁以外、固定**無**尾斜線的 canonical path。
     *
     * ⭐ 商品頁是唯一固定**有**尾斜線的一組（沿用既有 D-103 決定，
     * 因為舊站的商品 URL 就是那個形式，外部連結都指向它）。
     */
    private const TRAILING_SLASH_EXEMPT_PREFIXES = [
        '/faq',
        '/services',
        '/checkout',
        '/order-check',
    ];

    /**
     * The trusted origin for canonical URLs, sitemap entries and Locations.
     *
     * ⛔ 只讀 config，⛔ 絕不讀 `$request->getSchemeAndHttpHost()`。
     */
    public static function origin(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * An absolute URL on the trusted origin, percent-encoded.
     *
     * ⛔⛔ 中文 path 一律輸出 **percent-encoded** 形式。
     *
     * ⭐ 我實測發現既有頁面的 canonical（由 Laravel `route()` 產生）是
     * `/product/ig%E8%B2%B7%E7%B2%89%E7%B5%B2/`，而我原本讓 sitemap 輸出
     * raw UTF-8 的 `/product/ig買粉絲/`。兩者指的是同一個 URL，
     * ⛔ 但**混用兩種形式**正是造成「同一頁被當成兩個 URL」的典型原因
     * ——canonical 說一種、sitemap 說另一種，等於自己製造重複內容訊號。
     *
     * ⭐ 統一成 encoded：它是 wire format，任何 client 都不會誤解；
     * ⛔ 而 raw UTF-8 在部分伺服器與 log 管線上仍可能被改寫。
     */
    public static function to(string $path): string
    {
        return self::origin().self::encodePath($path);
    }

    /**
     * Percent-encode each path segment, keeping the slashes.
     *
     * ⛔ 逐段編碼，⛔ 不是整串 `rawurlencode()`——後者會把 `/` 也編掉。
     */
    public static function encodePath(string $path): string
    {
        $hasTrailingSlash = $path !== '/' && str_ends_with($path, '/');

        $encoded = implode('/', array_map(
            static fn (string $segment): string => rawurlencode($segment),
            explode('/', trim($path, '/')),
        ));

        return '/'.$encoded.($hasTrailingSlash ? '/' : '');
    }

    /**
     * Normalise a raw request path into the form used as a mapping key.
     *
     * ⛔ 正規化只做四件事，⛔ 每一件都有理由：
     *
     *  1. **percent-decode**：舊站的中文 URL 在外部連結裡兩種形式都存在
     *     （`/product/ig粉絲/` 與 `/product/ig%E7%B2%89%E7%B5%B2/`），
     *     ⛔ 兩者必須落在同一個 mapping key 上。
     *  2. **去尾斜線**：`/shop` 與 `/shop/` 是同一個舊頁面。
     *  3. **小寫**：只影響 ASCII；⛔ 中文不受影響（`mb_strtolower` 對
     *     漢字是 no-op），而舊站的英文 slug 大小寫混用過。
     *  4. **摺疊重複斜線**：`//shop` 不該逃過對照。
     *
     * ⛔ 刻意**不**做的事：不 trim 中間空白、不轉換全形、不做模糊比對。
     * ⭐ 那些會讓兩個不同的舊 URL 意外落到同一條規則上，
     * ⛔ 而 301 是永久的，猜錯就是永久猜錯。
     */
    public static function normalisePath(string $path): string
    {
        // ⛔ 只取 path，丟掉 query 與 fragment（它們不參與對照）。
        $path = (string) parse_url($path, PHP_URL_PATH);

        $decoded = rawurldecode($path);

        // ⛔ 摺疊重複斜線。
        $decoded = (string) preg_replace('#/{2,}#', '/', $decoded);

        if ($decoded === '' || $decoded[0] !== '/') {
            $decoded = '/'.$decoded;
        }

        $decoded = mb_strtolower($decoded, 'UTF-8');

        // ⛔ 根路徑保留單一斜線；其餘一律去尾斜線。
        return $decoded === '/' ? '/' : rtrim($decoded, '/');
    }

    /**
     * The final canonical path for a normalised path, or null if it is not ours.
     *
     * ⭐ 回傳值是**最終**形式：呼叫端拿到就可以直接 301，
     * ⛔ 不需要（也不得）再轉一次。
     */
    public static function finalPathFor(string $normalised): ?string
    {
        // 1. legacy 對照表（15 條）——⛔ 最高優先，它們是外部既有連結。
        $legacy = (array) config('legacy-redirects.legacy', []);

        if (isset($legacy[$normalised])) {
            return $legacy[$normalised];
        }

        // 2. 商品級 services alias（9 條）——⛔ 由既有 ProductSlugMap 推導。
        if (($alias = self::productAliasTarget($normalised)) !== null) {
            return $alias;
        }

        /*
         * 3. 尾斜線正規化。
         *
         * ⛔⛔ 這裡回傳的必須是「**與現況不同**的最終形式」，null 代表
         * 「已經正確、不需要轉」。
         *
         * ⭐ 我第一版在這裡出過一個真正危險的 bug：`normalisePath()` 已經
         * 去掉尾斜線，於是 `/faq` 與 `/faq/` 都變成 `/faq`，而我又把 `/faq`
         * 當成「要轉去的目標」回傳——結果 `/faq` 轉去 `/faq`，
         * ⛔ **無限轉址迴圈**。我是印出實際 status 才看到的。
         *
         * ⛔ 因此這一段改為只回報「原始請求的尾斜線與規則不符」的情況，
         * 而那需要看**原始** path，不是正規化後的。
         */
        return null;
    }

    /**
     * The trailing-slash correction for a *raw* request path, or null.
     *
     * ⛔ 這個判斷必須看**原始**請求（是否真的帶尾斜線），
     * ⛔ 不能看正規化後的字串——正規化已經把尾斜線資訊抹掉了。
     */
    public static function trailingSlashRedirect(string $rawPath, string $normalised): ?string
    {
        if ($normalised === '/') {
            return null;
        }

        $hadTrailingSlash = str_ends_with(
            (string) parse_url($rawPath, PHP_URL_PATH),
            '/'
        );

        /*
         * ⛔ 商品頁固定**有**尾斜線。
         *
         * ⛔⛔ 但只有**真實存在**的商品才收斂。
         *
         * ⭐ 我第一版少了這個條件，於是 `/product/UPPER`、`/product/a b`
         * 這些本來就該 404 的無效 slug 全都變成 301——⛔ 那等於把「找不到」
         * 變成「已搬家」，而且製造出無限多條指向 404 的永久轉址。
         * 既有測試 `test_invalid_slugs_are_rejected` 以
         * `[404] but received 301` 抓到了這件事。
         *
         * ⛔ 查不到就回 null，讓它照常走到 404。
         */
        if (str_starts_with($normalised, '/product/')) {
            if ($hadTrailingSlash) {
                return null;
            }

            $slug = substr($normalised, strlen('/product/'));

            // ⛔ 只處理單段 slug；⛔ 更深的路徑不是商品頁。
            if ($slug === '' || str_contains($slug, '/')) {
                return null;
            }

            return app(CatalogRepository::class)->findServiceByProductSlug($slug) === null
                ? null
                : $normalised.'/';
        }

        // ⛔ 其餘 canonical 前綴固定**無**尾斜線。
        foreach (self::TRAILING_SLASH_EXEMPT_PREFIXES as $prefix) {
            if ($normalised === $prefix || str_starts_with($normalised, $prefix.'/')) {
                return $hadTrailingSlash ? $normalised : null;
            }
        }

        return null;
    }

    /**
     * `/services/{platform}/{service}` → `/product/{slug}/`，或 null。
     *
     * ⛔⛔ 唯一來源是既有的 `ProductSlugMap`，⛔ 不在 config 再抄一份。
     * ⭐ 那份表本來就是 importer 與 seeder 共用的事實來源；
     * 商品若日後改對照，這裡自動跟著改，⛔ 不會出現兩份不同步的表。
     *
     * ⛔ comments／auto-likes 不在表內 → 回 null → 維持既有 404，
     * ⛔ 不新增 SEO 頁、不轉址（施工單 §2.3）。
     */
    public static function productAliasTarget(string $normalised): ?string
    {
        if (! str_starts_with($normalised, '/services/')) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $normalised), fn (string $s): bool => $s !== ''));

        // ⛔ 只處理**商品級**兩段（platform/service）；⛔ Hub 一段不在此列。
        if (count($segments) !== 3) {
            return null;
        }

        $productSlug = ProductSlugMap::for($segments[1], $segments[2]);

        return $productSlug === null ? null : '/product/'.$productSlug.'/';
    }

    /**
     * Is this normalised path one of the explicitly-gone WooCommerce pages?
     */
    public static function isGone(string $normalised): bool
    {
        return in_array($normalised, (array) config('legacy-redirects.gone', []), true);
    }

    /**
     * The production host convergence target, or null when it does not apply.
     *
     * ⛔⛔ 只在**正式 host**（apex 或 www）上才收斂。
     *
     * ⭐ 這是施工單 §2.4 的硬性要求：`staging.iglikefollow.com` 與
     * localhost ⛔ **絕不**被導向正式站。把測試流量導到正式站會讓
     * 測試訂單變成真實訂單——那是會花錢、會開發票的錯誤。
     *
     * ⛔ 判斷只用**精確比對**已知的兩個正式 host，⛔ 不用
     * `str_contains($host, 'iglikefollow.com')` 之類的模糊比對：
     * 那會把 `evil-iglikefollow.com.attacker.test` 也算進來。
     */
    public static function productionOriginRedirect(Request $request): ?string
    {
        $canonicalHost = self::host();

        if ($canonicalHost === '') {
            return null;
        }

        /*
         * ⛔⛔ 只有在 trusted origin 本身是 HTTPS 時才做 host／scheme 收斂。
         *
         * ⭐ 我第一版沒有這個判斷，結果在 local（`APP_URL=http://localhost:8083`）
         * 每一頁都自己轉自己：request host 等於 config host，但
         * `isSecure()` 是 false，於是它永遠認為「還要升級到 HTTPS」。
         * ⛔ 那是**全站無限轉址迴圈**——我是印出實際 status 才看到的。
         *
         * ⭐ 判準因此改為看 **trusted origin 的 scheme**：
         * 正式站的 `APP_URL` 是 `https://www.iglikefollow.com`，
         * 所以收斂只在那裡生效；local／testing 的 `APP_URL` 是 http，
         * ⛔ 完全不進這段邏輯。
         */
        if (parse_url(self::origin(), PHP_URL_SCHEME) !== 'https') {
            return null;
        }

        $requestHost = strtolower(rtrim(trim($request->getHost()), '.'));

        // ⛔ 已經在正式 host 且已是 HTTPS：不需要收斂。
        if ($requestHost === $canonicalHost && $request->isSecure()) {
            return null;
        }

        // ⛔ apex 與 www 是**唯一**兩個會被收斂的 host。
        $apex = str_starts_with($canonicalHost, 'www.')
            ? substr($canonicalHost, 4)
            : $canonicalHost;

        if ($requestHost !== $canonicalHost && $requestHost !== $apex) {
            // ⛔ staging／localhost／任何其他 host：⛔ 一律不動。
            return null;
        }

        return self::origin();
    }

    /** ⛔ trusted origin 的 host，⛔ 不是 request 的。 */
    public static function host(): string
    {
        $host = (string) parse_url(self::origin(), PHP_URL_HOST);

        return strtolower(rtrim(trim($host), '.'));
    }

    /**
     * The 14 indexable canonical paths, in the order the sitemap must use.
     *
     * ⛔ 商品部分由 catalog 實際狀態決定：只有 `published` 且有
     * `product_slug` 的服務才列入。⛔ draft／缺 slug **fail closed**
     * ——⛔ 不列、⛔ 也不拿 alias 補數（施工單 §3）。
     *
     * @return list<string>
     */
    public static function indexablePaths(): array
    {
        $paths = ['/', '/faq'];

        $platforms = ['instagram', 'facebook', 'threads'];

        foreach ($platforms as $platform) {
            $paths[] = '/services/'.$platform;
        }

        /*
         * ⛔ 順序固定：首頁、FAQ、3 Hub、9 商品。
         * ⭐ 商品依 platform → service 的既有 mapping 順序，
         * ⛔ 不依 DB 的 id 或 created_at（那會讓 sitemap 每次重建就換順序）。
         */
        /*
         * ⛔ 用既有的 `CatalogRepository::findService()`，⛔ 不自己拼查詢：
         * ⭐ 它已經同時要求 platform 與 service 都是 `published`，
         * 而那正是「可索引」的定義。自己寫一份等於多一個會走樣的判斷。
         */
        $catalog = app(CatalogRepository::class);

        foreach (array_keys(ProductSlugMap::MAP) as $key) {
            [$platformSlug, $serviceSlug] = explode('/', $key, 2);

            $service = $catalog->findService($platformSlug, $serviceSlug);

            // ⛔ fail closed：找不到、未發布或沒有 product slug 就不列。
            if ($service === null || blank($service->product_slug)) {
                continue;
            }

            $paths[] = '/product/'.$service->product_slug.'/';
        }

        return $paths;
    }
}
