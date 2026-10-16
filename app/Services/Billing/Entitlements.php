<?php

namespace App\Services\Billing;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Support\Billing\Features;
use App\Support\Money;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * What a fashion house's plan allows. Single-house installs, and installs
 * with billing switched off, get everything.
 *
 * Limits stop new additions, never access: a house over its limit keeps every
 * record and can still read and export (if its plan exports) what it has.
 */
class Entitlements
{
    protected array $plans = [];

    public function enabled(): bool
    {
        return config('tenancy.mode') !== 'single' && (bool) config('billing.enabled', true);
    }

    public function subscription(?Tenant $tenant): ?Subscription
    {
        return $tenant ? Subscription::where('tenant_id', $tenant->id)->first() : null;
    }

    /** The plan in force: the subscription's, else the free plan. */
    public function plan(?Tenant $tenant): ?Plan
    {
        if (! $tenant) {
            return null;
        }

        return $this->plans[$tenant->id] ??= (Subscription::where('tenant_id', $tenant->id)->with('plan')->first()?->plan ?? Plan::free());
    }

    public function forget(?Tenant $tenant = null): void
    {
        $tenant ? Arr::forget($this->plans, $tenant->id) : $this->plans = [];
    }

    public function has(?Tenant $tenant, string $feature): bool
    {
        if (! $this->enabled() || ! $tenant) {
            return true;
        }
        $plan = $this->plan($tenant);

        return ! $plan || $plan->has($feature);   // no plans set up yet: don't block anyone
    }

    public function limit(?Tenant $tenant, string $key): ?int
    {
        return $this->enabled() && $tenant ? $this->plan($tenant)?->limit($key) : null;
    }

    public function usage(Tenant $tenant, string $key): int
    {
        return match ($key) {
            'customers' => Customer::withoutTenantScope()->where('tenant_id', $tenant->id)->where('status', '!=', 'merged')->count(),
            'staff'     => TenantMembership::withoutTenantScope()->where('tenant_id', $tenant->id)->where('status', 'active')->count(),
            'branches'  => Branch::withoutTenantScope()->where('tenant_id', $tenant->id)->whereNull('deleted_at')->count(),
            default     => 0,
        };
    }

    /** [used, limit|null, percent|null] for each limit. */
    public function usageSummary(Tenant $tenant): array
    {
        return collect(Features::LIMITS)->mapWithKeys(function ($labels, $key) use ($tenant) {
            $limit = $this->limit($tenant, $key);
            $used = $this->usage($tenant, $key);

            return [$key => ['label' => $labels[0], 'used' => $used, 'limit' => $limit, 'percent' => $limit ? min(100, (int) floor(100 * $used / max(1, $limit))) : null]];
        })->all();
    }

    public function canAdd(Tenant $tenant, string $key, int $count = 1): bool
    {
        $limit = $this->limit($tenant, $key);

        return $limit === null || $this->usage($tenant, $key) + $count <= $limit;
    }

    /** Throws a friendly validation error when a limit is reached. */
    public function assertCanAdd(?Tenant $tenant, string $key, int $count = 1): void
    {
        if (! $tenant || $this->canAdd($tenant, $key, $count)) {
            return;
        }
        $plan = $this->plan($tenant);
        $limit = $this->limit($tenant, $key);
        [, $one, $many] = Features::LIMITS[$key];
        $next = $this->upgradeFor($tenant, fn (Plan $p) => $p->limit($key) === null || $p->limit($key) > $limit);
        throw ValidationException::withMessages(['plan_limit' => sprintf(
            'Your %s plan allows %d %s. %s',
            $plan->name, $limit, $limit === 1 ? $one : $many,
            $next ? "Upgrade to {$next->name} to add more." : 'Contact us to add more.'
        )]);
    }

    /** The cheapest active plan above the current one that passes $test. */
    public function upgradeFor(Tenant $tenant, callable $test): ?Plan
    {
        $current = $this->plan($tenant);

        return Plan::where('is_active', true)->ordered()->get()
            ->filter(fn (Plan $p) => ! $current || $p->sort_order > $current->sort_order)
            ->first(fn (Plan $p) => $test($p));
    }

    public function upgradeMessage(Tenant $tenant, string $feature): string
    {
        $next = $this->upgradeFor($tenant, fn (Plan $p) => $p->has($feature));

        return Features::label($feature) . ' is not included in your ' . ($this->plan($tenant)?->name ?? 'current') . ' plan'
            . ($next ? '. Upgrade to ' . $next->name . ($next->price_monthly > 0 ? ' (from ' . Money::format($next->price_monthly) . ' a month)' : '') . ' to use it.' : '.');
    }

    /** The feature a route needs (config billing.route_features), or null. */
    public function featureForRoute(?string $routeName): ?string
    {
        if (! $routeName) {
            return null;
        }
        foreach (config('billing.route_features', []) as $feature => $patterns) {
            foreach ($patterns as $pattern) {
                if (\Illuminate\Support\Str::is($pattern, $routeName)) {
                    return $feature;
                }
            }
        }

        return null;
    }
}
