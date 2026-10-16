<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Tenancy\CurrentTenant;

/** Create a fashion house with an Owner; returns [Tenant, User owner]. */
function makeHouse(string $name = 'House A', array $attrs = []): array
{
    TenantProvisioner::syncPermissionCatalog();

    $owner = User::factory()->create(['name' => "$name Owner"]);
    $tenant = app(TenantProvisioner::class)->create(['name' => $name, 'status' => 'active'] + $attrs, $owner);
    CurrentTenant::context()->clear();

    // Test houses get the top plan, so module tests aren't affected by plan limits;
    // BillingTest sets plans explicitly with onPlan().
    onPlan($tenant, \App\Models\Plan::ordered()->get()->last()?->code);

    return [$tenant, $owner->fresh()];
}

/** Put a house on a plan (by code) directly, as the platform would. */
function onPlan(Tenant $tenant, ?string $code, string $status = 'active'): void
{
    if (! $code) {
        return;
    }
    \App\Models\Subscription::updateOrCreate(['tenant_id' => $tenant->id], [
        'plan_id' => \App\Models\Plan::where('code', $code)->value('id'), 'status' => $status, 'interval' => 'monthly',
        'current_period_start' => now(), 'current_period_end' => null, 'trial_ends_at' => $status === 'trialing' ? now()->addDays(14) : null,
    ]);
    app(\App\Services\Billing\Entitlements::class)->forget();
}

/** Add a staff member with the given role to a house. */
function addStaff(Tenant $tenant, string $role, array $attrs = []): User
{
    $user = User::factory()->create($attrs);

    CurrentTenant::run($tenant, function () use ($user, $role) {
        \App\Models\TenantMembership::create(['user_id' => $user->id, 'status' => 'active', 'joined_at' => now()]);
        $user->assignRole($role);
    });
    CurrentTenant::context()->clear();

    return $user->fresh();
}
