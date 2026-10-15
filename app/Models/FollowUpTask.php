<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A to-do about a customer: call back, invite to fitting, check in after delivery… */
class FollowUpTask extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'customer_id', 'title', 'details', 'due_at', 'assigned_to',
        'status', 'completed_at', 'completed_by', 'created_by',
    ];

    protected $casts = ['due_at' => 'datetime', 'completed_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', 'open');
    }

    public function scopeOverdue(Builder $q): Builder
    {
        return $q->open()->whereNotNull('due_at')->where('due_at', '<', now());
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->due_at && $this->due_at->isPast();
    }
}
