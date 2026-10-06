<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells search engines not to index or follow anything on this site.
 */
class NoIndex
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
