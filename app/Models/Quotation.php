<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A price offer to a customer (SRS FR-ORD-001, §6.2 step 9–10). */
class Quotation extends Model
{
    use BelongsToTenant, HasPublicId;

    public const STATUSES = [
        'draft' => ['Draft', 'st-muted'], 'sent' => ['Sent', 'st-info'], 'accepted' => ['Accepted', 'st-success'],
        'declined' => ['Declined', 'st-danger'], 'expired' => ['Expired', 'st-muted'], 'converted' => ['Became an order', 'st-success'],
    ];

    protected $fillable = [
        'tenant_id', 'number', 'customer_id', 'branch_id', 'status', 'title', 'event_date', 'valid_until', 'subtotal',
        'discount_total', 'order_discount', 'total', 'deposit_percent', 'customer_notes', 'internal_notes', 'sent_at',
        'decided_at', 'decline_reason', 'order_id', 'created_by',
    ];

    protected $casts = [
        'event_date' => 'date', 'valid_until' => 'date', 'sent_at' => 'datetime', 'decided_at' => 'datetime',
        'subtotal' => 'float', 'discount_total' => 'float', 'order_discount' => 'float', 'total' => 'float', 'deposit_percent' => 'float',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'sent'], true);
    }

    public function effectiveStatus(): string
    {
        return $this->status === 'sent' && $this->valid_until && $this->valid_until->endOfDay()->isPast() ? 'expired' : $this->status;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->effectiveStatus()][0] ?? $this->status;
    }

    public function getStatusPillAttribute(): string
    {
        return self::STATUSES[$this->effectiveStatus()][1] ?? 'st-muted';
    }
}
