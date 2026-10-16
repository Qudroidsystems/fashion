<?php

namespace App\Models;

use App\Support\Billing\Features;
use Illuminate\Database\Eloquent\Model;

/** A subscription plan (platform-level; edited by the platform super admin). */
class Plan extends Model
{
    protected $fillable = [
        'code', 'name', 'tagline', 'price_monthly', 'price_yearly', 'limits', 'features', 'is_free', 'is_active', 'is_public', 'highlight', 'sort_order',
    ];

    protected $casts = [
        'price_monthly' => 'float', 'price_yearly' => 'float', 'limits' => 'array', 'features' => 'array',
        'is_free' => 'boolean', 'is_active' => 'boolean', 'is_public' => 'boolean', 'highlight' => 'boolean',
    ];

    public static function free(): ?self
    {
        return static::where('is_free', true)->orderBy('sort_order')->first();
    }

    public function scopeOrdered($q)
    {
        return $q->orderBy('sort_order')->orderBy('id');
    }

    public function has(string $feature): bool
    {
        return in_array($feature, $this->features ?? [], true);
    }

    /** null = unlimited. */
    public function limit(string $key): ?int
    {
        $limits = $this->limits ?? [];

        return array_key_exists($key, $limits) && $limits[$key] !== null && $limits[$key] !== '' ? (int) $limits[$key] : null;
    }

    public function price(string $interval): float
    {
        return $interval === 'yearly' ? $this->price_yearly : $this->price_monthly;
    }

    public function isPaid(): bool
    {
        return ! $this->is_free && ($this->price_monthly > 0 || $this->price_yearly > 0);
    }

    /** Human list of what the plan includes, in catalogue order. */
    public function featureLabels(): array
    {
        return collect(Features::ALL)->filter(fn ($l, $k) => $this->has($k))->values()->all();
    }
}
