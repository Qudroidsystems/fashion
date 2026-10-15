<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use App\Support\Orders\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer order with garment lines (SRS FR-ORD-001/002/003).
 * What the customer owes is derived: total − allocations (FR-ORD-006).
 */
class Order extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = [
        'tenant_id', 'number', 'customer_id', 'branch_id', 'quotation_id', 'status', 'title', 'event_date', 'promised_date',
        'priority', 'subtotal', 'discount_total', 'order_discount', 'total', 'deposit_required', 'customer_notes',
        'internal_notes', 'owner_id', 'revision', 'confirmed_at', 'delivered_at', 'cancelled_at', 'cancel_reason', 'created_by',
    ];

    protected $casts = [
        'event_date' => 'date', 'promised_date' => 'date', 'confirmed_at' => 'datetime', 'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime', 'subtotal' => 'float', 'discount_total' => 'float', 'order_discount' => 'float',
        'total' => 'float', 'deposit_required' => 'float', 'revision' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(OrderMaterialRequirement::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderStatusEvent::class)->latest('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(OrderRevision::class)->latest('id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function materialIssues(): HasMany
    {
        return $this->hasMany(MaterialIssue::class);
    }

    /** What the customer is charged: nothing for drafts or cancelled orders. */
    public function chargeable(): float
    {
        return in_array($this->status, ['draft', 'cancelled'], true) ? 0.0 : $this->total;
    }

    /** Net money applied to this order and its invoices. */
    public function paid(): float
    {
        return round((float) PaymentAllocation::where(fn ($q) => $q->where('order_id', $this->id)
            ->orWhereIn('invoice_id', Invoice::where('order_id', $this->id)->select('id')))->sum('amount'), 2);
    }

    public function balance(): float
    {
        return round($this->chargeable() - $this->paid(), 2);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, OrderStatus::EDITABLE, true);
    }

    public function isLate(): bool
    {
        return $this->promised_date && $this->promised_date->endOfDay()->isPast() && in_array($this->status, OrderStatus::OPEN, true);
    }

    public function getStatusLabelAttribute(): string
    {
        return OrderStatus::label($this->status);
    }

    public function getStatusPillAttribute(): string
    {
        return OrderStatus::pill($this->status);
    }
}
