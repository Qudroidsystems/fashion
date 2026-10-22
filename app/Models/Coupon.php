<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A discount code for the online shop. */
class Coupon extends Model
{
    use BelongsToTenant;

    public const TYPES = ['percent' => 'Percentage off', 'fixed' => 'Amount off', 'free_shipping' => 'Free delivery'];

    protected $fillable = ['tenant_id', 'code', 'description', 'type', 'value', 'min_subtotal', 'max_discount', 'usage_limit',
        'usage_per_customer', 'used_count', 'starts_at', 'ends_at', 'is_active', 'created_by'];

    protected $casts = ['value' => 'float', 'min_subtotal' => 'float', 'max_discount' => 'float', 'starts_at' => 'datetime', 'ends_at' => 'datetime',
        'is_active' => 'boolean', 'usage_limit' => 'integer', 'usage_per_customer' => 'integer', 'used_count' => 'integer'];

    protected static function booted(): void
    {
        static::saving(fn (Coupon $c) => $c->code = mb_strtoupper(trim($c->code)));
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function getSummaryAttribute(): string
    {
        return match ($this->type) {
            'percent' => rtrim(rtrim(number_format($this->value, 2), '0'), '.') . '% off',
            'fixed' => \App\Support\Money::format($this->value) . ' off',
            default => 'Free delivery',
        };
    }
}
