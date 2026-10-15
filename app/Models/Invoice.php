<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bill to the customer. For an order it is a request for part of the order
 * (deposit / balance); without an order it is a charge in its own right.
 * Issued invoices are frozen; corrections are voids (FR-ORD-009).
 */
class Invoice extends Model
{
    use BelongsToTenant, HasPublicId;

    public const KINDS = ['deposit' => 'Deposit', 'balance' => 'Balance', 'full' => 'Full', 'other' => 'Invoice'];

    protected $fillable = [
        'tenant_id', 'number', 'customer_id', 'order_id', 'kind', 'status', 'issue_date', 'due_date', 'total', 'notes',
        'void_reason', 'issued_at', 'voided_at', 'created_by',
    ];

    protected $casts = ['issue_date' => 'date', 'due_date' => 'date', 'issued_at' => 'datetime', 'voided_at' => 'datetime', 'total' => 'float'];

    protected static function booted(): void
    {
        static::updating(function (Invoice $i) {
            if ($i->getOriginal('status') !== 'draft') {
                $allowed = ['status', 'void_reason', 'voided_at', 'updated_at'];
                if (array_diff(array_keys($i->getDirty()), $allowed) || ($i->isDirty('status') && $i->status !== 'void')) {
                    throw new \LogicException('Issued invoices cannot be changed. Void it and issue a new one.');
                }
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function paid(): float
    {
        return round((float) PaymentAllocation::where('invoice_id', $this->id)->sum('amount'), 2);
    }

    public function balance(): float
    {
        return $this->status === 'issued' ? round($this->total - $this->paid(), 2) : 0.0;
    }

    public function getStatusLabelAttribute(): string
    {
        if ($this->status === 'issued') {
            $bal = $this->balance();

            return $bal <= 0 ? 'Paid' : ($bal < $this->total ? 'Part paid' : ($this->due_date?->endOfDay()->isPast() ? 'Overdue' : 'Unpaid'));
        }

        return ucfirst($this->status);
    }

    public function getStatusPillAttribute(): string
    {
        return ['Paid' => 'st-success', 'Part paid' => 'st-violet', 'Overdue' => 'st-danger', 'Unpaid' => 'st-warning', 'Void' => 'st-muted', 'Draft' => 'st-muted'][$this->status_label] ?? 'st-muted';
    }
}
