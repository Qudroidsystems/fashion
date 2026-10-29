<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer coming in (or being visited) for something other than a fitting. */
class Appointment extends Model
{
    use BelongsToTenant, HasPublicId;

    public const TYPES = [
        'consultation' => 'Consultation', 'measurement' => 'Measurement', 'collection' => 'Collection',
        'delivery' => 'Delivery / home visit', 'other' => 'Other',
    ];

    /** status => [label, pill] */
    public const STATUSES = [
        'booked' => ['Booked', 'st-info'], 'done' => ['Done', 'st-success'], 'no_show' => ['Didn’t come', 'st-warning'], 'cancelled' => ['Cancelled', 'st-muted'],
    ];

    protected $fillable = [
        'tenant_id', 'number', 'customer_id', 'order_id', 'branch_id', 'staff_id', 'type', 'starts_at', 'duration_minutes',
        'status', 'notes', 'outcome', 'cancel_reason', 'created_by',
    ];

    protected $casts = ['starts_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getEndsAtAttribute()
    {
        return $this->starts_at->copy()->addMinutes($this->duration_minutes);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function getStatusPillAttribute(): string
    {
        return self::STATUSES[$this->status][1] ?? 'st-muted';
    }
}
