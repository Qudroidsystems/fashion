<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A dated checkpoint; the client sees it only once published (FR-PRJ-005, scenario 39). */
class ProjectMilestone extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'project_id', 'name', 'due_date', 'status', 'notes', 'customer_visible', 'published_at', 'published_by',
        'completed_at', 'completed_by', 'sort_order',
    ];

    protected $casts = ['due_date' => 'date', 'customer_visible' => 'boolean', 'published_at' => 'datetime', 'completed_at' => 'datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isPublished(): bool
    {
        return $this->customer_visible && $this->published_at !== null;
    }

    public function isLate(): bool
    {
        return $this->status === 'pending' && $this->due_date && $this->due_date->isPast() && ! $this->due_date->isToday();
    }
}
