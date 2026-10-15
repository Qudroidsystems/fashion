<?php

namespace Database\Seeders;

use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Database\Seeder;

/** Creates/updates every permission in App\Support\PermissionCatalog. Safe to re-run. */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        TenantProvisioner::syncPermissionCatalog();
    }
}
