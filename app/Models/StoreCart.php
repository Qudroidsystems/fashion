<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A shopper's basket (guest: cookie token; signed in: their account). */
class StoreCart extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'token', 'account_id', 'coupon_code'];

    public function items(): HasMany
    {
        return $this->hasMany(StoreCartItem::class, 'cart_id')->orderBy('id');
    }
}
