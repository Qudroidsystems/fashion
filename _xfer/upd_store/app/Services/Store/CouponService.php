<?php

namespace App\Services\Store;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Support\Money;

/** Discount codes: checks and the amount (all money in minor units here). */
class CouponService
{
    /** @return array{coupon: ?Coupon, discount: int, free_shipping: bool, error: ?string} */
    public function check(?string $code, int $subtotal, ?string $email = null, ?string $phone = null): array
    {
        $out = ['coupon' => null, 'discount' => 0, 'free_shipping' => false, 'error' => null];
        $code = mb_strtoupper(trim((string) $code));
        if ($code === '') {
            return $out;
        }
        $c = Coupon::where('code', $code)->first();
        $fail = fn (string $m) => ['error' => $m] + $out;
        if (! $c || ! $c->is_active) {
            return $fail('That code is not valid.');
        }
        if ($c->starts_at && $c->starts_at->isFuture()) {
            return $fail('That code is not active yet.');
        }
        if ($c->ends_at && $c->ends_at->isPast()) {
            return $fail('That code has expired.');
        }
        if ($c->usage_limit !== null && $c->used_count >= $c->usage_limit) {
            return $fail('That code has been used up.');
        }
        if ($c->min_subtotal && $subtotal < (int) round($c->min_subtotal * 100)) {
            return $fail('Spend ' . Money::format($c->min_subtotal) . ' or more to use this code.');
        }
        if ($c->usage_per_customer && ($email || $phone)) {
            $used = CouponRedemption::where('coupon_id', $c->id)->where(function ($q) use ($email, $phone) {
                $email && $q->orWhere('email', mb_strtolower(trim($email)));
                ($p = Customer::normalizePhone($phone)) && $q->orWhere('phone_normalized', $p);
            })->count();
            if ($used >= $c->usage_per_customer) {
                return $fail('You have already used this code.');
            }
        }

        $discount = match ($c->type) {
            'percent' => (int) round($subtotal * min(100, $c->value) / 100),
            'fixed'   => (int) round($c->value * 100),
            default   => 0,
        };
        if ($c->type === 'percent' && $c->max_discount) {
            $discount = min($discount, (int) round($c->max_discount * 100));
        }

        return ['coupon' => $c, 'discount' => max(0, min($discount, $subtotal)), 'free_shipping' => $c->type === 'free_shipping', 'error' => null];
    }
}
