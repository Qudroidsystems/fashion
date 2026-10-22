<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A shopper's login on one house's online shop (separate from staff users). */
class StoreAccount extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = ['tenant_id', 'customer_id', 'first_name', 'last_name', 'email', 'phone', 'google_id', 'avatar_url', 'email_verified_at', 'password', 'marketing_opt_in', 'status', 'last_login_at'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['password' => 'hashed', 'marketing_opt_in' => 'boolean', 'last_login_at' => 'datetime', 'email_verified_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(fn (StoreAccount $a) => $a->email = mb_strtolower(trim($a->email)));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(StoreAddress::class, 'account_id')->orderByDesc('is_default')->orderBy('id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ShopOrder::class, 'account_id')->latest('id');
    }

    public function getNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }
}
