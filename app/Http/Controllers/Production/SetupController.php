<?php

namespace App\Http\Controllers\Production;

use App\Http\Controllers\Controller;
use App\Models\CatalogueCategory;
use App\Models\ProductionStage;
use App\Models\ProductionWorkflow;
use App\Models\QualityChecklist;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Workflows and quality checklists (SRS FR-PRD-001, FR-PRD-006). Changing a
 * workflow affects only garments planned afterwards: planned tasks are copies.
 */
class SetupController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:Manage production workflow');
    }

    public function index(): View
    {
        return view('production.setup.index', [
            'pagetitle'  => 'Production setup',
            'workflows'  => ProductionWorkflow::with('stages.role', 'stages.checklist', 'category')->orderByDesc('is_active')->orderBy('name')->get(),
            'checklists' => QualityChecklist::orderByDesc('is_active')->orderBy('name')->get(),
        ]);
    }

    public function createWorkflow(): View
    {
        return $this->form(new ProductionWorkflow(['line_kinds' => ['bespoke'], 'is_active' => true]));
    }

    public function editWorkflow(ProductionWorkflow $workflow): View
    {
        return $this->form($workflow->load('stages'));
    }

    protected function form(ProductionWorkflow $w): View
    {
        return view('production.setup.workflow', [
            'pagetitle'  => $w->exists ? 'Edit workflow' : 'New workflow',
            'workflow'   => $w,
            'rows'       => old('stages', $w->exists ? $w->stages->map(fn ($s) => $s->only(['id', 'name', 'type', 'duration_hours', 'role_id', 'requires_previous', 'customer_visible', 'checklist_id', 'instructions']))->all()
                : [['name' => '', 'type' => 'work', 'duration_hours' => 8, 'requires_previous' => true, 'customer_visible' => false]]),
            'roles'      => Role::ofCurrentTenant()->orderBy('name')->get(['id', 'name']),
            'checklists' => QualityChecklist::active()->orderBy('name')->get(['id', 'name']),
            'categories' => CatalogueCategory::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeWorkflow(Request $request): RedirectResponse
    {
        $w = $this->saveWorkflow(new ProductionWorkflow, $request);

        return redirect()->route('production.setup')->with('success', "Workflow “{$w->name}” created.");
    }

    public function updateWorkflow(Request $request, ProductionWorkflow $workflow): RedirectResponse
    {
        $w = $this->saveWorkflow($workflow, $request);

        return redirect()->route('production.setup')->with('success', "Workflow “{$w->name}” saved. Garments already planned keep their tasks.");
    }

    protected function saveWorkflow(ProductionWorkflow $w, Request $request): ProductionWorkflow
    {
        $tenant = CurrentTenant::id();
        $data = $request->validate([
            'name'                       => 'required|string|max:120',
            'description'                => 'nullable|string|max:255',
            'line_kinds'                 => 'required|array|min:1',
            'line_kinds.*'               => Rule::in(array_keys(ProductionWorkflow::LINE_KINDS)),
            'category_id'                => ['nullable', Rule::exists('catalogue_categories', 'id')->where('tenant_id', $tenant)],
            'is_default'                 => 'nullable|boolean',
            'is_active'                  => 'nullable|boolean',
            'stages'                     => 'required|array|min:1',
            'stages.*.id'                => 'nullable|integer',
            'stages.*.name'              => 'nullable|string|max:80',
            'stages.*.type'              => ['required', Rule::in(array_keys(ProductionStage::TYPES))],
            'stages.*.duration_hours'    => 'required|integer|min:1|max:2000',
            'stages.*.role_id'           => 'nullable|integer',
            'stages.*.requires_previous' => 'nullable|boolean',
            'stages.*.customer_visible'  => 'nullable|boolean',
            'stages.*.checklist_id'      => 'nullable|integer',
            'stages.*.instructions'      => 'nullable|string|max:1000',
        ]);
        $stages = collect($data['stages'])->filter(fn ($s) => trim($s['name'] ?? '') !== '')->values();
        if ($stages->isEmpty()) {
            throw ValidationException::withMessages(['stages' => 'Add at least one stage.']);
        }
        if ($stages->contains(fn ($s) => $s['type'] === 'qc' && empty($s['checklist_id']))) {
            throw ValidationException::withMessages(['stages' => 'Choose a checklist for each quality check stage.']);
        }
        $roleIds = Role::ofCurrentTenant()->pluck('id')->all();
        $checklistIds = QualityChecklist::pluck('id')->all();

        return DB::transaction(function () use ($w, $data, $stages, $roleIds, $checklistIds) {
            $before = $w->exists ? ['stages' => $w->stages->pluck('name')->all()] + $w->only(['name', 'line_kinds', 'is_active']) : null;
            $w->fill([
                'name' => $data['name'], 'description' => $data['description'] ?? null, 'line_kinds' => array_values($data['line_kinds']),
                'category_id' => $data['category_id'] ?? null, 'is_default' => (bool) ($data['is_default'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? false),
            ])->save();

            $keep = [];
            foreach ($stages as $i => $s) {
                $attrs = [
                    'name' => trim($s['name']), 'type' => $s['type'], 'position' => $i + 1, 'duration_hours' => (int) $s['duration_hours'],
                    'role_id' => in_array((int) ($s['role_id'] ?? 0), $roleIds, true) ? (int) $s['role_id'] : null,
                    'requires_previous' => (bool) ($s['requires_previous'] ?? false), 'customer_visible' => (bool) ($s['customer_visible'] ?? false),
                    'checklist_id' => $s['type'] === 'qc' && in_array((int) ($s['checklist_id'] ?? 0), $checklistIds, true) ? (int) $s['checklist_id'] : null,
                    'instructions' => $s['instructions'] ?? null,
                ];
                $stage = ! empty($s['id']) ? $w->stages()->find($s['id']) : null;
                $stage ? $stage->update($attrs) : $stage = $w->stages()->create($attrs);
                $keep[] = $stage->id;
            }
            // Removed stages go; tasks already planned from them keep their own copy.
            $w->stages()->whereNotIn('id', $keep)->delete();
            AuditLogger::log($before ? 'production.workflow_updated' : 'production.workflow_created', $w, $before,
                ['stages' => $stages->pluck('name')->all()] + $w->only(['name', 'line_kinds', 'is_active']));

            return $w;
        });
    }

    public function storeChecklist(Request $request): RedirectResponse
    {
        $c = $this->saveChecklist(new QualityChecklist, $request);

        return back()->with('success', "Checklist “{$c->name}” created.");
    }

    public function updateChecklist(Request $request, QualityChecklist $checklist): RedirectResponse
    {
        $c = $this->saveChecklist($checklist, $request);

        return back()->with('success', "Checklist “{$c->name}” saved.");
    }

    /** Items: one per line; start a line with "?" for an optional item. */
    protected function saveChecklist(QualityChecklist $c, Request $request): QualityChecklist
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'items' => 'required|string|max:5000', 'is_active' => 'nullable|boolean']);
        $items = collect(preg_split('/\r?\n/', $data['items']))->map(fn ($l) => trim($l))->filter()
            ->map(fn ($l) => str_starts_with($l, '?') ? ['label' => trim(substr($l, 1)), 'required' => false] : ['label' => $l, 'required' => true])
            ->filter(fn ($i) => $i['label'] !== '')->take(40)->values()->all();
        if (! $items) {
            throw ValidationException::withMessages(['items' => 'Add at least one item.']);
        }
        $c->fill(['name' => $data['name'], 'items' => $items, 'is_active' => $request->boolean('is_active', true)])->save();
        AuditLogger::log('production.checklist_saved', $c, after: ['name' => $c->name, 'items' => count($items)]);

        return $c;
    }
}
