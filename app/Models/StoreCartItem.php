<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreCartItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'cart_id', 'product_variant_id', 'quantity'];

    protected $casts = ['quantity' => 'integer'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
