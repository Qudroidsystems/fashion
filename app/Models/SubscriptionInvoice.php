<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One subscription charge (platform billing). */
class SubscriptionInvoice extends Model
{
    public const STATUSES = ['pending' => ['Waiting for payment', 'st-warning'], 'paid' => ['Paid', 'st-success'], 'failed' => ['Failed', 'st-danger'], 'void' => ['Void', 'st-muted']];

    protected $fillable = [
        'tenant_id', 'number', 'plan_id', 'interval', 'reason', 'amount', 'currency', 'status', 'reference', 'gateway', 'channel',
        'period_start', 'period_end', 'paid_at', 'failure_reason', 'attempts', 'created_by',
    ];

    protected $casts = ['amount' => 'float', 'period_start' => 'datetime', 'period_end' => 'datetime', 'paid_at' => 'datetime'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
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
