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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getLabelAttribute(): string
    {
        return trim(implode(' / ', array_filter([$this->size, $this->colour]))) ?: 'Standard';
    }
}
