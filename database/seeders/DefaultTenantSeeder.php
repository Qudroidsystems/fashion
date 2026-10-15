<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Database\Seeder;

/**
 * The first fashion house and its Owner account.
 *
 *   single mode → this is THE fashion house of the installation;
 *   multi mode  → the first tenant, and the Owner is also a platform operator.
 *
 * Credentials come from .env (FHMP_ADMIN_*). Change the password after the first sign-in.
 */
class DefaultTenantSeeder extends Seeder
{
    public function run(TenantProvisioner $provisioner): void
    {
        $slug = config('tenancy.default_tenant', 'default');

        $owner = User::firstOrCreate(
            ['email' => env('FHMP_ADMIN_EMAIL', 'admin@fashionhouse.test')],
            [
                'name'     => env('FHMP_ADMIN_NAME', 'House Owner'),
                'password' => env('FHMP_ADMIN_PASSWORD', 'password'),
            ]
        );
        $owner->forceFill(['is_platform_admin' => config('tenancy.mode') !== 'single'])->save();

        $tenant = Tenant::where('slug', $slug)->first();

        if (! $tenant) {
            $tenant = $provisioner->create([
                'name'   => env('FHMP_HOUSE_NAME', 'My Fashion House'),
                'slug'   => $slug,
                'status' => 'active',
            ], $owner);
        } else {
            $provisioner->syncDefaultRoles($tenant);
        }

        $this->command?->info("Fashion house: {$tenant->name} ({$tenant->slug})");
        $this->command?->info("Owner sign-in: {$owner->email} / " . (env('FHMP_ADMIN_PASSWORD') ? '(FHMP_ADMIN_PASSWORD)' : 'password'));
    }
}
