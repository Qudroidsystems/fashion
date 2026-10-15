<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A sellable size/colour of a product, identified by SKU and optional barcode. */
class ProductVariant extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'product_id', 'sku', 'barcode', 'size', 'colour', 'price', 'cost_price', 'reorder_level', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'price'         => 'decimal:2',
        'cost_price'    => 'decimal:2',
        'reorder_level' => 'decimal:3',
        'is_active'     => 'boolean',
    ];

    protected static function booted(): void
    {
        // Its stock item keeps the history: switch it off rather than lose movements.
        static::deleting(function (ProductVariant $v) {
            $item = InventoryItem::where('product_variant_id', $v->id)->first();
            if ($item) {
                StockMovement::where('item_id', $item->id)->exists()
                    ? $item->update(['is_active' => false, 'product_variant_id' => null])
                    : $item->delete();
            }
        });
    }

    public function stockItem(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(InventoryItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getLabelAttribute(): string
    {
        return trim(implode(' / ', array_filter([$this->size, $this->colour]))) ?: 'Standard';
    }
}
