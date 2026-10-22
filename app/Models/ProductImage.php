<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A picture in a product's shop gallery (first = cover). */
class ProductImage extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'product_id', 'path', 'alt', 'colour', 'sort_order'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getUrlAttribute(): string
    {
        return asset('storage/' . $this->path);
    }
}
