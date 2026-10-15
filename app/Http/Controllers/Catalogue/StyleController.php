<?php

namespace App\Http\Controllers\Catalogue;

use App\Http\Controllers\Controller;
use App\Models\CatalogueCategory;
use App\Models\FashionStyle;
use App\Models\MeasurementTemplate;
use App\Models\StyleImage;
use App\Models\StyleVersion;
use App\Services\Audit\AuditLogger;
use App\Services\Catalogue\StyleService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Bespoke style catalogue with versions (SRS FR-MEA-006, scenario 31). */
class StyleController extends Controller
{
    public function __construct(protected StyleService $service)
    {
        $this->middleware('permission:View style', ['only' => ['index', 'show']]);
        $this->middleware('permission:Manage style', ['except' => ['index', 'show', 'publish']]);
        $this->middleware('permission:Publish style', ['only' => ['publish']]);
    }

    public function index(Request $request): View
    {
        $status = $request->input('status', 'active');

        $styles = FashionStyle::with(['category', 'currentVersion.images', 'draft.images'])
            ->search($request->q)
            ->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->integer('category')))
            ->when($status === 'draft', fn ($q) => $q->where('status', 'active')->whereNull('current_version_id'),
                fn ($q) => $q->when($status !== 'all', fn ($w) => $w->where('status', $status)))
            ->orderBy('name')->paginate(24)->withQueryString();

        return view('catalogue.styles.index', [
            'pagetitle'  => 'Styles',
            'styles'     => $styles,
            'status'     => $status,
            'categories' => CatalogueCategory::active()->get(),
            'counts'     => [
                'active'   => FashionStyle::where('status', 'active')->count(),
                'draft'    => FashionStyle::where('status', 'active')->whereNull('current_version_id')->count(),
                'archived' => FashionStyle::where('status', 'archived')->count(),
            ],
        ]);
    }

    public function create(): View
    {
        $style = new FashionStyle(['audience' => 'any', 'status' => 'active']);
        $draft = new StyleVersion(['version_no' => 1, 'status' => 'draft', 'fabric_unit' => 'yd']);
        $draft->setRelation('images', collect())->setRelation('templateLinks', collect());

        return view('catalogue.styles.form', $this->formData($style, $draft));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $style = $this->service->create($this->styleFields($data), $this->contentFields($data), $data['templates'] ?? []);
        $draft = $style->draft()->first();
        if ($request->hasFile('images')) {
            $this->service->addImages($draft, $request->file('images'));
        }

        return $this->afterSave($request, $style, $draft, 'Style created as a draft.');
    }

    public function show(Request $request, FashionStyle $style): View
    {
        $versions = $style->versions()->with('publisher:id,name')->get();
        $version = $request->filled('version')
            ? $versions->firstWhere('version_no', $request->integer('version')) ?? abort(404)
            : ($style->currentVersion ?? $style->draft);
        $version?->load('images', 'templateLinks.template', 'templateLinks.templateVersion');

        return view('catalogue.styles.show', [
            'pagetitle' => $style->name,
            'style'     => $style->load('category', 'creator:id,name'),
            'version'   => $version,
            'versions'  => $versions,
            'draft'     => $versions->firstWhere('status', 'draft'),
            'products'  => auth()->user()->can('View product') ? $style->products()->with('variants')->get() : collect(),
            'tz'        => CurrentTenant::get()->timezone,
        ]);
    }

    /** Edit the style's draft. Without one, go back to the style (drafts are started with newDraft). */
    public function edit(FashionStyle $style): View|RedirectResponse
    {
        abort_if($style->status === 'archived', 403, 'Restore the style before editing it.');
        $draft = $style->draft()->with('images', 'templateLinks')->first();
        if (! $draft) {
            return redirect()->route('styles.show', $style);
        }

        return view('catalogue.styles.form', $this->formData($style, $draft));
    }

    /** Start a new version: copies the live version into a draft. */
    public function newDraft(FashionStyle $style): RedirectResponse
    {
        abort_if($style->status === 'archived', 403, 'Restore the style before editing it.');
        $draft = $this->service->draftFor($style);

        return redirect()->route('styles.edit', $style)->with('success', "Draft v{$draft->version_no} started from the live version. Customers keep seeing v" . ($draft->version_no - 1) . ' until you publish.');
    }

    public function update(Request $request, FashionStyle $style): RedirectResponse
    {
        abort_if($style->status === 'archived', 403, 'Restore the style before editing it.');
        $draft = $style->draft()->firstOrFail();
        $data = $this->validated($request, $style);

        $before = $style->only(['name', 'category_id', 'audience', 'show_in_catalogue']);
        $style->update($this->styleFields($data));
        if ($style->wasChanged()) {
            AuditLogger::log('style.updated', $style, $before, $style->only(array_keys($before)));
        }
        $this->service->updateDraft($draft, $this->contentFields($data), $data['templates'] ?? []);
        if ($request->hasFile('images')) {
            $this->service->addImages($draft, $request->file('images'));
        }

        return $this->afterSave($request, $style, $draft, 'Draft saved.');
    }

    public function publish(Request $request, FashionStyle $style): RedirectResponse
    {
        $draft = $style->draft()->firstOrFail();
        $data = $request->validate(['change_notes' => 'nullable|string|max:255']);
        $this->service->publish($draft, $data['change_notes'] ?? null);

        return redirect()->route('styles.show', $style)->with('success', "Version {$draft->version_no} is now live.");
    }

    public function discardDraft(FashionStyle $style): RedirectResponse
    {
        $this->service->discardDraft($style->draft()->firstOrFail());

        return redirect()->route('styles.show', $style)->with('success', 'Draft discarded.');
    }

    public function archive(Request $request, FashionStyle $style): RedirectResponse
    {
        $to = $style->status === 'archived' ? 'active' : 'archived';
        $style->update(['status' => $to]);
        AuditLogger::log('style.' . ($to === 'archived' ? 'archived' : 'restored'), $style);

        return back()->with('success', $to === 'archived' ? 'Style archived. Existing orders keep it; it can no longer be chosen.' : 'Style restored.');
    }

    public function destroyImage(StyleImage $image): RedirectResponse
    {
        $this->service->removeImage($image);

        return back()->with('success', 'Picture removed from the draft.');
    }

    public function coverImage(StyleImage $image): RedirectResponse
    {
        $this->service->makeCover($image);

        return back()->with('success', 'Cover picture set.');
    }

    /* ------------------------------------------------------------------ */

    protected function afterSave(Request $request, FashionStyle $style, StyleVersion $draft, string $message): RedirectResponse
    {
        if ($request->input('action') === 'publish') {
            abort_unless($request->user()->can('Publish style'), 403);
            $this->service->publish($draft->fresh(), $request->input('change_notes'));

            return redirect()->route('styles.show', $style)->with('success', "Published version {$draft->version_no}.");
        }

        return redirect()->route('styles.edit', $style)->with('success', $message);
    }

    protected function validated(Request $request, ?FashionStyle $style = null): array
    {
        return $request->validate([
            'name'                   => 'required|string|max:120',
            'code'                   => [$style ? 'prohibited' : 'nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-_\/]+$/',
                                         Rule::unique('fashion_styles', 'code')->where('tenant_id', CurrentTenant::id())],
            'category_id'            => ['nullable', 'integer', Rule::exists('catalogue_categories', 'id')->where('tenant_id', CurrentTenant::id())],
            'audience'               => ['required', Rule::in(array_keys(FashionStyle::AUDIENCES))],
            'show_in_catalogue'      => 'nullable|boolean',
            'description'            => 'nullable|string|max:5000',
            'technical_notes'        => 'nullable|string|max:5000',
            'base_price'             => 'nullable|numeric|min:0|max:999999999',
            'estimated_cost'         => 'nullable|numeric|min:0|max:999999999',
            'lead_time_days'         => 'nullable|integer|min:0|max:365',
            'fabric_quantity'        => 'nullable|numeric|min:0|max:999',
            'fabric_unit'            => 'nullable|in:yd,m',
            'fabric_notes'           => 'nullable|string|max:255',
            'price_options'          => 'nullable|array|max:20',
            'price_options.*.label'  => 'nullable|string|max:80',
            'price_options.*.amount' => 'nullable|numeric|min:0|max:999999999',
            'templates'              => 'nullable|array',
            'templates.*'            => 'integer',
            'images'                 => 'nullable|array|max:' . StyleService::MAX_IMAGES,
            'images.*'               => 'image|mimes:jpg,jpeg,png,webp|max:4096',
            'action'                 => 'nullable|in:save,publish',
            'change_notes'           => 'nullable|string|max:255',
        ]);
    }

    protected function styleFields(array $data): array
    {
        return array_filter([
            'name'              => $data['name'],
            'code'              => $data['code'] ?? null,
            'category_id'       => $data['category_id'] ?? null,
            'audience'          => $data['audience'],
            'show_in_catalogue' => (bool) ($data['show_in_catalogue'] ?? false),
        ], fn ($v, $k) => $k !== 'code' || $v, ARRAY_FILTER_USE_BOTH);
    }

    protected function contentFields(array $data): array
    {
        $fields = collect(StyleVersion::CONTENT)->mapWithKeys(fn ($f) => [$f => $data[$f] ?? null])->all();
        $fields['price_options'] = $data['price_options'] ?? [];
        if (! auth()->user()->can('View costing')) {
            unset($fields['estimated_cost']);     // keep what's there; this user can't see it
        }

        return $fields;
    }

    protected function formData(FashionStyle $style, StyleVersion $draft): array
    {
        return [
            'pagetitle'  => $style->exists ? 'Edit ' . $style->name : 'New style',
            'style'      => $style,
            'draft'      => $draft,
            'categories' => CatalogueCategory::active()->get(),
            'templates'  => MeasurementTemplate::usable()->orderBy('name')->get(),
            'linked'     => $draft->templateLinks->pluck('template_id')->all(),
        ];
    }
}
