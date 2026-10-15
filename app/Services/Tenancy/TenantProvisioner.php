<?php

namespace App\Services\Tenancy;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\PermissionCatalog;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates a fashion house: tenant row, main branch, default roles and the
 * owner's membership (SRS FR-TEN-001, roadmap phase 1 "tenant can be
 * onboarded and configured securely").
 */
class TenantProvisioner
{
    public function create(array $data, User $owner): Tenant
    {
        return DB::transaction(function () use ($data, $owner) {
            $tenant = Tenant::create([
                'name'          => $data['name'],
                'slug'          => $data['slug'] ?? $this->uniqueSlug($data['name']),
                'email'         => $data['email'] ?? $owner->email,
                'phone'         => $data['phone'] ?? null,
                'address'       => $data['address'] ?? null,
                'timezone'      => $data['timezone'] ?? config('tenancy.defaults.timezone'),
                'locale'        => $data['locale'] ?? config('tenancy.defaults.locale'),
                'currency_code' => $data['currency_code'] ?? config('tenancy.defaults.currency'),
                'status'        => $data['status'] ?? 'trial',
                'trial_ends_at' => ($data['status'] ?? 'trial') === 'trial'
                    ? now()->addDays(config('tenancy.trial_days', 14)) : null,
                'settings'      => $data['settings'] ?? $this->defaultSettings(),
                'created_by'    => auth()->id() ?? $owner->id,
            ]);

            CurrentTenant::run($tenant, function (Tenant $tenant) use ($owner, $data) {
                $branch = Branch::create([
                    'name'    => $data['branch_name'] ?? 'Main branch',
                    'code'    => 'MAIN',
                    'type'    => 'branch',
                    'is_main' => true,
                    'address' => $data['address'] ?? null,
                    'phone'   => $data['phone'] ?? null,
                ]);

                $this->syncDefaultRoles($tenant);
                app(\App\Services\Measurements\MeasurementStarterKit::class)->install($tenant);
                \App\Services\Catalogue\CatalogueStarter::install($tenant);

                TenantMembership::create([
                    'user_id'   => $owner->id,
                    'branch_id' => $branch->id,
                    'status'    => 'active',
                    'is_owner'  => true,
                    'joined_at' => now(),
                ]);

                $owner->unsetRelation('roles');
                $owner->assignRole(PermissionCatalog::OWNER_ROLE);

                AuditLogger::log('tenant.created', $tenant, after: $tenant->only(['name', 'slug', 'status']), tenantId: $tenant->id);
            });

            return $tenant;
        });
    }

    /**
     * Create any missing default roles for a tenant. Existing roles keep
     * whatever permissions the owner has given them, except Owner, which
     * always has every permission.
     */
    public function syncDefaultRoles(Tenant $tenant): void
    {
        if (Permission::where('guard_name', 'web')->count() < count(PermissionCatalog::all())) {
            static::syncPermissionCatalog();
        }

        CurrentTenant::run($tenant, function (Tenant $tenant) {
            foreach (PermissionCatalog::DEFAULT_ROLES as $name => $def) {
                $role = Role::where('tenant_id', $tenant->id)->where('name', $name)->where('guard_name', 'web')->first();

                if (! $role) {
                    $role = Role::create([
                        'name'        => $name,
                        'guard_name'  => 'web',
                        'tenant_id'   => $tenant->id,
                        'badge'       => $def['badge'],
                        'description' => $def['description'],
                        'is_system'   => true,
                    ]);
                    $role->syncPermissions(PermissionCatalog::permissionsFor($name));
                } elseif ($name === PermissionCatalog::OWNER_ROLE) {
                    $role->syncPermissions(PermissionCatalog::permissionsFor($name));
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Make sure every catalogue permission exists (safe to run repeatedly). */
    public static function syncPermissionCatalog(): void
    {
        foreach (PermissionCatalog::GROUPS as $group => $names) {
            foreach ($names as $name) {
                Permission::updateOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    ['title' => $group]
                );
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'house';
        $slug = $base;
        $i = 2;

        while (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    protected function defaultSettings(): array
    {
        return [
            'measurement_display_unit' => 'in',            // storage is canonical millimetres (SRS §3.3)
            'measurement_change_threshold_percent' => 5,  // flag a length that moves more than this
            'measurement_review_months' => 6,              // remind to re-measure after this long
            'allow_negative_stock' => false,                // FR-INV-004: block issues/sales beyond on-hand
            'stock_approval_threshold' => 20000,            // §9: adjustments worth more need a second person
            'po_approval_threshold' => 100000,              // POs above this need someone other than the requester
            'numbering' => [
                'customer'  => 'CUS-{SEQ:5}',
                'order'     => 'ORD-{YYYY}-{SEQ:5}',
                'invoice'   => 'INV-{YYYY}-{SEQ:5}',
                'receipt'   => 'RCP-{YYYY}-{SEQ:5}',
                'quotation' => 'QTE-{YYYY}-{SEQ:5}',
            ],
        ];
    }
}
