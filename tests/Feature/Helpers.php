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

    return [$tenant, $owner->fresh()];
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
