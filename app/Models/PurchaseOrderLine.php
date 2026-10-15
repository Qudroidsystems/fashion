<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'purchase_order_id', 'item_id', 'description', 'quantity', 'unit_cost', 'received_quantity', 'sort_order'];

    protected $casts = ['quantity' => 'float', 'unit_cost' => 'float', 'received_quantity' => 'float'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function getOutstandingAttribute(): float
    {
        return max(0, round($this->quantity - $this->received_quantity, 3));
    }

    public function getLineTotalAttribute(): float
    {
        return round($this->quantity * $this->unit_cost, 2);
    }
}
