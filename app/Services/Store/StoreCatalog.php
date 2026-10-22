<?php

namespace App\Services\Store;

use App\Models\CatalogueCategory;
use App\Models\FashionStyle;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * What the shop shows: products switched on for the catalogue that have at least one
 * active size/colour, with filters, facets, stock per variant (from the fulfilment branch)
 * and related items. Costs never leave this class.
 */
class StoreCatalog
{
    public const SORTS = [
        'featured'   => 'Featured',
        'newest'     => 'Newest',
        'best'       => 'Best selling',
        'price_asc'  => 'Price, low to high',
        'price_desc' => 'Price, high to low',
        'name'       => 'Alphabetically, A–Z',
        'rating'     => 'Top rated',
    ];

    public function __construct(protected StoreSettings $settings)
    {
    }

    public function base(): Builder
    {
        return Product::inCatalogue()
            ->whereHas('variants', fn ($v) => $v->where('is_active', true))
            ->with(['category', 'images', 'variants' => fn ($q) => $q->where('is_active', true)])
            ->addSelect(['products.*',
                'min_price' => ProductVariant::selectRaw('min(price)')->whereColumn('product_id', 'products.id')->where('is_active', true),
            ]);
    }

    /** Filtered, sorted page of products. $f: q, category, size[], colour[], min, max, sale, in_stock, sort, page. */
    public function search(Tenant $tenant, array $f, int $perPage = 24): LengthAwarePaginator
    {
        $q = $this->base();
        $term = trim((string) ($f['q'] ?? ''));
        if ($term !== '') {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('description', 'like', "%{$term}%")
                ->orWhere('tags', 'like', "%{$term}%")->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$term}%")));
        }
        if (! empty($f['category'])) {
            $q->where('category_id', (int) $f['category']);
        }
        $sizes = array_filter((array) ($f['size'] ?? []));
        if ($sizes) {
            $q->whereHas('variants', fn ($v) => $v->where('is_active', true)->whereIn('size', $sizes));
        }
        $colours = array_filter((array) ($f['colour'] ?? []));
        if ($colours) {
            $q->whereHas('variants', fn ($v) => $v->where('is_active', true)->whereIn('colour', $colours));
        }
        if (isset($f['min']) && $f['min'] !== '' && is_numeric($f['min'])) {
            $q->whereHas('variants', fn ($v) => $v->where('is_active', true)->where('price', '>=', (float) $f['min']));
        }
        if (isset($f['max']) && $f['max'] !== '' && is_numeric($f['max'])) {
            $q->whereHas('variants', fn ($v) => $v->where('is_active', true)->where('price', '<=', (float) $f['max']));
        }
        if (! empty($f['sale'])) {
            $q->whereHas('variants', fn ($v) => $v->where('is_active', true)->whereNotNull('compare_at_price')->whereColumn('compare_at_price', '>', 'price'));
        }
        if (! empty($f['featured'])) {
            $q->where('is_featured', true);
        }
        if (! empty($f['in_stock']) || StoreSettings::get($tenant, 'hide_out_of_stock')) {
            $this->onlyInStock($q, $tenant);
        }

        match ($f['sort'] ?? 'featured') {
            'newest'     => $q->latest('products.id'),
            'best'       => $q->orderByDesc('sold_count')->orderBy('name'),
            'price_asc'  => $q->orderBy('min_price')->orderBy('name'),
            'price_desc' => $q->orderByDesc('min_price')->orderBy('name'),
            'name'       => $q->orderBy('name'),
            'rating'     => $q->orderByDesc('rating_avg')->orderByDesc('rating_count'),
            default      => $q->orderByDesc('is_featured')->latest('products.id'),
        };

