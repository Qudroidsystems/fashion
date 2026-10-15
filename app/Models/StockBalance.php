<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cached on-hand / reserved per item, location and lot. Rebuildable from the ledger. */
class StockBalance extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'item_id', 'branch_id', 'lot_key', 'lot_id', 'on_hand', 'reserved', 'low_stock_alerted_at'];

    protected $casts = ['on_hand' => 'float', 'reserved' => 'float', 'low_stock_alerted_at' => 'datetime'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    public function getAvailableAttribute(): float
    {
        return $this->on_hand - $this->reserved;
    }
}
