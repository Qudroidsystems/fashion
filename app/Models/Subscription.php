<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/** A fashion house's plan (one per house). Not tenant-scoped: the platform reads it across houses. */
class Subscription extends Model
{
    public const STATUSES = [
        'trialing' => ['Free trial', 'st-info'],
        'active'   => ['Active', 'st-success'],
        'past_due' => ['Payment failed', 'st-danger'],
    ];

    protected $fillable = [
        'tenant_id', 'plan_id', 'interval', 'status', 'trial_ends_at', 'current_period_start', 'current_period_end', 'cancel_at_period_end',
        'pending_plan_id', 'pending_interval', 'grace_ends_at', 'last_notice_at', 'failure_reason', 'authorization', 'card_label', 'billing_email',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime', 'current_period_start' => 'datetime', 'current_period_end' => 'datetime',
        'grace_ends_at' => 'datetime', 'last_notice_at' => 'datetime', 'cancel_at_period_end' => 'boolean',
    ];

    protected $hidden = ['authorization'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class, 'tenant_id', 'tenant_id')->latest('id');
    }

    /** The reusable Paystack card authorization, or null. */
    public function cardAuthorization(): ?array
    {
        try {
            return $this->authorization ? json_decode(Crypt::decryptString($this->authorization), true) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function getStatusPillAttribute(): string
    {
        return self::STATUSES[$this->status][1] ?? 'st-muted';
    }

    /** When the current paid time (or trial) runs out; null = never. */
    public function endsAt(): ?\Illuminate\Support\Carbon
    {
        return $this->status === 'trialing' ? $this->trial_ends_at : $this->current_period_end;
    }
}
