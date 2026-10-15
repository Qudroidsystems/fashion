<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialIssueLine extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'material_issue_id', 'item_id', 'lot_id', 'reservation_id', 'quantity', 'returned_quantity', 'unit_cost'];

    protected $casts = ['quantity' => 'float', 'returned_quantity' => 'float', 'unit_cost' => 'float'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    public function getNetQuantityAttribute(): float
    {
        return round($this->quantity - $this->returned_quantity, 3);
    }
}
