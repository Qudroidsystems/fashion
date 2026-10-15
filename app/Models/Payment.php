<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Money received from a customer (SRS FR-ORD-006/007). Never edited; reversed if it bounces. */
class Payment extends Model
{
    use BelongsToTenant, HasPublicId;

    public const METHODS = [
        'cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'card' => 'Card / POS terminal',
        'mobile_money' => 'Mobile money', 'cheque' => 'Cheque', 'other' => 'Other',
    ];

    protected $fillable = [
        'tenant_id', 'number', 'customer_id', 'branch_id', 'amount', 'method', 'reference', 'status', 'received_at', 'notes',
        'received_by', 'reversed_at', 'reversed_by', 'reversal_reason',
    ];

    protected $casts = ['amount' => 'float', 'received_at' => 'datetime', 'reversed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (Payment $p) {
            $allowed = ['status', 'reversed_at', 'reversed_by', 'reversal_reason', 'updated_at'];
            if (array_diff(array_keys($p->getDirty()), $allowed) || ($p->isDirty('status') && $p->status !== 'reversed')) {
                throw new \LogicException('Payments cannot be edited. Reverse it and record it again.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Payments cannot be deleted. Reverse it instead.'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** Net amount applied to orders / invoices. */
    public function allocated(): float
    {
        return round((float) $this->allocations()->sum('amount'), 2);
    }

    /** Money refunded to the customer from this payment (paid refunds). */
    public function refunded(): float
    {
        return round((float) $this->refunds()->where('status', 'paid')->sum('amount'), 2);
    }

    /** Held for the customer, not yet applied anywhere (their credit). */
    public function unallocated(): float
    {
        if ($this->status === 'reversed') {
            return 0.0;
        }
        // Refunds from an order already reduced the allocations (negative rows),
        // so every paid refund is simply money that left: amount − applied − refunded.
        return round($this->amount - $this->allocated() - $this->refunded(), 2);
    }

    public function getMethodLabelAttribute(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }
}
