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
 * expose /admin or preview URLs.
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
