<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use App\Models\TenantMembership;
use Closure;
use RuntimeException;

/**
 * Holds the fashion house (tenant) the current request, job or command is
 * working in. Set by ResolveTenant / ResolveApiTenant from the user's ACTIVE
 * membership — never from a tenant id the client sends (SRS FR-TEN-003).
 *
 * Registered as a singleton; use the CurrentTenant facade-style helper for
 * convenience: CurrentTenant::id(), CurrentTenant::get(), …
 */
class TenantContext
{
    protected ?Tenant $tenant = null;

    protected ?TenantMembership $membership = null;

    public function set(Tenant $tenant, ?TenantMembership $membership = null): void
    {
        $this->tenant = $tenant;
        $this->membership = $membership;

        // Spatie "teams": roles and permissions are evaluated inside this tenant.
        setPermissionsTeamId($tenant->getKey());
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->membership = null;
        setPermissionsTeamId(null);
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    public function membership(): ?TenantMembership
    {
        return $this->membership;
    }

    /** The current tenant, or an exception when there is none (use in code that must be tenant-scoped). */
    public function require(): Tenant
    {
        if (! $this->tenant) {
            throw new RuntimeException('No tenant context. Tenant-owned data cannot be read or written outside a tenant.');
        }

        return $this->tenant;
    }

    /**
     * Run a callback inside another tenant (queued jobs, seeders, platform tools),
     * then restore whatever was active before.
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        [$previousTenant, $previousMembership] = [$this->tenant, $this->membership];

        $this->set($tenant);

        try {
            return $callback($tenant);
        } finally {
            $previousTenant ? $this->set($previousTenant, $previousMembership) : $this->clear();
        }
    }

    public function isSingleMode(): bool
    {
        return config('tenancy.mode') === 'single';
    }
}
