<?php

namespace App\Http\Controllers\Catalogue;

use App\Http\Controllers\Controller;
use App\Models\CatalogueCategory;
use App\Models\FashionStyle;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Audit\AuditLogger;
use App\Services\Catalogue\ProductService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Ready-made products with size/colour variants (SRS FR-POS-007, FR-INV-002). */
class ProductController extends Controller
{
    public function __construct(protected ProductService $service)
    {
        $this->middleware('permission:View product', ['only' => ['index', 'lookup']]);
        $this->middleware('permission:Manage product', ['except' => ['index', 'lookup']]);
    }

    public function index(Request $request): View
    {
        $status = $request->input('status', 'active');

        return view('catalogue.products.index', [
            'pagetitle'  => 'Products',
            'products'   => Product::with(['category', 'variants'])
                ->search($request->q)
                ->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->integer('category')))
                ->when($status !== 'all', fn ($q) => $q->where('status', $status))
                ->orderBy('name')->paginate(30)->withQueryString(),
            'status'     => $status,
            'categories' => CatalogueCategory::active()->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $product = new Product(['status' => 'active', 'show_in_catalogue' => true, 'track_stock' => true,
            'style_id' => $request->integer('style') ?: null]);
        $product->setRelation('variants', collect());

        return view('catalogue.products.form', $this->formData($product));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $product = $this->service->save(null, $this->fields($data), $data['variants'], $request->file('image'));

        return redirect()->route('products.edit', $product)->with('success', "Product {$product->code} created.");
    }

    public function edit(Product $product): View
    {
        return view('catalogue.products.form', $this->formData($product->load('variants')));
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validated($request, $product);
        $this->service->save($product, $this->fields($data), $data['variants'], $request->file('image'), $request->boolean('remove_image'));

        return redirect()->route('products.edit', $product)->with('success', 'Product saved.');
    }

    public function archive(Product $product): RedirectResponse
    {
        $to = $product->status === 'archived' ? 'active' : 'archived';
        $product->update(['status' => $to]);
        app(\App\Services\Inventory\ItemService::class)->syncProduct($product);
        AuditLogger::log('product.' . ($to === 'archived' ? 'archived' : 'restored'), $product);

        return back()->with('success', $to === 'archived' ? 'Product archived.' : 'Product restored.');
    }

    /** Barcode / SKU lookup for scanners and the POS (FR-POS-002). */
    public function lookup(Request $request): JsonResponse
    {
        $code = trim((string) $request->query('code'));
        $variant = $code === '' ? null : ProductVariant::with('product')
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code))->first();

        if (! $variant) {
            return response()->json(['found' => false], 404);
        }

        return response()->json(['found' => true, 'variant' => [
            'id' => $variant->id, 'sku' => $variant->sku, 'barcode' => $variant->barcode, 'label' => $variant->label,
            'price' => (float) $variant->price, 'active' => $variant->is_active && $variant->product->status === 'active',
            'product' => ['id' => $variant->product->public_id, 'name' => $variant->product->name, 'code' => $variant->product->code],
        ]]);
    }

    /* ------------------------------------------------------------------ */

    protected function validated(Request $request, ?Product $product = null): array
    {
        $tenant = CurrentTenant::id();
        $ids = collect($request->input('variants', []))->pluck('id')->filter()->map(fn ($v) => (int) $v)->all();

        $data = $request->validate([
            'name'                       => 'required|string|max:150',
            'code'                       => [$product ? 'prohibited' : 'nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-_]+$/',
                                             Rule::unique('products', 'code')->where('tenant_id', $tenant)],
            'category_id'                => ['nullable', 'integer', Rule::exists('catalogue_categories', 'id')->where('tenant_id', $tenant)],
            'style_id'                   => ['nullable', 'integer', Rule::exists('fashion_styles', 'id')->where('tenant_id', $tenant)],
            'description'                => 'nullable|string|max:5000',
            'show_in_catalogue'          => 'nullable|boolean',
            'track_stock'                => 'nullable|boolean',
            'image'                      => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'variants'                   => 'required|array|min:1|max:100',
            'variants.*.id'              => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('tenant_id', $tenant)->where('product_id', $product?->id ?? 0)],
            'variants.*.size'            => 'nullable|string|max:30',
            'variants.*.colour'          => 'nullable|string|max:40',
            'variants.*.sku'             => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9\-_\.]+$/', 'distinct:ignore_case'],
            'variants.*.barcode'         => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-]+$/', 'distinct'],
            'variants.*.generate_barcode'=> 'nullable|boolean',
            'variants.*.price'           => 'required|numeric|min:0|max:999999999',
            'variants.*.cost_price'      => 'nullable|numeric|min:0|max:999999999',
            'variants.*.reorder_level'   => 'nullable|numeric|min:0|max:999999',
            'variants.*.is_active'       => 'nullable|boolean',
        ], [
            'variants.required'          => 'Add at least one size or colour (or a single standard row).',
            'variants.*.price.required'  => 'Every row needs a price.',
        ]);

        // SKU / barcode must be unique in the house, ignoring the rows being edited.
        $errors = [];
        foreach ($data['variants'] as $i => $row) {
            foreach (['sku', 'barcode'] as $field) {
                if (! empty($row[$field]) && ProductVariant::where($field, $row[$field])->whereNotIn('id', $ids)->exists()) {
                    $errors["variants.$i.$field"] = strtoupper($field) . ' "' . $row[$field] . '" is already used by another product.';
                }
            }
        }
        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        if (! $request->user()->can('View costing')) {
            foreach ($data['variants'] as &$row) {
                unset($row['cost_price']);
            }
        }

        return $data;
    }

    protected function fields(array $data): array
    {
        return [
            'name'              => $data['name'],
            'code'              => $data['code'] ?? null,
            'category_id'       => $data['category_id'] ?? null,
            'style_id'          => $data['style_id'] ?? null,
            'description'       => $data['description'] ?? null,
            'show_in_catalogue' => (bool) ($data['show_in_catalogue'] ?? false),
            'track_stock'       => (bool) ($data['track_stock'] ?? false),
        ];
    }

    protected function formData(Product $product): array
    {
        return [
            'pagetitle'  => $product->exists ? $product->name : 'New product',
            'product'    => $product,
            'categories' => CatalogueCategory::active()->get(),
            'styles'     => FashionStyle::where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
        ];
    }
}
