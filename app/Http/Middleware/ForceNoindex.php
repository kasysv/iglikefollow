<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin panel and draft previews must never be indexable.
 *
 * This is unconditional: unlike the public IndexingPolicy, it does not consult
 * environment or host, so opening the public site to indexing later cannot
 * expose the admin panel or preview URLs.
 *
 * M5C：後台路徑已由 `/admin` 改為 `/ignfdash`。這個 middleware 刻意
 * **不看 path**——它掛在 panel 自己的 middleware 堆疊上，所以換路徑
 * 不影響它，⛔ 這裡也不該出現任何硬編碼的後台網址。
 * ⭐ 正式站已開放索引，這一層的無條件性比以前更重要。
 */
class ForceNoindex
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
