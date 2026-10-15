<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use App\Models\TenantMembership;
use Closure;

/**
 * Static shortcut to the TenantContext singleton.
 *
 *   CurrentTenant::id();          // int|null
 *   CurrentTenant::get();         // Tenant|null
 *   CurrentTenant::require();     // Tenant or exception
 *   CurrentTenant::run($t, fn () => ...);
 */
class CurrentTenant
{
    public static function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    public static function get(): ?Tenant
    {
        return static::context()->get();
    }

    public static function id(): ?int
    {
        return static::context()->id();
    }

    public static function check(): bool
    {
        return static::context()->check();
    }

    public static function require(): Tenant
    {
        return static::context()->require();
    }

    public static function membership(): ?TenantMembership
    {
        return static::context()->membership();
    }

    public static function run(Tenant $tenant, Closure $callback): mixed
    {
        return static::context()->run($tenant, $callback);
    }

    public static function isSingleMode(): bool
    {
        return static::context()->isSingleMode();
    }
}
