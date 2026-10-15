<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API (/api/v1) requests from the staff and customer apps.
 *
 * The app may send X-Tenant: <slug or public id>. It is honoured ONLY when
 * the token's user has an active membership there (SRS §8.1: "do not trust
 * arbitrary tenant IDs supplied in request bodies"). Without the header the
 * user's first active membership is used.
 */
class ResolveApiTenant
{
    public function __construct(protected TenantContext $context)
    {
    }

    public function handle(Request $request, Closure $next, string $optional = ''): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $memberships = $user->memberships()->active()->whereHas('tenant')->with('tenant');

        if (config('tenancy.mode') === 'single') {
            $memberships->whereHas('tenant', fn ($q) => $q->where('slug', config('tenancy.default_tenant')));
        } elseif ($key = $request->header(config('tenancy.header'))) {
            $memberships->whereHas('tenant', fn ($q) => $q->where('slug', $key)->orWhere('public_id', $key));
        }

        $membership = $memberships->orderByDesc('is_owner')->orderBy('id')->first();

        if (! $membership) {
            if ($optional === 'optional') {
                return $next($request);
            }

            return response()->json([
                'success' => false,
                'code'    => 'tenant_forbidden',
                'message' => 'You do not have access to this fashion house.',
            ], 403);
        }

        if (! $membership->tenant->isUsable()) {
            return response()->json([
                'success' => false,
                'code'    => 'tenant_unavailable',
                'message' => 'This fashion house account is not active. Please contact the owner.',
            ], 403);
        }

        $this->context->set($membership->tenant, $membership);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $next($request);
    }
}
