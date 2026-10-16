<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FashionStyle;
use App\Models\Project;
use App\Models\ProjectBudgetLine;
use App\Services\Projects\ProjectService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * SRS §8.2 Projects:
 *   GET/POST /projects · GET /projects/{id}
 *   POST /projects/{id}/participants  (one, or "people": [...])
 *   POST /projects/{id}/garments      {participants:[ids], style|sku|description, options[], quantity}
 *   POST /projects/{id}/milestones    · POST /projects/{id}/milestones/{mid}/publish
 *   POST /projects/{id}/updates       {title, body, customer_visible, publish}
 *   GET  /public/project-tracking/{token}   (no login; published content only)
 * Money appears only with "View payment"; costs only with "View costing" / "View financial reports".
 */
class ProjectController extends Controller
{
    public function __construct(protected ProjectService $projects)
    {
        $this->middleware('permission:View project', ['only' => ['index', 'show']]);
        $this->middleware('permission:Manage project', ['only' => ['store', 'storeParticipants', 'storeGarments', 'storeMilestone', 'storeUpdate']]);
        $this->middleware('permission:Publish project update', ['only' => ['publishMilestone']]);
    }

    public function index(Request $request): JsonResponse
    {
        $page = Project::visibleTo($request->user())->with('customer:id,public_id,first_name,last_name')
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', $request->status)), fn ($q) => $q->whereIn('status', ['planning', 'active']))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$request->q}%")->orWhere('number', 'like', "%{$request->q}%")))
            ->orderByRaw('event_date is null')->orderBy('event_date')->paginate(min(100, (int) $request->input('per_page', 25)));

        return response()->json([
            'success' => true,
            'data' => $page->getCollection()->map(fn ($p) => $this->brief($p)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:160', 'type' => ['required', Rule::in(array_keys(Project::TYPES))], 'customer' => 'required|string',
            'event_date' => 'nullable|date', 'delivery_date' => 'nullable|date', 'venue' => 'nullable|string|max:255',
            'contract_amount' => 'nullable|numeric|min:0', 'deposit_amount' => 'nullable|numeric|min:0', 'description' => 'nullable|string|max:2000',
            'starter_milestones' => 'nullable|boolean',
        ]);
        $data['customer_id'] = Customer::where('public_id', $data['customer'])->orWhere('customer_number', $data['customer'])->value('id')
            ?? throw ValidationException::withMessages(['customer' => 'Unknown customer.']);
        $p = $this->projects->create($data, $request->user(), $request->boolean('starter_milestones', true));

        return response()->json(['success' => true, 'data' => $this->detail($p->fresh(), $request)], 201);
    }

    public function show(Request $request, string $project): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->detail($this->find($project, $request), $request)]);
    }

    public function storeParticipants(Request $request, string $project): JsonResponse
    {
        $p = $this->find($project, $request);
        $rules = ['name' => 'required|string|max:120', 'phone' => 'nullable|string|max:30', 'email' => 'nullable|email|max:160',
            'role' => 'nullable|string|max:60', 'group_name' => 'nullable|string|max:60', 'pays' => 'nullable|in:client,self'];
        $people = $request->has('people')
            ? $request->validate(['people' => 'required|array|min:1|max:200'] + collect($rules)->mapWithKeys(fn ($r, $k) => ["people.*.{$k}" => $r])->all())['people']
            : [$request->validate($rules)];
        $added = collect($people)->map(fn ($row) => $this->projects->addParticipant($p, $row + ['pays' => 'client']));

        return response()->json(['success' => true, 'data' => $added->map(fn ($x) => $this->participant($x))], 201);
    }

    public function storeGarments(Request $request, string $project): JsonResponse
    {
        $p = $this->find($project, $request);
        $data = $request->validate([
            'participants' => 'required|array|min:1', 'participants.*' => 'integer',
            'style' => 'nullable|string', 'sku' => 'nullable|string', 'description' => 'nullable|string|max:255',
            'options' => 'nullable|array', 'options.*' => 'string', 'quantity' => 'nullable|numeric|min:1|max:999',
            'unit_price' => 'nullable|numeric|min:0', 'instructions' => 'nullable|string|max:2000',
        ]);
        $spec = array_intersect_key($data, array_flip(['description', 'options', 'quantity', 'unit_price', 'instructions']));
        if (! empty($data['style'])) {
            $spec += ['kind' => 'bespoke', 'style_id' => FashionStyle::where('public_id', $data['style'])->orWhere('code', $data['style'])->value('id')
                ?? throw ValidationException::withMessages(['style' => 'Unknown style.'])];
        } elseif (! empty($data['sku'])) {
            $spec += ['kind' => 'ready_made', 'product_variant_id' => \App\Models\ProductVariant::where('sku', $data['sku'])->orWhere('barcode', $data['sku'])->value('id')
                ?? throw ValidationException::withMessages(['sku' => 'Unknown SKU.'])];
        } else {
            $spec['kind'] = 'service';
        }
        $lines = $this->projects->addGarments($p, $p->participants()->whereIn('id', $data['participants'])->get(), $spec, $request->user());

        return response()->json(['success' => true, 'data' => $lines->map(fn ($l) => $this->garment($l, $request))->values()], 201);
    }

    public function storeMilestone(Request $request, string $project): JsonResponse
    {
        $p = $this->find($project, $request);
        $data = $request->validate(['name' => 'required|string|max:120', 'due_date' => 'nullable|date', 'notes' => 'nullable|string|max:500', 'customer_visible' => 'nullable|boolean']);
        $m = $this->projects->saveMilestone($p, null, $data + ['customer_visible' => $request->boolean('customer_visible')]);

        return response()->json(['success' => true, 'data' => $this->milestone($m)], 201);
    }

    public function publishMilestone(Request $request, string $project, int $milestone): JsonResponse
    {
        $p = $this->find($project, $request);
        $m = $this->projects->publishMilestone($p->milestones()->findOrFail($milestone), $request->user());

        return response()->json(['success' => true, 'data' => $this->milestone($m)]);
    }

    public function storeUpdate(Request $request, string $project): JsonResponse
    {
        $p = $this->find($project, $request);
        $data = $request->validate(['title' => 'nullable|string|max:160', 'body' => 'required|string|max:5000', 'customer_visible' => 'nullable|boolean',
            'publish' => 'nullable|boolean', 'images' => 'nullable|array|max:8', 'images.*' => 'image|max:5120']);
        $u = $this->projects->addUpdate($p, $data + ['customer_visible' => $request->boolean('customer_visible')], $request->file('images', []), $request->user());
        if ($request->boolean('publish')) {
            abort_unless($request->user()->can('Publish project update'), 403, 'Publishing needs “Publish project update”.');
            $u = $this->projects->publishUpdate($u, $request->user());
        }

        return response()->json(['success' => true, 'data' => [
            'id' => $u->id, 'title' => $u->title, 'body' => $u->body, 'status' => $u->status, 'customer_visible' => $u->customer_visible, 'images' => $u->imageUrls(),
        ]], 201);
    }

    /** The client's view for the customer app (FR-PRJ-006, scenario 39). */
    public function tracking(string $token): JsonResponse
    {
        $project = $this->projects->findByToken($token);
        abort_unless($project && $project->trackingOpen(), 404);

        return CurrentTenant::run($project->tenant, function ($house) use ($project) {
            $v = $this->projects->publicView($project);

            return response()->json(['success' => true, 'data' => [
                'house' => ['name' => $house->name, 'logo' => $house->logo_url, 'phone' => $house->phone],
                'name' => $v['name'], 'type' => $v['type'], 'event_date' => $v['event_date']?->toDateString(), 'venue' => $v['venue'],
                'message' => $v['message'], 'status' => $v['status'], 'garments' => $v['garments'],
                'milestones' => $v['milestones']->map(fn ($m) => ['name' => $m->name, 'status' => $m->status, 'due_date' => $m->due_date?->toDateString(),
                    'completed_at' => $m->completed_at?->toIso8601String(), 'notes' => $m->notes]),
                'updates' => $v['updates']->map(fn ($u) => ['title' => $u->title, 'body' => $u->body, 'published_at' => $u->published_at?->toIso8601String(), 'images' => $u->imageUrls()]),
            ]]);
        });
    }

    /* ------------------------------- helpers ------------------------------- */

    protected function find(string $ref, Request $request): Project
    {
        return Project::visibleTo($request->user())->where(fn ($w) => $w->where('public_id', $ref)->orWhere('number', $ref))->firstOrFail();
    }

    protected function brief(Project $p): array
    {
        return [
            'id' => $p->public_id, 'number' => $p->number, 'name' => $p->name, 'type' => $p->type, 'status' => $p->status,
            'client' => $p->customer ? ['id' => $p->customer->public_id, 'name' => $p->customer->name] : null,
            'event_date' => $p->event_date?->toDateString(), 'delivery_date' => $p->delivery_date?->toDateString(),
        ];
    }

    protected function detail(Project $p, Request $request): array
    {
        $p->load('participants', 'milestones', 'customer');
        $s = $this->projects->summary($p);
        $lines = $this->projects->garmentLines($p)->with('order:id,public_id,number,status')->get()->groupBy('participant_id');
        $u = $request->user();

        return $this->brief($p) + [
            'venue' => $p->venue, 'description' => $p->description,
            'garments' => $s['garments'],
            'participants' => $p->participants->map(fn ($x) => $this->participant($x) + ['garments' => $lines->get($x->id, collect())->map(fn ($l) => $this->garment($l, $request))->values()]),
            'milestones' => $p->milestones->map(fn ($m) => $this->milestone($m)),
        ] + ($u->can('View payment') ? ['money' => $s['money'], 'orders' => $s['orders']->map(fn ($o) => ['id' => $o->public_id, 'number' => $o->number, 'status' => $o->status, 'balance' => max(0, $o->balance())])] : [])
          + ($u->can('View costing') || $u->can('View financial reports') ? ['costs' => $s['costs']] : []);
    }

    protected function participant($x): array
    {
        return ['id' => $x->id, 'name' => $x->name, 'phone' => $x->phone, 'role' => $x->role, 'group' => $x->group_name, 'pays' => $x->pays, 'status' => $x->status];
    }

    protected function garment($l, Request $request): array
    {
        return ['line' => $l->id, 'description' => $l->description, 'quantity' => $l->quantity, 'status' => $l->status,
            'order' => $l->order?->number, 'measured' => (bool) $l->measurement_session_id]
            + ($request->user()->can('View payment') ? ['line_total' => $l->line_total] : []);
    }

    protected function milestone($m): array
    {
        return ['id' => $m->id, 'name' => $m->name, 'due_date' => $m->due_date?->toDateString(), 'status' => $m->status,
            'customer_visible' => $m->customer_visible, 'published' => $m->isPublished()];
    }
}
