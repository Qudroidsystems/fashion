<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A roll, dye lot or remnant of an item (SRS FR-INV-003/007). */
class StockLot extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'item_id', 'lot_no', 'kind', 'colour', 'width', 'expires_on', 'supplier_id', 'parent_lot_id', 'notes'];

    protected $casts = ['expires_on' => 'date'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_lot_id');
    }

    public function getLabelAttribute(): string
    {
        return ($this->kind === 'remnant' ? 'Remnant ' : ($this->kind === 'roll' ? 'Roll ' : 'Lot ')) . $this->lot_no;
    }
}
