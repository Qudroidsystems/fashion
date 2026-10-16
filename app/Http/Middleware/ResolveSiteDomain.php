<?php

namespace App\Http\Middleware;

use App\Services\Sites\SiteService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A house's own verified domain (Business plan) is served as if it were the
 * house's subdomain, so the same site routes answer it.
 */
class ResolveSiteDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $base = config('sites.domain');
        $host = strtolower($request->getHost());
        $appHost = strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));
        if ($base && $host !== $appHost && ! str_ends_with($host, '.' . $base) && $host !== $base && ! in_array($host, ['localhost', '127.0.0.1'], true)) {
            $tenant = app(SiteService::class)->byDomain($host);
            if ($tenant) {
                $request->headers->set('host', $tenant->slug . '.' . $base);
                $request->server->set('HTTP_HOST', $tenant->slug . '.' . $base);
                $request->attributes->set('site_own_domain', $host);
            }
        }

        return $next($request);
    }
}
