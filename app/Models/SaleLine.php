<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleLine extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'sale_id', 'kind', 'product_variant_id', 'item_id', 'order_id', 'payment_id', 'description', 'quantity',
        'unit_price', 'list_price', 'discount', 'line_total', 'unit_cost', 'returned_quantity',
    ];

    protected $casts = [
        'quantity' => 'float', 'unit_price' => 'float', 'list_price' => 'float', 'discount' => 'float', 'line_total' => 'float',
        'unit_cost' => 'float', 'returned_quantity' => 'float',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function getReturnableAttribute(): float
    {
        return $this->kind === 'product' ? max(0, round($this->quantity - $this->returned_quantity, 2)) : 0.0;
    }

    /** Net price of one unit after the line discount (what a return gives back). */
    public function getNetUnitAttribute(): float
    {
        return $this->quantity > 0 ? round($this->line_total / $this->quantity, 2) : 0.0;
    }
}
