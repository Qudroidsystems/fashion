<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A user's place in a fashion house: status, home branch, owner flag (SRS FR-IAM-001). */
class TenantMembership extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['invited', 'active', 'suspended'];

    protected $fillable = [
        'tenant_id', 'user_id', 'branch_id', 'status', 'is_owner', 'job_title',
        'staff_number', 'invited_at', 'joined_at', 'invited_by',
    ];

    protected $casts = [
        'is_owner'   => 'boolean',
        'invited_at' => 'datetime',
        'joined_at'  => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
