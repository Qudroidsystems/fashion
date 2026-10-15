<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Material an order needs, reserved in stock (SRS §7 order_material_requirements, §6.2 step 12). */
class OrderMaterialRequirement extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'order_id', 'order_line_id', 'item_id', 'quantity', 'reservation_id', 'note'];

    protected $casts = ['quantity' => 'float'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(StockReservation::class);
    }
}
