<?php

namespace App\Models;

use App\Support\PermissionCatalog;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Roles belong to a fashion house (tenant_id). Seeded default roles are
 * flagged is_system.
 *
 * NOTE: there is deliberately no global "current tenant" scope here. Spatie
 * builds one shared permission cache (permission → roles of every tenant);
 * a global scope would poison that cache with whichever tenant built it.
 * Spatie already filters role checks by the current team id, so:
 *   - in app code, query with Role::ofCurrentTenant();
 *   - {role} URL parameters are resolved within the current tenant (below).
 */
class Role extends SpatieRole
{
    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function scopeOfCurrentTenant(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('tenant_id'), CurrentTenant::require()->getKey());
    }

    /** /roles/{role} only ever finds a role of the current fashion house. */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->ofCurrentTenant()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    public function isOwnerRole(): bool
    {
        return $this->name === PermissionCatalog::OWNER_ROLE;
    }
}
