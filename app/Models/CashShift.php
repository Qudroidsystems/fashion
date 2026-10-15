<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A cashier's till session (SRS FR-POS-003). */
class CashShift extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = [
        'tenant_id', 'number', 'branch_id', 'user_id', 'status', 'opened_at', 'opening_float', 'closed_at',
        'expected_cash', 'counted_cash', 'variance', 'variance_reason', 'closed_by', 'notes',
    ];

    protected $casts = [
        'opened_at' => 'datetime', 'closed_at' => 'datetime', 'opening_float' => 'float', 'expected_cash' => 'float',
        'counted_cash' => 'float', 'variance' => 'float',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'shift_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class, 'shift_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class, 'shift_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
