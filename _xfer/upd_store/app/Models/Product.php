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
        'tenant_id', 'code', 'name', 'slug', 'category_id', 'style_id', 'description', 'details', 'image_path', 'status',
        'show_in_catalogue', 'is_featured', 'badge', 'tags', 'seo_title', 'seo_description', 'track_stock', 'created_by',
    ];

    protected $casts = [
        'show_in_catalogue' => 'boolean', 'track_stock' => 'boolean', 'is_featured' => 'boolean', 'tags' => 'array',
        'rating_avg' => 'float', 'rating_count' => 'integer', 'sold_count' => 'integer',
    ];

    protected static function booted(): void
    {
        // A readable, unique address for the online shop: /product/{slug}.
        static::saving(function (Product $p) {
            if (! $p->slug) {   // kept when the name changes, so shared links keep working
                $p->slug = static::uniqueSlug($p->name, $p->id);
            }
        });
    }

    public static function uniqueSlug(?string $name, ?int $ignoreId = null): string
    {
        $base = \Illuminate\Support\Str::slug((string) $name) ?: 'product';
        $base = mb_substr($base, 0, 170);
        $slug = $base;
        $i = 2;
        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

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

    /** Shop gallery (first = cover). */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    /** Every picture URL for the shop: the gallery, else the single product picture. */
    public function galleryUrls(): array
    {
        $urls = $this->relationLoaded('images') || $this->exists ? $this->images->map(fn ($i) => $i->url)->all() : [];
        if (! $urls && $this->image_url) {
            $urls = [$this->image_url];
        }

        return $urls;
    }

    public function getCoverUrlAttribute(): ?string
    {
        return $this->galleryUrls()[0] ?? null;
    }

    public function getHoverUrlAttribute(): ?string
    {
        return $this->galleryUrls()[1] ?? null;
    }

    /** Highest "was" price saving across active variants, as a whole percentage (0 when none). */
    public function salePercent(): int
    {
        return (int) $this->variants->where('is_active', true)->map(function ($v) {
            $was = (float) $v->compare_at_price;

            return $was > (float) $v->price && $was > 0 ? round(($was - (float) $v->price) / $was * 100) : 0;
        })->max();
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

    public function getRatingStarsAttribute(): int
    {
        return (int) round($this->rating_avg);
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
