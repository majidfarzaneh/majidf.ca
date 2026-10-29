<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SESSION_DOMAIN (e.g. ".majidf.local") shares one sign-in across the site, panel and admin subdomains.
 * Requests on any other host (localhost, an IP) get a host-only cookie instead, which browsers would otherwise reject.
 */
class ScopeSessionDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $domain = ltrim((string) config('session.domain'), '.');

        if ($domain !== '' && $request->getHost() !== $domain && ! str_ends_with($request->getHost(), '.'.$domain)) {
            config(['session.domain' => null]);
        }

        return $next($request);
    }
}
