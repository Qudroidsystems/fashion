<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustmentLine extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'stock_adjustment_id', 'item_id', 'lot_id', 'expected_quantity', 'counted_quantity', 'quantity_change', 'unit_cost', 'note'];

    protected $casts = ['expected_quantity' => 'float', 'counted_quantity' => 'float', 'quantity_change' => 'float', 'unit_cost' => 'float'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }
}
