<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptLine extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'goods_receipt_id', 'purchase_order_line_id', 'item_id', 'lot_id', 'quantity', 'unit_cost'];

    protected $casts = ['quantity' => 'float', 'unit_cost' => 'float'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }
}
