<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/** A fashion house using the platform (SRS FR-TEN-001). */
class Tenant extends Model
{
    use HasUlids, SoftDeletes;

    public const STATUSES = ['trial', 'active', 'past_due', 'suspended', 'cancelled'];

    protected $fillable = [
        'name', 'slug', 'domain', 'email', 'phone', 'address', 'timezone', 'locale',
        'currency_code', 'logo_path', 'brand_color', 'status', 'trial_ends_at',
        'grace_ends_at', 'settings', 'created_by',
    ];

    protected $casts = [
        'settings'      => 'array',
        'trial_ends_at' => 'datetime',
        'grace_ends_at' => 'datetime',
    ];

    /** The ULID column is the public id; the numeric id stays the primary key. */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function branches(): HasMany
    {
        // The explicit tenant_id foreign key already scopes these; drop the
        // "current tenant" scope so platform screens can count other tenants.
        return $this->hasMany(Branch::class)->withoutGlobalScope('tenant');
    }

    public function mainBranch(): HasOne
    {
        return $this->hasOne(Branch::class)->withoutGlobalScope('tenant')->where('is_main', true);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class)->withoutGlobalScope('tenant');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_memberships')
            ->withPivot(['status', 'is_owner', 'branch_id', 'job_title'])
            ->withTimestamps();
    }

    public function subscription(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /** Whether staff can sign in and work (SRS FR-TEN-005). */
    public function isUsable(): bool
    {
        if (! in_array($this->status, config('tenancy.usable_statuses', []), true)) {
            return false;
        }

        // With subscriptions on, an ended trial or unpaid renewal moves the house to the free plan
        // instead of locking it out (App\Services\Billing\SubscriptionService). Only the
        // platform can suspend or cancel a house.
        if (app(\App\Services\Billing\Entitlements::class)->enabled()) {
            return true;
        }

        if ($this->status === 'trial' && $this->trial_ends_at && $this->trial_ends_at->isPast()) {
            return false;
        }

        if ($this->status === 'past_due' && $this->grace_ends_at && $this->grace_ends_at->isPast()) {
            return false;
        }

        return true;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? asset('storage/' . $this->logo_path) : null;
    }

    public function getInitialsAttribute(): string
    {
        return collect(preg_split('/\s+/', trim($this->name)))
            ->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    }
}
