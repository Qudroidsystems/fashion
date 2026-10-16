<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One piece of work on a garment (SRS FR-PRD-002). Current state only — the
 * history lives in production_events. Alterations and rework are tasks too.
 */
class ProductionTask extends Model
{
    use BelongsToTenant, HasPublicId;

    public const STATUSES = [
        'blocked'     => ['Waiting', 'st-muted'],
        'pending'     => ['To do', 'st-info'],
        'in_progress' => ['In progress', 'st-warning'],
        'on_hold'     => ['On hold', 'st-danger'],
        'done'        => ['Done', 'st-success'],
        'cancelled'   => ['Cancelled', 'st-muted'],
    ];

    public const OPEN = ['blocked', 'pending', 'in_progress', 'on_hold'];

    public const PRIORITIES = ['normal' => 'Normal', 'high' => 'High', 'rush' => 'Rush'];

    public const RESPONSIBLE = ['customer' => 'Customer (changed mind / body change)', 'house' => 'House (measurement or design)', 'staff' => 'Workroom error', 'supplier' => 'Supplier / material fault'];

    public const COST_TREATMENT = ['free' => 'Free of charge', 'chargeable' => 'Charge the customer', 'absorbed' => 'Absorbed as a cost'];

    protected $fillable = [
        'tenant_id', 'number', 'order_id', 'order_line_id', 'stage_id', 'kind', 'name', 'type', 'position', 'duration_hours',
        'requires_previous', 'customer_visible', 'checklist_id', 'status', 'priority', 'assigned_to', 'role_id', 'due_date',
        'instructions', 'blocks_task_id', 'reason', 'responsible_party', 'cost_treatment', 'cost', 'resolution',
        'started_at', 'completed_at', 'completed_by', 'created_by',
    ];

    protected $casts = [
        'due_date' => 'date', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'requires_previous' => 'boolean',
        'customer_visible' => 'boolean', 'cost' => 'float', 'duration_hours' => 'integer', 'position' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProductionStage::class, 'stage_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(QualityChecklist::class, 'checklist_id');
    }

    public function blocksTask(): BelongsTo
    {
        return $this->belongsTo(self::class, 'blocks_task_id');
    }

    public function blockers(): HasMany
    {
        return $this->hasMany(self::class, 'blocks_task_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProductionEvent::class, 'task_id')->orderByDesc('id');
    }

    public function qualityChecks(): HasMany
    {
        return $this->hasMany(QualityCheck::class, 'task_id')->latest('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_date && $this->due_date->endOfDay()->isPast();
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function getStatusPillAttribute(): string
    {
        return self::STATUSES[$this->status][1] ?? 'st-muted';
    }

    public function getKindLabelAttribute(): ?string
    {
        return ['alteration' => 'Alteration', 'rework' => 'Rework'][$this->kind] ?? null;
    }

    public function scopeOpen($q)
    {
        return $q->whereIn('status', self::OPEN);
    }
}
