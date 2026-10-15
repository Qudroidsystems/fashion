<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Shared categories for bespoke styles and ready-made products (Gowns, Agbada, Shirts…). */
class CatalogueCategory extends Model
{
    use BelongsToTenant;

    public const STARTER = ['Gowns & dresses', 'Native (agbada / senator)', 'Shirts & tops', 'Trousers', 'Skirts', 'Suits', 'Kaftans', 'Children', 'Accessories'];

    protected $fillable = ['tenant_id', 'name', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function styles(): HasMany
    {
        return $this->hasMany(FashionStyle::class, 'category_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }
}
