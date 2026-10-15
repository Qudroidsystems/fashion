<?php

namespace App\Http\Controllers\Measurements;

use App\Http\Controllers\Controller;
use App\Models\MeasurementParameter;
use App\Models\MeasurementSession;
use App\Models\MeasurementTemplate;
use App\Services\Measurements\TemplateService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Versioned measurement templates (SRS FR-MEA-004/005). */
class TemplateController extends Controller
{
    public function __construct(protected TemplateService $service)
    {
        $this->middleware('permission:Manage measurement templates');
    }

    public function index(): View
    {
        return view('measurements.templates.index', [
            'pagetitle' => 'Measurement templates',
            'templates' => MeasurementTemplate::with(['currentVersion' => fn ($q) => $q->withCount('parameters'), 'draft'])
                ->withCount('versions')->orderByDesc('is_active')->orderBy('name')->get(),
            'parameters' => MeasurementParameter::where('is_active', true)->orderBy('sort_order')->get(),
            'audiences'  => MeasurementTemplate::AUDIENCES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'             => 'required|string|max:100',
            'code'             => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('measurement_templates', 'code')->where('tenant_id', CurrentTenant::id())],
            'garment_category' => 'nullable|string|max:60',
            'audience'         => ['required', Rule::in(array_keys(MeasurementTemplate::AUDIENCES))],
            'description'      => 'nullable|string|max:1000',
            'parameters'       => 'nullable|array',
            'parameters.*'     => 'integer',
        ]);

        $items = collect($data['parameters'] ?? [])->map(fn ($id) => ['parameter_id' => $id, 'is_required' => true])->all();
        $template = $this->service->create(collect($data)->except('parameters')->all(), $items);

        return redirect()->route('measurement-templates.show', $template)->with('success', 'Template created as a draft. Arrange the measurements, then publish.');
    }

    public function show(MeasurementTemplate $template): View
    {
        $draft = $template->draft()->with('parameters')->first();
        $current = $template->currentVersion()->with('parameters')->first();

        return view('measurements.templates.show', [
            'pagetitle'  => $template->name,
            'template'   => $template,
            'draft'      => $draft,
            'current'    => $current,
            'versions'   => $template->versions()->withCount('parameters')->get(),
            'parameters' => MeasurementParameter::where('is_active', true)->orderBy('sort_order')->get(),
            'usage'      => MeasurementSession::whereIn('template_version_id', $template->versions()->pluck('id'))
                ->selectRaw('template_version_id, count(*) c')->groupBy('template_version_id')->pluck('c', 'template_version_id'),
            'audiences'  => MeasurementTemplate::AUDIENCES,
        ]);
    }

    public function update(Request $request, MeasurementTemplate $template): RedirectResponse
    {
        $data = $request->validate([
            'name'             => 'required|string|max:100',
            'garment_category' => 'nullable|string|max:60',
            'audience'         => ['required', Rule::in(array_keys(MeasurementTemplate::AUDIENCES))],
            'description'      => 'nullable|string|max:1000',
            'is_active'        => 'nullable|boolean',
        ]);
        $template->update($data + ['is_active' => $request->boolean('is_active')]);

        return back()->with('success', 'Template details saved.');
    }

    /** Start (or continue) editing: creates the next draft version from the current one. */
    public function edit(MeasurementTemplate $template): RedirectResponse
    {
        $this->service->draftFor($template);

        return redirect()->route('measurement-templates.show', $template)->with('success', 'Draft ready. Changes only take effect when you publish.');
    }

    public function saveDraft(Request $request, MeasurementTemplate $template): RedirectResponse
    {
        $data = $request->validate([
            'items'                => 'nullable|array',
            'items.*.parameter_id' => 'required|integer',
            'items.*.include'      => 'nullable|boolean',
            'items.*.is_required'  => 'nullable|boolean',
            'items.*.guidance'     => 'nullable|string|max:255',
            'items.*.order'        => 'nullable|integer',
        ]);

        $items = collect($data['items'] ?? [])
            ->filter(fn ($i) => ! empty($i['include']))
            ->sortBy(fn ($i) => (int) ($i['order'] ?? 999))
            ->map(fn ($i) => ['parameter_id' => $i['parameter_id'], 'is_required' => ! empty($i['is_required']), 'guidance' => $i['guidance'] ?? null])
            ->values()->all();

        $this->service->saveItems($this->service->draftFor($template), $items);

        if ($request->input('action') === 'publish') {
            $this->service->publish($template->draft()->first(), $request->input('change_notes'));

            return back()->with('success', 'Published. New sessions use this version; existing sessions keep theirs.');
        }

        return back()->with('success', 'Draft saved.');
    }
}
