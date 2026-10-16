<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use LogicException;

/** Append-only production history: actor, stage, time, notes, evidence (SRS FR-PRD-003). */
class ProductionEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const LABELS = [
        'created' => 'Planned', 'assigned' => 'Assigned', 'started' => 'Started', 'paused' => 'Put on hold', 'resumed' => 'Resumed',
        'completed' => 'Completed', 'reopened' => 'Reopened', 'note' => 'Note', 'blocked' => 'Waiting on other work',
        'unblocked' => 'Ready to start', 'qc_passed' => 'Passed quality check', 'qc_failed' => 'Failed quality check',
        'cancelled' => 'Cancelled', 'rescheduled' => 'Due date changed',
    ];

    protected $fillable = ['tenant_id', 'task_id', 'order_id', 'order_line_id', 'type', 'from_status', 'to_status', 'note', 'evidence_path', 'customer_visible', 'user_id'];

    protected $casts = ['customer_visible' => 'boolean'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Production events cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Production events cannot be deleted.'));
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProductionTask::class, 'task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getLabelAttribute(): string
    {
        return self::LABELS[$this->type] ?? ucfirst($this->type);
    }

    public function getEvidenceUrlAttribute(): ?string
    {
        return $this->evidence_path ? Storage::disk('public')->url($this->evidence_path) : null;
    }
}
