<?php

namespace App\Http\Middleware;

use App\Services\Billing\Entitlements;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plan features (config billing.route_features). Without the feature a page
 * still opens read-only, with an upgrade bar; saving is refused. The staff
 * mobile app (the API) needs the "mobile_app" feature.
 */
class EnforcePlan
{
    public function __construct(protected Entitlements $entitlements)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        View::share('planLock', null);   // never carry a previous request's bar over
        $tenant = CurrentTenant::get();
        if (! $tenant || ! $this->entitlements->enabled()) {
            return $next($request);
        }

        if ($request->is('api/*') && ! $this->entitlements->has($tenant, 'mobile_app')) {
            return $this->refuse($request, 'mobile_app', $this->entitlements->upgradeMessage($tenant, 'mobile_app'));
        }

        $feature = $this->entitlements->featureForRoute($request->route()?->getName());
        if (! $feature || $this->entitlements->has($tenant, $feature)) {
            return $next($request);
        }
        $message = $this->entitlements->upgradeMessage($tenant, $feature);

        if ($request->isMethodSafe() && ! in_array($feature, config('billing.block_reads', []), true)) {
            View::share('planLock', ['feature' => $feature, 'message' => $message]);

            return $next($request);
        }

        return $this->refuse($request, $feature, $message);
    }

    protected function refuse(Request $request, string $feature, string $message): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['success' => false, 'code' => 'plan_feature_locked', 'feature' => $feature, 'message' => $message], 403);
        }
        if ($request->isMethodSafe()) {
            return redirect()->route('billing.index')->withErrors(['plan' => $message]);
        }

        return back()->withErrors(['plan' => $message])->withInput();
    }
}
