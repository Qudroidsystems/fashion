<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Add to every tenant-owned business model (customers, orders, stock, …).
 *
 *  - Reads are limited to the current tenant by a global scope.
 *  - New records get the current tenant's id automatically; creating one
 *    with no tenant context throws instead of silently writing a NULL.
 *  - A record can never be moved to another tenant.
 *
 * Remember the database side too: tenant_id column, foreign key, and
 * composite unique keys such as UNIQUE(tenant_id, order_number) (SRS §7.1).
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            if ($tenantId = CurrentTenant::id()) {
                $query->where($query->qualifyColumn('tenant_id'), $tenantId);
            }
        });

        static::creating(function ($model) {
            if (empty($model->tenant_id)) {
                $model->tenant_id = CurrentTenant::require()->getKey();
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('tenant_id') && $model->getOriginal('tenant_id') !== null) {
                throw new \LogicException('A record cannot be moved to another tenant.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Query across all tenants — platform tools only, never in tenant screens. */
    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope('tenant');
    }
}
