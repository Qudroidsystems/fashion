<?php

namespace App\Models;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * A person who signs in. Users are global; the fashion houses they work in
 * come from tenant_memberships, and their roles are per tenant (Spatie teams).
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'phone_number',
        'gender',
        'avatar',
        'is_disabled',
        'must_change_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at'    => 'datetime',
        'last_login_at'        => 'datetime',
        'last_seen_at'         => 'datetime',
        'is_platform_admin'    => 'boolean',
        'is_disabled'          => 'boolean',
        'must_change_password' => 'boolean',
        'password'             => 'hashed',
    ];

    /* ------------------------------------------------------------------
     | Tenancy
     * ------------------------------------------------------------------ */

    public function memberships(): HasMany
    {
        // Memberships across every tenant (not limited by the current tenant scope).
        return $this->hasMany(TenantMembership::class)->withoutGlobalScope('tenant');
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_memberships')
            ->withPivot(['status', 'is_owner', 'branch_id', 'job_title'])
            ->withTimestamps();
    }

    /** Tenants this user can currently work in. */
    public function activeTenants(): BelongsToMany
    {
        return $this->tenants()->wherePivot('status', 'active');
    }

    public function membershipFor(Tenant|int|null $tenant = null): ?TenantMembership
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : ($tenant ?? CurrentTenant::id());

        return $tenantId ? $this->memberships()->where('tenant_id', $tenantId)->first() : null;
    }

    public function isMemberOf(Tenant|int $tenant): bool
    {
        return (bool) $this->membershipFor($tenant)?->isActive();
    }

    /** Users that belong to the current tenant (any membership status). */
    public function scopeInCurrentTenant(Builder $query): Builder
    {
        $tenantId = CurrentTenant::require()->getKey();

        return $query->whereHas('memberships', fn ($q) => $q->where('tenant_id', $tenantId));
    }

    public function isOwnerOfCurrentTenant(): bool
    {
        return (bool) $this->membershipFor()?->is_owner;
    }

    /* ------------------------------------------------------------------
     | Presentation
     * ------------------------------------------------------------------ */

    public function getFirstNameAttribute(): string
    {
        return explode(' ', trim($this->name))[0] ?? '';
    }

    public function getLastNameAttribute(): string
    {
        $parts = explode(' ', trim($this->name));

        return count($parts) > 1 ? end($parts) : '';
    }

    public function getInitialsAttribute(): string
    {
        return collect(preg_split('/\s+/', trim($this->name)))
            ->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    }

    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar && Storage::disk('public')->exists('avatars/' . basename($this->avatar))) {
            return Storage::disk('public')->url('avatars/' . basename($this->avatar));
        }

        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&color=7F9CF5&background=EBF4FF';
    }

    public function hasCustomAvatar(): bool
    {
        return $this->avatar && Storage::disk('public')->exists('avatars/' . basename($this->avatar));
    }

    public function isActive(): bool
    {
        return ! $this->is_disabled;
    }
}
