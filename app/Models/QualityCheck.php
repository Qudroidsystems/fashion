<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A completed checklist on a garment: pass/fail, reviewer, issues, rework (SRS FR-PRD-006). */
class QualityCheck extends Model
{
    use BelongsToTenant, HasPublicId;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'order_id', 'order_line_id', 'task_id', 'checklist_id', 'results', 'result', 'issues', 'rework_task_id', 'reviewer_id'];

    protected $casts = ['results' => 'array'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProductionTask::class, 'task_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }

    public function reworkTask(): BelongsTo
    {
        return $this->belongsTo(ProductionTask::class, 'rework_task_id');
    }
}
