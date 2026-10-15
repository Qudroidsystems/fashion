<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** SRS FR-INV-005: draft → pending approval → approved → (partially) received / cancelled / closed. */
class PurchaseOrder extends Model
{
    use BelongsToTenant, HasPublicId;

    public const STATUSES = [
        'draft' => ['Draft', 'st-muted'],
        'pending_approval' => ['Awaiting approval', 'st-warning'],
        'approved' => ['Approved', 'st-info'],
        'partially_received' => ['Part received', 'st-violet'],
        'received' => ['Received', 'st-success'],
        'cancelled' => ['Cancelled', 'st-danger'],
        'closed' => ['Closed', 'st-muted'],
    ];

    protected $fillable = [
        'tenant_id', 'number', 'supplier_id', 'branch_id', 'status', 'order_date', 'expected_date', 'total', 'notes',
        'created_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'cancel_reason',
    ];

    protected $casts = [
        'order_date' => 'date', 'expected_date' => 'date', 'total' => 'float',
        'submitted_at' => 'datetime', 'approved_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function isReceivable(): bool
    {
        return in_array($this->status, ['approved', 'partially_received'], true);
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
