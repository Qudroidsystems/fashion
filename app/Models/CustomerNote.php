<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Free-text customer note. Sensitive notes need "View customer sensitive notes" (FR-CRM-006). */
class CustomerNote extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['tenant_id', 'customer_id', 'body', 'is_sensitive', 'is_pinned', 'created_by'];

    protected $casts = ['is_sensitive' => 'boolean', 'is_pinned' => 'boolean'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Hide sensitive notes from users without the permission. */
    public function scopeVisibleTo(Builder $q, ?User $user): Builder
    {
        return $user?->can('View customer sensitive notes') ? $q : $q->where('is_sensitive', false);
    }
}
