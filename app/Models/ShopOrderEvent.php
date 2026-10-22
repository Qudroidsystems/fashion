<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Timeline of a shop order (status changes, payments, notes). Never edited. */
class ShopOrderEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'shop_order_id', 'type', 'status', 'note', 'customer_visible', 'user_id'];

    protected $casts = ['customer_visible' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
