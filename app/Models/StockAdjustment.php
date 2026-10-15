<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Approvable stock change: adjustment, wastage, stock count, opening balance or
 * customer material received (SRS FR-INV-006, §9 separation of duties, scenario 41).
 */
class StockAdjustment extends Model
{
    use BelongsToTenant, HasPublicId;

    public const KINDS = [
        'adjustment'        => 'Adjustment',
        'wastage'           => 'Wastage / damage',
        'count'             => 'Stock count',
        'opening'           => 'Opening balance',
        'customer_material' => 'Customer material received',
    ];

    public const STATUSES = [
        'draft' => ['Draft', 'st-muted'],
        'pending_approval' => ['Awaiting approval', 'st-warning'],
        'approved' => ['Posted', 'st-success'],
        'rejected' => ['Rejected', 'st-danger'],
    ];

    protected $fillable = [
        'tenant_id', 'number', 'kind', 'branch_id', 'status', 'reason', 'value_change', 'customer_id',
        'created_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'rejection_reason',
    ];

    protected $casts = ['value_change' => 'float', 'submitted_at' => 'datetime', 'approved_at' => 'datetime'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function getKindLabelAttribute(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
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
