<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CouponRedemption extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'coupon_id', 'shop_order_id', 'email', 'phone_normalized', 'amount'];
}