        return $q->paginate($perPage)->withQueryString();
    }

    /** Products with something to sell: stock not tracked, back-orders allowed, or free stock at the fulfilment branch. */
    protected function onlyInStock(Builder $q, Tenant $tenant): void
    {
        if (StoreSettings::get($tenant, 'allow_backorder')) {
            return;
        }
        $branch = $this->settings->fulfilmentBranchId($tenant);
        $q->where(fn ($w) => $w->where('track_stock', false)->orWhereHas('variants', fn ($v) => $v->where('is_active', true)
            ->whereIn('id', InventoryItem::query()->select('product_variant_id')->whereNotNull('product_variant_id')
                ->whereIn('id', StockBalance::query()->select('item_id')->where('branch_id', $branch)->whereRaw('on_hand - reserved > 0')))));
    }

    public function bySlug(string $slug): ?Product
    {
        return $this->base()->with(['style'])->where('slug', $slug)->first()
            ?? $this->base()->with(['style'])->where('public_id', $slug)->first();
    }

    public function related(Product $product, int $n = 8): Collection
    {
        $same = $this->base()->whereKeyNot($product->id)->when($product->category_id, fn ($q) => $q->where('category_id', $product->category_id))
            ->orderByDesc('sold_count')->take($n)->get();
        if ($same->count() >= 4) {
            return $same;
        }

        return $same->concat($this->base()->whereKeyNot($product->id)->whereNotIn('products.id', $same->pluck('id'))->orderByDesc('is_featured')->latest('products.id')->take($n - $same->count())->get());
    }

    public function featured(int $n = 8): Collection
    {
        $f = $this->base()->where('is_featured', true)->latest('products.id')->take($n)->get();

        return $f->isNotEmpty() ? $f : $this->base()->latest('products.id')->take($n)->get();
    }

    public function newest(int $n = 8): Collection
    {
        return $this->base()->latest('products.id')->take($n)->get();
    }

    public function bestSellers(int $n = 8): Collection
    {
        return $this->base()->where('sold_count', '>', 0)->orderByDesc('sold_count')->take($n)->get();
    }

    public function onSale(int $n = 8): Collection
    {
        return $this->base()->whereHas('variants', fn ($v) => $v->where('is_active', true)->whereNotNull('compare_at_price')->whereColumn('compare_at_price', '>', 'price'))
            ->latest('products.id')->take($n)->get();
    }

    /** Categories that have shop products, with counts and a picture. */
    public function categories(): Collection
    {
        $rows = Product::inCatalogue()->whereHas('variants', fn ($v) => $v->where('is_active', true))->whereNotNull('category_id')
            ->selectRaw('category_id, count(*) c')->groupBy('category_id')->pluck('c', 'category_id');

        return CatalogueCategory::whereIn('id', $rows->keys())->orderBy('sort_order')->orderBy('name')->get()->map(function ($c) use ($rows) {
            $c->shop_count = (int) $rows[$c->id];
            $c->cover = $this->base()->where('category_id', $c->id)->orderByDesc('is_featured')->latest('products.id')->first()?->cover_url;

            return $c;
        });
    }

    /** Filter choices: sizes and colours on sale, and the price range. */
    public function facets(): array
    {
        $variants = ProductVariant::where('is_active', true)->whereHas('product', fn ($p) => $p->inCatalogue())->get(['size', 'colour', 'colour_hex', 'price']);
        $order = ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', '2XL', 'XXXL', '3XL', '4XL', '5XL'];
        $sizes = $variants->pluck('size')->filter()->unique()->sortBy(function ($s) use ($order) {
            $i = array_search(strtoupper($s), $order, true);

            return $i === false ? (is_numeric($s) ? 100 + (float) $s : 1000) : $i;
        })->values();

        return [
            'sizes'   => $sizes,
            'colours' => $variants->filter(fn ($v) => $v->colour)->unique(fn ($v) => mb_strtolower($v->colour))->map(fn ($v) => ['name' => $v->colour, 'hex' => $v->colour_hex ?: self::guessHex($v->colour)])->values(),
            'min'     => (float) floor((float) $variants->min('price')),
            'max'     => (float) ceil((float) $variants->max('price')),
        ];
    }

    /**
     * Free stock per variant id at the fulfilment branch.
     * null = not limited (stock not tracked, or back-orders allowed).
     */
    public function stock(Tenant $tenant, Collection $variants): array
    {
        $variants = $variants->filter();
        if ($variants->isEmpty()) {
            return [];
        }
        $backorder = (bool) StoreSettings::get($tenant, 'allow_backorder');
        $branch = $this->settings->fulfilmentBranchId($tenant);
        $products = Product::whereIn('id', $variants->pluck('product_id')->unique())->pluck('track_stock', 'id');
        $items = InventoryItem::whereIn('product_variant_id', $variants->pluck('id'))->where('is_active', true)->pluck('id', 'product_variant_id');
        $balances = StockBalance::whereIn('item_id', $items->values())->where('branch_id', $branch)
            ->selectRaw('item_id, coalesce(sum(on_hand),0) on_hand, coalesce(sum(reserved),0) reserved')->groupBy('item_id')->get()->keyBy('item_id');

        $out = [];
        foreach ($variants as $v) {
            if ($backorder || ! ($products[$v->product_id] ?? true)) {
                $out[$v->id] = null;
                continue;
            }
            $itemId = $items[$v->id] ?? null;
            $b = $itemId ? $balances->get($itemId) : null;
            $out[$v->id] = $b ? max(0, (int) floor((float) $b->on_hand - (float) $b->reserved)) : 0;
        }

        return $out;
    }

    /** Bespoke styles shown on the shop (made to measure, ordered by enquiry). */
    public function styles(int $n = 0): Collection
    {
        $q = FashionStyle::inCatalogue()->with('category', 'currentVersion.images')->orderBy('name');

        return $n ? $q->take($n)->get() : $q->get();
    }

    /** A swatch colour for common colour names when the house didn't pick one. */
    public static function guessHex(?string $name): string
    {
        $map = ['black' => '#181818', 'white' => '#ffffff', 'red' => '#d62828', 'blue' => '#1d4ed8', 'navy' => '#1e2a4a', 'green' => '#2a9d8f',
            'emerald' => '#047857', 'yellow' => '#f4c430', 'gold' => '#c9a227', 'orange' => '#f77f00', 'pink' => '#ec749d', 'purple' => '#6a0572',
            'wine' => '#722f37', 'maroon' => '#800000', 'brown' => '#7b4b2a', 'beige' => '#e8d9c0', 'cream' => '#f5efdf', 'grey' => '#9ca3af',
            'gray' => '#9ca3af', 'silver' => '#c0c0c0', 'teal' => '#0f766e', 'peach' => '#f6b89a', 'lilac' => '#c8a2c8', 'khaki' => '#b9a77a', 'ivory' => '#fffff0'];
        $n = mb_strtolower((string) $name);
        foreach ($map as $k => $hex) {
            if (str_contains($n, $k)) {
                return $hex;
            }
        }

        return '#' . substr(md5($n), 0, 6);
    }
}
