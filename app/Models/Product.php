<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A ready-made item sold off the rack (SRS FR-POS-007). Sizes/colours are variants. */
class Product extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'code', 'name', 'category_id', 'style_id', 'description', 'image_path', 'status',
        'show_in_catalogue', 'track_stock', 'created_by',
    ];

    protected $casts = ['show_in_catalogue' => 'boolean', 'track_stock' => 'boolean'];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CatalogueCategory::class, 'category_id');
    }

    public function style(): BelongsTo
    {
        return $this->belongsTo(FashionStyle::class, 'style_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    public function scopeSearch($q, ?string $term)
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }

        return $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")
            ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', "%{$term}%")->orWhere('barcode', $term)));
    }

    public function scopeInCatalogue($q)
    {
        return $q->where('status', 'active')->where('show_in_catalogue', true);
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? asset('storage/' . $this->image_path) : null;
    }

    /** [min, max] price over active variants. */
    public function priceRange(): array
    {
        $prices = $this->variants->where('is_active', true)->pluck('price')->map(fn ($p) => (float) $p);

        return [$prices->min(), $prices->max()];
    }
}
