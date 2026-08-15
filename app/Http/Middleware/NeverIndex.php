<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks every response on a route as noindex, unconditionally.
 *
 * Checkout must never be indexable, and it must not depend on the site-wide
 * IndexingPolicy: once the public site is opened to indexing, an order form
 * or a mock success page would otherwise become fair game.
 *
 * A middleware rather than per-return headers because a validation failure
 * throws out of the controller — the redirect Laravel builds for it never
 * passes through the action's own return statement.
 */
class NeverIndex
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
