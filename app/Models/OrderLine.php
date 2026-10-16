<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPricedLines;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One garment / item on an order (SRS FR-ORD-002). */
class OrderLine extends Model
{
    use BelongsToTenant, HasPricedLines;

    public const STATUSES = [
        'pending' => ['Not started', 'st-muted'], 'in_production' => ['In production', 'st-violet'],
        'ready' => ['Ready', 'st-success'], 'delivered' => ['Delivered', 'st-success'], 'cancelled' => ['Cancelled', 'st-danger'],
    ];

    protected $fillable = ['order_id', 'participant_id', 'measurement_session_id', 'assigned_to', 'wearer', 'status'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(ProjectParticipant::class, 'participant_id');
    }

    public function measurementSession(): BelongsTo
    {
        return $this->belongsTo(MeasurementSession::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** Production tasks for this garment, in workflow order (stages first, then alterations/rework by time). */
    public function tasks(): HasMany
    {
        return $this->hasMany(ProductionTask::class)->orderBy('position')->orderBy('id');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function getStatusPillAttribute(): string
    {
        return self::STATUSES[$this->status][1] ?? 'st-muted';
    }
}
