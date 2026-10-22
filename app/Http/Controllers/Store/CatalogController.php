<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\CatalogueCategory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Audit\AuditLogger;
use App\Services\Store\StoreCatalog;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Products as the shop shows them: picture gallery, prices and "was" prices, cost (with
 * "View costing"), swatch colours, online description, badges, featured, SEO, visibility.
 * Sizes/colours themselves are created on the product (Catalogue › Ready-made products).
 */
class CatalogController extends Controller
{
    public const MAX_IMAGES = 12;

    public function __construct(protected StoreCatalog $catalog)
    {
        $this->middleware('permission:View product', ['only' => ['index']]);
        $this->middleware('permission:Manage store products', ['except' => ['index']]);
    }

    public function index(Request $request): View
    {
        $tenant = CurrentTenant::require();
        $products = Product::with(['category', 'variants', 'images'])
            ->search($request->q)
            ->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->integer('category')))
            ->when($request->input('show') === 'online', fn ($q) => $q->inCatalogue())
            ->when($request->input('show') === 'hidden', fn ($q) => $q->where(fn ($w) => $w->where('show_in_catalogue', false)->orWhere('status', '!=', 'active')))
            ->when($request->input('show') === 'no_pictures', fn ($q) => $q->whereDoesntHave('images')->whereNull('image_path'))
            ->when($request->input('show') !== 'archived', fn ($q) => $q->where('status', 'active'), fn ($q) => $q->where('status', 'archived'))
            ->orderBy('name')->paginate(30)->withQueryString();

        return view('store-admin.products.index', [
            'pagetitle'  => 'Shop products',
            'products'   => $products,
            'stock'      => $this->catalog->stock($tenant, $products->getCollection()->flatMap->variants),
            'categories' => CatalogueCategory::active()->get(),
            'canCost'    => $request->user()->can('View costing'),
        ]);
    }

    public function edit(Request $request, Product $product): View
    {
        $tenant = CurrentTenant::require();
        $product->load(['variants', 'images', 'category']);

        return view('store-admin.products.edit', [
            'pagetitle'  => $product->name,
            'product'    => $product,
            'stock'      => $this->catalog->stock($tenant, $product->variants),
            'categories' => CatalogueCategory::active()->get(),
            'canCost'    => $request->user()->can('View costing'),
            'shopUrl'    => app(\App\Services\Store\StoreSettings::class)->url($tenant, 'product/' . $product->slug),
            'max'        => self::MAX_IMAGES,
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $tenant = CurrentTenant::id();
        $data = $request->validate([
            'name'              => 'required|string|max:150',
            'slug'              => ['required', 'string', 'max:170', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->where('tenant_id', $tenant)->ignore($product->id)],
            'category_id'       => ['nullable', 'integer', Rule::exists('catalogue_categories', 'id')->where('tenant_id', $tenant)],
            'description'       => 'nullable|string|max:5000',
            'details'           => 'nullable|string|max:5000',
            'badge'             => 'nullable|string|max:30',
            'tags'              => 'nullable|string|max:300',
            'seo_title'         => 'nullable|string|max:160',
            'seo_description'   => 'nullable|string|max:300',
            'show_in_catalogue' => 'nullable|boolean',
            'is_featured'       => 'nullable|boolean',
            'variants'          => 'nullable|array',
            'variants.*.price'  => 'required|numeric|min:0|max:999999999',
            'variants.*.compare_at_price' => 'nullable|numeric|min:0|max:999999999',
            'variants.*.cost_price' => 'nullable|numeric|min:0|max:999999999',
            'variants.*.colour_hex' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'variants.*.is_active' => 'nullable|boolean',
        ], ['slug.regex' => 'Use lower-case letters, numbers and dashes, e.g. ankara-wrap-dress.', 'variants.*.colour_hex.regex' => 'Pick a colour like #7A2E4D.']);

        DB::transaction(function () use ($request, $product, $data) {
            $before = ['name' => $product->name, 'prices' => $product->variants->pluck('price', 'sku')->all()];
            $product->update([
                'name' => $data['name'], 'slug' => $data['slug'], 'category_id' => $data['category_id'] ?? null,
                'description' => $data['description'] ?? null, 'details' => $data['details'] ?? null, 'badge' => $data['badge'] ?? null,
                'tags' => collect(explode(',', (string) ($data['tags'] ?? '')))->map(fn ($t) => trim($t))->filter()->unique()->values()->all() ?: null,
                'seo_title' => $data['seo_title'] ?? null, 'seo_description' => $data['seo_description'] ?? null,
                'show_in_catalogue' => $request->boolean('show_in_catalogue'), 'is_featured' => $request->boolean('is_featured'),
            ]);
            $canCost = $request->user()->can('View costing');
            foreach ((array) ($data['variants'] ?? []) as $id => $row) {
                $v = $product->variants()->whereKey((int) $id)->first();
                if (! $v) {
                    continue;
                }
                $compare = isset($row['compare_at_price']) && $row['compare_at_price'] !== '' ? round((float) $row['compare_at_price'], 2) : null;
                $v->fill([
                    'price' => round((float) $row['price'], 2),
                    'compare_at_price' => $compare && $compare > (float) $row['price'] ? $compare : null,
                    'colour_hex' => $row['colour_hex'] ?? null,
                    'is_active' => (bool) ($row['is_active'] ?? false),
                ]);
                if ($canCost && array_key_exists('cost_price', $row)) {
                    $v->cost_price = $row['cost_price'] !== '' && $row['cost_price'] !== null ? round((float) $row['cost_price'], 2) : null;
                }
                $v->save();
            }
            app(\App\Services\Inventory\ItemService::class)->syncProduct($product->fresh('variants', 'category'));
            AuditLogger::log('product.shop_updated', $product, $before, ['name' => $product->name, 'prices' => $product->variants()->pluck('price', 'sku')->all()]);
        });

        return back()->with('success', 'Saved. The shop shows the changes straight away.');
    }

    /** Inline price / sale price / visibility changes from the list. */
    public function quick(Request $request, Product $product): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('product_id', $product->id)],
            'price' => 'nullable|numeric|min:0|max:999999999', 'compare_at_price' => 'nullable|numeric|min:0|max:999999999',
            'show_in_catalogue' => 'nullable|boolean', 'is_featured' => 'nullable|boolean',
        ]);
        if (! empty($data['variant_id'])) {
            $v = ProductVariant::findOrFail($data['variant_id']);
            $old = $v->price;
            if (array_key_exists('price', $data) && $data['price'] !== null) {
                $v->price = round((float) $data['price'], 2);
            }
            if (array_key_exists('compare_at_price', $data)) {
                $v->compare_at_price = $data['compare_at_price'] && $data['compare_at_price'] > $v->price ? round((float) $data['compare_at_price'], 2) : null;
            }
            $v->save();
            app(\App\Services\Inventory\ItemService::class)->syncProduct($product->fresh('variants', 'category'));
            AuditLogger::log('product.price_changed', $product, ['sku' => $v->sku, 'price' => $old], ['price' => $v->price, 'compare_at_price' => $v->compare_at_price]);
        }
        foreach (['show_in_catalogue', 'is_featured'] as $f) {
            if ($request->has($f)) {
                $product->update([$f => $request->boolean($f)]);
            }
        }

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Saved.');
    }

    public function upload(Request $request, Product $product): RedirectResponse
    {
        $request->validate([
            'images'   => 'required|array|min:1|max:' . self::MAX_IMAGES,
            'images.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
            'colour'   => 'nullable|string|max:40',
        ], ['images.*.max' => 'Each picture must be 5 MB or smaller.', 'images.*.image' => 'Only pictures (JPG, PNG or WebP) can be uploaded.']);
        $have = $product->images()->count();
        if ($have + count($request->file('images')) > self::MAX_IMAGES) {
            return back()->withErrors(['images' => 'A product can have up to ' . self::MAX_IMAGES . ' pictures. Remove some first.']);
        }
        $tenant = CurrentTenant::require();
        $n = $have;
        foreach ($request->file('images') as $file) {
            $path = $file->storeAs('tenants/' . $tenant->public_id . '/products/' . $product->public_id,
                Str::lower(Str::random(10)) . '.' . $file->extension(), 'public');
            $product->images()->create(['path' => $path, 'alt' => $product->name, 'colour' => $request->input('colour') ?: null, 'sort_order' => $n++]);
        }
        // The first picture also becomes the product's picture (till, catalogue, order lines).
        if (! $product->image_path && ($first = $product->images()->first())) {
            $product->update(['image_path' => $first->path]);
        }
        AuditLogger::log('product.images_added', $product, after: ['count' => $n - $have]);

        return back()->with('success', ($n - $have) . ' picture(s) added.');
    }

    public function sortImages(Request $request, Product $product): JsonResponse|RedirectResponse
    {
        $ids = array_map('intval', (array) $request->input('order', []));
        foreach ($ids as $i => $id) {
            $product->images()->whereKey($id)->update(['sort_order' => $i]);
        }
        if ($first = $product->images()->first()) {
            $product->update(['image_path' => $first->path]);
        }

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Order saved.');
    }

    public function updateImage(Request $request, Product $product, ProductImage $image): RedirectResponse
    {
        abort_unless($image->product_id === $product->id, 404);
        $data = $request->validate(['alt' => 'nullable|string|max:150', 'colour' => 'nullable|string|max:40', 'cover' => 'nullable|boolean']);
        $image->update(['alt' => $data['alt'] ?? $image->alt, 'colour' => $data['colour'] ?? null]);
        if ($request->boolean('cover')) {
            $product->images()->where('id', '!=', $image->id)->increment('sort_order');
            $image->update(['sort_order' => 0]);
            $product->update(['image_path' => $image->path]);
        }

        return back()->with('success', 'Picture updated.');
    }

    public function deleteImage(Product $product, ProductImage $image): RedirectResponse
    {
        abort_unless($image->product_id === $product->id, 404);
        $wasCover = $product->image_path === $image->path;
        $path = $image->path;
        $image->delete();
        if ($wasCover) {
            $product->update(['image_path' => $product->images()->first()?->path]);
        }
        if (! ProductImage::where('path', $path)->exists() && $product->fresh()->image_path !== $path) {
            Storage::disk('public')->delete($path);
        }

        return back()->with('success', 'Picture removed.');
    }
}
