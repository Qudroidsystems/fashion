<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web requests: put the signed-in user inside a fashion house.
 *
 * The tenant comes from the user's ACTIVE membership (SRS FR-TEN-003):
 *   single mode  → the default tenant;
 *   multi mode   → a verified custom domain, else the tenant chosen with the
 *                  switcher (session), else the user's first active membership.
 *
 * Runs before route-model binding (see bootstrap/app.php), so tenant scopes
 * already apply when {models} are resolved from the URL.
 */
class ResolveTenant
{
    /** Routes a user without any tenant (e.g. a platform operator) may still open. */
    protected array $tenantlessRoutes = [
        'logout', 'platform.*', 'profile.*', 'tenant.switch', 'home', 'onboarding.*',
    ];

    public function __construct(protected TenantContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $membership = $this->findMembership($request, $user);

        if (! $membership) {
            if ($request->routeIs(...$this->tenantlessRoutes)) {
                return $next($request);
            }

            if ($user->is_platform_admin && config('tenancy.mode') !== 'single') {
                return redirect()->route('platform.tenants.index');
            }

            // Signed up (e.g. with Google) and never part of any house yet: finish the account.
            // Staff whose access was removed are signed out as before.
            if (\App\Http\Controllers\Auth\RegisterController::enabled()
                && ! TenantMembership::withoutTenantScope()->where('user_id', $user->id)->exists()) {
                return redirect()->route('onboarding.show');
            }

            return $this->signOut($request, 'Your account is not linked to an active fashion house. Please contact your administrator.');
        }

        $tenant = $membership->tenant;

        if (! $tenant->isUsable() && ! $request->routeIs(...$this->tenantlessRoutes)) {
            return response()->view('errors.tenant-unavailable', ['tenant' => $tenant], 403);
        }

        $this->context->set($tenant, $membership);
        $request->session()->put('tenant_id', $tenant->id);

        // Roles/permissions were possibly loaded before the team id was set.
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $next($request);
    }

    protected function findMembership(Request $request, User $user): ?TenantMembership
    {
        $active = $user->memberships()->active()->whereHas('tenant')->with('tenant');

        if (config('tenancy.mode') === 'single') {
            return (clone $active)->whereHas('tenant', fn ($q) => $q->where('slug', config('tenancy.default_tenant')))->first();
        }

        // 1. Verified custom domain.
        if ($domainTenant = Tenant::where('domain', $request->getHost())->first()) {
            return (clone $active)->where('tenant_id', $domainTenant->id)->first();
        }

        // 2. Tenant picked in the switcher (still re-checked against memberships).
        if ($chosen = $request->session()->get('tenant_id')) {
            if ($m = (clone $active)->where('tenant_id', $chosen)->first()) {
                return $m;
            }
        }

        // 3. First active membership.
        return (clone $active)->orderByDesc('is_owner')->orderBy('id')->first();
    }

    protected function signOut(Request $request, string $message): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('error', $message);
    }
}
