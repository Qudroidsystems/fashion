<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Snapshot of an order's lines before a change made after confirmation (SRS FR-ORD-001 "order revisions"). */
class OrderRevision extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'order_id', 'revision', 'reason', 'total_before', 'total_after', 'lines_before', 'user_id'];

    protected $casts = ['lines_before' => 'array', 'total_before' => 'float', 'total_after' => 'float'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
