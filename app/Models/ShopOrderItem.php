<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a shop order: a frozen copy of the product, size/colour and price paid. */
class ShopOrderItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'shop_order_id', 'product_id', 'product_variant_id', 'name', 'variant_label', 'sku', 'image_path',
        'unit_price', 'compare_at_price', 'quantity', 'line_total', 'reservation_id', 'order_line_id'];

    protected $casts = ['unit_price' => 'float', 'compare_at_price' => 'float', 'line_total' => 'float', 'quantity' => 'integer'];

    public function shopOrder(): BelongsTo
    {
        return $this->belongsTo(ShopOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(StockReservation::class);
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? asset('storage/' . $this->image_path) : null;
    }
}
