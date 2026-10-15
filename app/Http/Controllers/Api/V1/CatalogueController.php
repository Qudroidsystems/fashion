<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FashionStyle;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StyleVersion;
use App\Services\Catalogue\StyleService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SRS §8.2 Styles + ready-made catalogue:
 *   GET/POST /styles · GET/PATCH /styles/{id} · POST /styles/{id}/versions · POST /styles/{id}/images
 *   POST /style-versions/{id}/publish
 *   GET /products · GET /products/lookup?code= · GET /products/{id}
 *   GET /catalogue — what customers may see (published, switched on, no costs)
 * {id} is the style's / product's public id. Costs are returned only with "View costing".
 */
class CatalogueController extends Controller
{
    public function __construct(protected StyleService $styles)
    {
        $this->middleware('permission:View style', ['only' => ['styles', 'style']]);
        $this->middleware('permission:Manage style', ['only' => ['storeStyle', 'updateStyle', 'newVersion', 'uploadImages']]);
        $this->middleware('permission:Publish style', ['only' => ['publish']]);
        $this->middleware('permission:View product', ['only' => ['products', 'product', 'lookup']]);
    }

    /* ---------------------------- styles ------------------------------ */

    public function styles(Request $request): JsonResponse
    {
        $request->validate(['q' => 'nullable|string|max:100', 'category_id' => 'nullable|integer', 'per_page' => 'nullable|integer|min:1|max:100']);

        $page = FashionStyle::with(['category', 'currentVersion.images'])->where('status', $request->input('status', 'active'))
            ->search($request->q)
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->orderBy('name')->paginate((int) $request->input('per_page', 25));

        return response()->json([
            'success' => true,
            'data'    => $page->getCollection()->map(fn ($s) => $this->styleSummary($s)),
            'meta'    => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function style(Request $request, FashionStyle $style): JsonResponse
    {
        $version = $request->filled('version')
            ? $style->versions()->where('version_no', $request->integer('version'))->firstOrFail()
            : ($style->currentVersion ?? $style->draft);

        return response()->json(['success' => true, 'data' => $this->styleSummary($style) + [
            'version'  => $version ? $this->versionOut($version, $request->user()->can('View costing')) : null,
            'versions' => $style->versions()->get(['id', 'version_no', 'status', 'published_at', 'change_notes'])
                ->map(fn ($v) => $v->only(['id', 'version_no', 'status', 'change_notes']) + ['published_at' => $v->published_at?->toIso8601String()]),
        ]]);
    }

    public function storeStyle(Request $request): JsonResponse
    {
        $data = $this->validateStyle($request, true);
        $style = $this->styles->create($this->styleFields($data), $this->contentFields($request, $data), $data['template_ids'] ?? []);

        return response()->json(['success' => true, 'data' => $this->styleSummary($style->fresh()) + [
            'version' => $this->versionOut($style->draft()->first(), $request->user()->can('View costing')),
        ]], 201);
    }

    /** Updates style fields and the draft (created from the live version if needed). */
    public function updateStyle(Request $request, FashionStyle $style): JsonResponse
    {
        abort_if($style->status === 'archived', 409, 'Restore the style before editing it.');
        $data = $this->validateStyle($request, false);

        $style->update($this->styleFields($data, partial: true));
        $content = array_intersect_key($this->contentFields($request, $data), $request->all());
        $draft = $this->styles->draftFor($style);
        $this->styles->updateDraft($draft, $content, array_key_exists('template_ids', $data) ? ($data['template_ids'] ?? []) : null);

        return response()->json(['success' => true, 'data' => $this->styleSummary($style->fresh()) + [
            'version' => $this->versionOut($draft->fresh(), $request->user()->can('View costing')),
        ]]);
    }

    public function newVersion(Request $request, FashionStyle $style): JsonResponse
    {
        abort_if($style->status === 'archived', 409, 'Restore the style before editing it.');
        $draft = $this->styles->draftFor($style);

        return response()->json(['success' => true, 'data' => $this->versionOut($draft, $request->user()->can('View costing'))], 201);
    }

    public function uploadImages(Request $request, FashionStyle $style): JsonResponse
    {
        $request->validate(['images' => 'required|array|max:' . StyleService::MAX_IMAGES, 'images.*' => 'image|mimes:jpg,jpeg,png,webp|max:4096']);
        $draft = $this->styles->draftFor($style);
        $this->styles->addImages($draft, $request->file('images'));

        return response()->json(['success' => true, 'data' => $this->versionOut($draft->fresh(), $request->user()->can('View costing'))], 201);
    }

    public function publish(Request $request, StyleVersion $styleVersion): JsonResponse
    {
        abort_unless($styleVersion->isEditable(), 409, 'This version is already published.');
        $data = $request->validate(['change_notes' => 'nullable|string|max:255']);
        $version = $this->styles->publish($styleVersion, $data['change_notes'] ?? null);

        return response()->json(['success' => true, 'data' => $this->versionOut($version, $request->user()->can('View costing'))]);
    }

    /* --------------------------- products ----------------------------- */

    public function products(Request $request): JsonResponse
    {
        $request->validate(['q' => 'nullable|string|max:100', 'per_page' => 'nullable|integer|min:1|max:100']);
        $page = Product::with('category', 'variants')->where('status', 'active')->search($request->q)
            ->orderBy('name')->paginate((int) $request->input('per_page', 25));
        $cost = $request->user()->can('View costing');

        return response()->json([
            'success' => true,
            'data'    => $page->getCollection()->map(fn ($p) => $this->productOut($p, $cost)),
            'meta'    => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function product(Request $request, Product $product): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->productOut($product->load('category', 'variants'), $request->user()->can('View costing'))]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $code = trim((string) $request->validate(['code' => 'required|string|max:60'])['code']);
        $variant = ProductVariant::with('product.category', 'product.variants')->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code))->first();
        abort_unless($variant, 404, 'No product with that barcode or SKU.');

        return response()->json(['success' => true, 'data' => [
            'variant' => $this->variantOut($variant, $request->user()->can('View costing')),
            'product' => $this->productOut($variant->product, $request->user()->can('View costing')),
        ]]);
    }

    /* ---------------------- customer catalogue ------------------------ */

    public function catalogue(Request $request): JsonResponse
    {
        $styles = FashionStyle::inCatalogue()->with('category', 'currentVersion.images')->orderBy('name')->get();
        $products = Product::inCatalogue()->with(['category', 'variants' => fn ($q) => $q->where('is_active', true)])->orderBy('name')->get();

        return response()->json(['success' => true, 'data' => [
            'currency' => CurrentTenant::get()->currency_code,
            'styles'   => $styles->map(fn ($s) => $this->styleSummary($s) + [
                'description' => $s->currentVersion->description,
                'options'     => $s->currentVersion->options(),
                'images'      => $s->currentVersion->images->map(fn ($i) => $i->url)->values(),
            ])->values(),
            'products' => $products->filter(fn ($p) => $p->variants->isNotEmpty())->map(fn ($p) => [
                'id' => $p->public_id, 'name' => $p->name, 'category' => $p->category?->name, 'description' => $p->description,
                'image' => $p->image_url,
                'variants' => $p->variants->map(fn ($v) => ['id' => $v->id, 'label' => $v->label, 'size' => $v->size, 'colour' => $v->colour, 'price' => (float) $v->price])->values(),
            ])->values(),
        ]]);
    }

    /* ---------------------------- helpers ----------------------------- */

    protected function validateStyle(Request $request, bool $creating): array
    {
        $tenant = CurrentTenant::id();

        return $request->validate([
            'name'                   => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'code'                   => [$creating ? 'nullable' : 'prohibited', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-_\/]+$/', Rule::unique('fashion_styles', 'code')->where('tenant_id', $tenant)],
            'category_id'            => ['nullable', 'integer', Rule::exists('catalogue_categories', 'id')->where('tenant_id', $tenant)],
            'audience'               => ['sometimes', Rule::in(array_keys(FashionStyle::AUDIENCES))],
            'show_in_catalogue'      => 'sometimes|boolean',
            'description'            => 'nullable|string|max:5000',
            'technical_notes'        => 'nullable|string|max:5000',
            'base_price'             => 'nullable|numeric|min:0',
            'estimated_cost'         => 'nullable|numeric|min:0',
            'lead_time_days'         => 'nullable|integer|min:0|max:365',
            'fabric_quantity'        => 'nullable|numeric|min:0|max:999',
            'fabric_unit'            => 'nullable|in:yd,m',
            'fabric_notes'           => 'nullable|string|max:255',
            'price_options'          => 'nullable|array|max:20',
            'price_options.*.label'  => 'required_with:price_options|string|max:80',
            'price_options.*.amount' => 'nullable|numeric|min:0',
            'template_ids'           => 'nullable|array',
            'template_ids.*'         => 'integer',
        ]);
    }

    protected function styleFields(array $data, bool $partial = false): array
    {
        $fields = array_intersect_key($data, array_flip(['name', 'code', 'category_id', 'audience', 'show_in_catalogue']));

        return $partial ? $fields : $fields + ['audience' => 'any'];
    }

    protected function contentFields(Request $request, array $data): array
    {
        $fields = array_intersect_key($data, array_flip(StyleVersion::CONTENT));
        if (! $request->user()->can('View costing')) {
            unset($fields['estimated_cost']);
        }

        return $fields;
    }

    protected function styleSummary(FashionStyle $s): array
    {
        $v = $s->currentVersion;

        return [
            'id'                => $s->public_id,
            'code'              => $s->code,
            'name'              => $s->name,
            'category'          => $s->category?->name,
            'category_id'       => $s->category_id,
            'audience'          => $s->audience,
            'status'            => $s->status,
            'show_in_catalogue' => $s->show_in_catalogue,
            'published'         => (bool) $s->current_version_id,
            'version_no'        => $v?->version_no,
            'base_price'        => $v?->base_price !== null ? (float) $v->base_price : null,
            'lead_time_days'    => $v?->lead_time_days,
            'cover'             => $v?->images->first()?->url,
        ];
    }

    protected function versionOut(StyleVersion $v, bool $withCost): array
    {
        $v->loadMissing('images', 'templateLinks.template', 'templateLinks.templateVersion');

        return array_filter([
            'id'              => $v->id,
            'version_no'      => $v->version_no,
            'status'          => $v->status,
            'description'     => $v->description,
            'technical_notes' => $v->technical_notes,
            'base_price'      => $v->base_price !== null ? (float) $v->base_price : null,
            'estimated_cost'  => $withCost && $v->estimated_cost !== null ? (float) $v->estimated_cost : null,
            'price_options'   => $v->options(),
            'lead_time_days'  => $v->lead_time_days,
            'fabric'          => ['quantity' => $v->fabric_quantity !== null ? (float) $v->fabric_quantity : null, 'unit' => $v->fabric_unit, 'notes' => $v->fabric_notes],
            'templates'       => $v->templateLinks->map(fn ($l) => [
                'template_id' => $l->template_id, 'name' => $l->template?->name,
                'template_version_id' => $l->template_version_id, 'template_version_no' => $l->templateVersion?->version_no,
            ])->values(),
            'images'          => $v->images->map(fn ($i) => ['id' => $i->id, 'url' => $i->url, 'caption' => $i->caption])->values(),
            'published_at'    => $v->published_at?->toIso8601String(),
            'change_notes'    => $v->change_notes,
        ], fn ($val, $key) => $key !== 'estimated_cost' || $withCost, ARRAY_FILTER_USE_BOTH);
    }

    protected function productOut(Product $p, bool $withCost): array
    {
        return [
            'id'                => $p->public_id,
            'code'              => $p->code,
            'name'              => $p->name,
            'category'          => $p->category?->name,
            'description'       => $p->description,
            'image'             => $p->image_url,
            'status'            => $p->status,
            'show_in_catalogue' => $p->show_in_catalogue,
            'track_stock'       => $p->track_stock,
            'variants'          => $p->variants->map(fn ($v) => $this->variantOut($v, $withCost))->values(),
        ];
    }

    protected function variantOut(ProductVariant $v, bool $withCost): array
    {
        return array_filter([
            'id' => $v->id, 'sku' => $v->sku, 'barcode' => $v->barcode, 'size' => $v->size, 'colour' => $v->colour,
            'label' => $v->label, 'price' => (float) $v->price,
            'cost_price' => $withCost && $v->cost_price !== null ? (float) $v->cost_price : null,
            'reorder_level' => $v->reorder_level !== null ? (float) $v->reorder_level : null, 'is_active' => $v->is_active,
        ], fn ($val, $key) => $key !== 'cost_price' || $withCost, ARRAY_FILTER_USE_BOTH);
    }
}
