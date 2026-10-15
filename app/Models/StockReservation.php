<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Stock set aside for a customer job / order / project (SRS FR-INV-006). */
class StockReservation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'item_id', 'branch_id', 'quantity', 'consumed', 'status', 'customer_id', 'order_id', 'project_id',
        'reference', 'needed_by', 'created_by', 'released_by', 'released_at',
    ];

    protected $casts = ['quantity' => 'float', 'consumed' => 'float', 'needed_by' => 'date', 'released_at' => 'datetime'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getOpenQuantityAttribute(): float
    {
        return $this->status === 'active' ? max(0, $this->quantity - $this->consumed) : 0.0;
    }
}
