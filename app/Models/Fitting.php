<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A fitting appointment (SRS FR-PRD-005). */
class Fitting extends Model
{
    use BelongsToTenant, HasPublicId;

    public const STATUSES = [
        'scheduled'   => ['Booked', 'st-info'],
        'attended'    => ['Attended', 'st-success'],
        'no_show'     => ['Did not come', 'st-danger'],
        'cancelled'   => ['Cancelled', 'st-muted'],
        'rescheduled' => ['Moved', 'st-muted'],
    ];

    protected $fillable = [
        'tenant_id', 'number', 'customer_id', 'order_id', 'order_line_id', 'task_id', 'branch_id', 'staff_id', 'scheduled_at',
        'duration_minutes', 'status', 'outcome', 'notes', 'rescheduled_to_id', 'created_by', 'recorded_by', 'recorded_at',
    ];

    protected $casts = ['scheduled_at' => 'datetime', 'recorded_at' => 'datetime', 'duration_minutes' => 'integer'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProductionTask::class, 'task_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function rescheduledTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_to_id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(FittingReminder::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function getStatusPillAttribute(): string
    {
        return self::STATUSES[$this->status][1] ?? 'st-muted';
    }

    public function isUpcoming(): bool
    {
        return $this->status === 'scheduled';
    }
}
