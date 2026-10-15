<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPricedLines;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One garment / item on an order (SRS FR-ORD-002). */
class OrderLine extends Model
{
    use BelongsToTenant, HasPricedLines;

    public const STATUSES = [
        'pending' => ['Not started', 'st-muted'], 'in_production' => ['In production', 'st-violet'],
        'ready' => ['Ready', 'st-success'], 'delivered' => ['Delivered', 'st-success'], 'cancelled' => ['Cancelled', 'st-danger'],
    ];

    protected $fillable = ['order_id', 'measurement_session_id', 'assigned_to', 'wearer', 'status'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function measurementSession(): BelongsTo
    {
        return $this->belongsTo(MeasurementSession::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
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
