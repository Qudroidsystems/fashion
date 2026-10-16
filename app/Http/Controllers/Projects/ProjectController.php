<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\FashionStyle;
use App\Models\MeasurementSession;
use App\Models\Project;
use App\Models\ProjectBudgetLine;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Projects\ProjectService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Weddings, events and bulk projects (SRS FR-PRJ-001…007). */
class ProjectController extends Controller
{
    public function __construct(protected ProjectService $projects)
    {
        $this->middleware('permission:View project', ['only' => ['index', 'show']]);
        $this->middleware('permission:Manage project', ['except' => ['index', 'show']]);
    }

    public function index(Request $request): View
    {
        $u = $request->user();
        $status = $request->input('status', 'open');
        $list = Project::visibleTo($u)->with('customer:id,first_name,last_name', 'manager:id,name')
            ->when($status === 'open', fn ($q) => $q->whereIn('status', ['planning', 'active']))
            ->when(in_array($status, array_keys(Project::STATUSES), true), fn ($q) => $q->where('status', $status))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$request->q}%")->orWhere('number', 'like', "%{$request->q}%")
                ->orWhereHas('customer', fn ($c) => $c->search($request->q))))
            ->orderByRaw('event_date is null')->orderBy('event_date')->paginate(20)->withQueryString();
        $list->getCollection()->each(function (Project $p) {
            $lines = $this->projects->garmentLines($p)->where('status', '!=', 'cancelled')->get(['status']);
            $p->garment_total = $lines->count();
            $p->garment_ready = $lines->whereIn('status', ['ready', 'delivered'])->count();
            $p->participant_count = $p->participants()->where('status', 'active')->count();
        });

        return view('projects.index', [
            'pagetitle' => 'Projects', 'projects' => $list, 'status' => $status,
            'counts' => Project::visibleTo($u)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('projects.form', $this->formData(new Project([
            'type' => $request->input('type', 'wedding'), 'manager_id' => $request->user()->id,
            'customer_id' => Customer::where('public_id', $request->customer)->value('id'),
        ])));
    }

    public function store(Request $request): RedirectResponse
    {
        $project = $this->projects->create($this->validated($request), $request->user(), $request->boolean('starter_milestones', true));

        return redirect()->route('projects.show', $project)->with('success', 'Project created. Add the participants next.');
    }

    public function edit(Project $project): View
    {
        return view('projects.form', $this->formData($project));
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $this->projects->update($project, $this->validated($request));

        return redirect()->route('projects.show', $project)->with('success', 'Project saved.');
    }

    public function show(Request $request, Project $project): View
    {
        abort_unless(Project::visibleTo($request->user())->whereKey($project->id)->exists(), 404);
        $project->load('customer', 'manager', 'branch', 'participants.customer', 'milestones', 'updates.author', 'budgetLines', 'expenses.recorder');
        $lines = $this->projects->garmentLines($project)->with('order:id,public_id,number,status,customer_id', 'measurementSession:id,public_id,created_at,status', 'style:id,name')
            ->orderBy('sort_order')->get();
        $customerIds = $project->participants->pluck('customer_id')->filter()->push($project->customer_id)->unique();

        return view('projects.show', [
            'pagetitle'  => $project->name,
            'project'    => $project,
            'summary'    => $this->projects->summary($project),
            'garments'   => $lines->groupBy('participant_id'),
            'sessions'   => MeasurementSession::whereIn('customer_id', $customerIds)->whereIn('status', ['approved'])
                ->with('templateVersion.template:id,name')->latest('id')->get()->groupBy('customer_id'),
            'styles'     => FashionStyle::whereNotNull('current_version_id')->where('status', 'active')->with('currentVersion')->orderBy('name')->get(),
            'variants'   => ProductVariant::with('product:id,name')->where('is_active', true)->whereHas('product', fn ($p) => $p->where('status', 'active'))->limit(300)->get(),
            'trackingUrl' => $project->trackingOpen() && ($t = $project->trackingToken()) ? route('projects.track', $t) : null,
            'tz'         => CurrentTenant::get()->timezone,
            'canMoney'   => $request->user()->can('View payment'),
            'canCost'    => $request->user()->can('View costing') || $request->user()->can('View financial reports'),
            'canManage'  => $request->user()->can('Manage project'),
            'canPublish' => $request->user()->can('Publish project update'),
            'categories' => ProjectBudgetLine::CATEGORIES,
        ]);
    }

    public function confirm(Request $request, Project $project): RedirectResponse
    {
        $this->projects->confirm($project, $request->user());

        return back()->with('success', 'Project confirmed. The orders are confirmed and ready for production planning.');
    }

    public function cancel(Request $request, Project $project): RedirectResponse
    {
        abort_unless($request->user()->can('Cancel order'), 403, 'Cancelling a project cancels its orders, which needs “Cancel order”.');
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $this->projects->cancel($project, $data['reason'], $request->user());

        return back()->with('success', 'Project cancelled with its open orders.');
    }

    public function complete(Project $project): RedirectResponse
    {
        $this->projects->complete($project);

        return back()->with('success', 'Project completed. Everything has been handed over.');
    }

    public function tracking(Request $request, Project $project): RedirectResponse
    {
        if ($request->input('action') === 'revoke') {
            $this->projects->disableTracking($project);

            return back()->with('success', 'Tracking link switched off. The old link no longer works.');
        }
        $data = $request->validate(['days' => 'nullable|integer|min:1|max:730']);
        $this->projects->enableTracking($project, $data['days'] ?? null);

        return back()->with('success', 'New tracking link ready to share. Any earlier link has stopped working.');
    }

    /* ------------------------------- helpers ------------------------------- */

    protected function validated(Request $request): array
    {
        $tenant = CurrentTenant::id();
        $data = $request->validate([
            'name'            => 'required|string|max:160',
            'type'            => ['required', Rule::in(array_keys(Project::TYPES))],
            'customer_id'     => ['required', Rule::exists('customers', 'id')->where('tenant_id', $tenant)],
            'manager_id'      => 'nullable|integer',
            'branch_id'       => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'event_date'      => 'nullable|date',
            'delivery_date'   => 'nullable|date',
            'venue'           => 'nullable|string|max:255',
            'contract_amount' => 'nullable|numeric|min:0|max:10000000000',
            'deposit_amount'  => 'nullable|numeric|min:0|max:10000000000',
            'description'     => 'nullable|string|max:2000',
            'client_message'  => 'nullable|string|max:1000',
            'internal_notes'  => 'nullable|string|max:2000',
        ]);
        if (! empty($data['manager_id']) && ! User::inCurrentTenant()->whereKey($data['manager_id'])->exists()) {
            $data['manager_id'] = null;
        }
        if (! empty($data['delivery_date']) && ! empty($data['event_date']) && $data['delivery_date'] > $data['event_date']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['delivery_date' => 'Hand over on or before the event date.']);
        }
        $data['contract_amount'] = (float) ($data['contract_amount'] ?? 0);
        $data['deposit_amount'] = (float) ($data['deposit_amount'] ?? 0);

        return $data;
    }

    protected function formData(Project $project): array
    {
        return [
            'pagetitle' => $project->exists ? 'Edit ' . $project->name : 'New project',
            'project'   => $project,
            'customers' => Customer::active()->orderBy('first_name')->limit(1000)->get(['id', 'public_id', 'first_name', 'last_name', 'customer_number', 'phone']),
            'staff'     => User::inCurrentTenant()->orderBy('name')->get(['users.id', 'name']),
            'branches'  => Branch::active()->orderByDesc('is_main')->orderBy('name')->get(),
        ];
    }
}
