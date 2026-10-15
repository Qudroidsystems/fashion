<?php

use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Support\Facades\Artisan;

/*
| Re-sync the permission catalogue and every tenant's default roles after
| adding permissions to App\Support\PermissionCatalog.
*/
Artisan::command('fhmp:sync-permissions', function (TenantProvisioner $provisioner) {
    TenantProvisioner::syncPermissionCatalog();

    \App\Models\Tenant::query()->each(function ($tenant) use ($provisioner) {
        $provisioner->syncDefaultRoles($tenant);
        $this->line("  synced roles for {$tenant->name}");
    });

    $this->info('Permission catalogue and default roles are up to date.');
})->purpose('Sync permissions and default roles for every fashion house');

/*
| Scheduled jobs (birthdays, measurement reviews, fittings, overdue payments…)
| are registered here with Schedule::command(...) as the modules land.
| They must run per tenant, in the tenant's timezone (SRS FR-NOT-006).
*/
