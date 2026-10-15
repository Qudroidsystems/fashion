<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A point-of-sale transaction (SRS FR-POS-001). */
class Sale extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = [
        'tenant_id', 'number', 'client_ref', 'shift_id', 'branch_id', 'customer_id', 'status', 'subtotal', 'discount_total',
        'total', 'tendered', 'change_given', 'cashier_id', 'voided_at', 'voided_by', 'void_reason', 'note',
    ];

    protected $casts = [
        'subtotal' => 'float', 'discount_total' => 'float', 'total' => 'float', 'tendered' => 'float',
        'change_given' => 'float', 'voided_at' => 'datetime',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class, 'shift_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
    }

    public function tenders(): HasMany
    {
        return $this->hasMany(SaleTender::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function returnedAmount(): float
    {
        return round((float) $this->returns()->sum('amount'), 2);
    }
}
