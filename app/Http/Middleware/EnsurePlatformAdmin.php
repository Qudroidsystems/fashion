<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Platform operator screens (SRS FR-ADM-001). Multi-tenant mode only. */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_platform_admin, 403, 'Platform operators only.');
        abort_if(config('tenancy.mode') === 'single', 404);

        return $next($request);
    }
}
