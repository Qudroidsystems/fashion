<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Fitting;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductionTask;
use App\Models\User;
use App\Services\Production\DeliveryService;
use App\Services\Production\FittingService;
use App\Services\Production\ProductionService;
use App\Services\Production\QualityService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * SRS §8.2 Production (staff app):
 *   GET  /production/tasks (?who=mine|all|unassigned, status, order) · GET /production/tasks/{id}
 *   POST /production/tasks/{id}/events   {type: start|hold|complete|note, note?, evidence? (multipart)}
 *   POST /fittings · POST /fittings/{id}/record
 *   POST /quality-checks  {task, answers:[{passed,note}], issues?}
 *   POST /alteration-tasks {order, line?, name, reason, responsible_party, cost_treatment, cost?}
 *   POST /deliveries      {order, lines:[line ids], method, recipient_name, confirmation, …}
 * Orders are referenced by public id or number, tasks and fittings by public id or number.
 */
class ProductionController extends Controller
{
    public function __construct(
        protected ProductionService $production,
        protected FittingService $fittings,
        protected QualityService $quality,
        protected DeliveryService $deliveries,
    ) {
        $this->middleware('permission:View production', ['only' => ['tasks', 'task']]);
        $this->middleware('permission:Manage fitting', ['only' => ['storeFitting', 'recordFitting']]);
        $this->middleware('permission:Record quality check', ['only' => ['storeQualityCheck']]);
        $this->middleware('permission:Manage production workflow', ['only' => ['storeAlteration']]);
        $this->middleware('permission:Manage delivery', ['only' => ['storeDelivery']]);
    }

    public function tasks(Request $request): JsonResponse
    {
        $u = $request->user();
        $who = $request->input('who', $u->can('Manage production workflow') ? 'all' : 'mine');
        $page = ProductionTask::with('order:id,public_id,number,title,promised_date,priority,risk_level', 'line:id,description,wearer', 'assignee:id,name')
            ->whereHas('order', fn ($o) => $o->whereNotIn('status', ['cancelled', 'draft']))
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', $request->status)), fn ($q) => $q->open())
            ->when($who === 'mine', fn ($q) => $q->where(fn ($w) => $w->where('assigned_to', $u->id)->orWhereNull('assigned_to')))
            ->when($who === 'unassigned', fn ($q) => $q->whereNull('assigned_to'))
            ->when($request->filled('order'), fn ($q) => $q->where('order_id', $this->order($request->order)->id))
            ->orderByRaw("case priority when 'rush' then 0 when 'high' then 1 else 2 end")->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('id')
            ->paginate(min(100, (int) $request->input('per_page', 25)));

        return response()->json([
            'success' => true,
            'data'    => $page->getCollection()->map(fn ($t) => $this->taskData($t, $u)),
            'meta'    => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function task(Request $request, string $task): JsonResponse
    {
        $t = $this->findTask($task)->load('events.user:id,name', 'checklist', 'order', 'line', 'assignee:id,name');

        return response()->json(['success' => true, 'data' => $this->taskData($t, $request->user()) + [
            'instructions' => $t->instructions,
            'checklist' => $t->type === 'qc' ? ($t->checklist?->items ?: [['label' => 'Garment is acceptable', 'required' => true]]) : null,
            'waiting_on' => $t->status === 'blocked' ? $this->production->waitingOn($t) : null,
            'events' => $t->events->sortByDesc('id')->values()->map(fn ($e) => [
                'type' => $e->type, 'from' => $e->from_status, 'to' => $e->to_status, 'note' => $e->note,
                'by' => $e->user?->name, 'at' => $e->created_at?->toIso8601String(),
                'evidence_url' => $e->evidence_path ? asset('storage/' . $e->evidence_path) : null,
            ]),
        ]]);
    }

    /** FR-PRD-003: one endpoint for stage updates from the staff app. */
    public function event(Request $request, string $task): JsonResponse
    {
        $t = $this->findTask($task);
        $data = $request->validate([
            'type'     => 'required|in:start,hold,complete,note',
            'note'     => 'nullable|required_if:type,hold|required_if:type,note|string|max:1000',
            'evidence' => 'nullable|image|max:5120',
        ]);
        $u = $request->user();
        $allowed = $data['type'] === 'note'
            ? ($this->production->canWork($u, $t) || $u->can('Record quality check'))
            : $this->production->canWork($u, $t);
        abort_unless($allowed, 403, 'This task is assigned to someone else.');

        $t = match ($data['type']) {
            'start'    => $this->production->start($t, $u, $data['note'] ?? null),
            'hold'     => $this->production->hold($t, $data['note']),
            'complete' => $this->production->complete($t, $u, $data['note'] ?? null, $request->file('evidence')),
            'note'     => tap($t, fn () => $this->production->note($t, $data['note'], $request->file('evidence'))),
        };

        return response()->json(['success' => true, 'data' => $this->taskData($t->fresh(['order', 'line', 'assignee']), $u)]);
    }

    public function storeFitting(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order'            => 'nullable|string',
            'customer'         => 'nullable|required_without:order|string',
            'line'             => 'nullable|integer',
            'staff_id'         => 'nullable|integer',
            'scheduled_at'     => 'required|date',          // house local time, e.g. 2026-10-20 14:30
            'duration_minutes' => 'nullable|integer|min:10|max:480',
            'notes'            => 'nullable|string|max:1000',
            'reschedule'       => 'nullable|string',               // move this booked fitting instead of adding one
        ]);
        if (! empty($data['reschedule'])) {
            $old = Fitting::where('public_id', $data['reschedule'])->orWhere('number', $data['reschedule'])->firstOrFail();
            $new = $this->fittings->reschedule($old, $data['scheduled_at'], $data['notes'] ?? null, $request->user());

            return response()->json(['success' => true, 'data' => $this->fittingData($new->fresh())], 201);
        }
        $order = ! empty($data['order']) ? $this->order($data['order']) : null;
        $customerId = $order?->customer_id ?? \App\Models\Customer::where('public_id', $data['customer'])->orWhere('customer_number', $data['customer'])->value('id');
        $fitting = $this->fittings->schedule([
            'order_id' => $order?->id, 'customer_id' => $customerId, 'order_line_id' => $data['line'] ?? null,
            'staff_id' => ! empty($data['staff_id']) && User::inCurrentTenant()->whereKey($data['staff_id'])->exists() ? $data['staff_id'] : null,
            'scheduled_at' => $data['scheduled_at'], 'duration_minutes' => $data['duration_minutes'] ?? 30, 'notes' => $data['notes'] ?? null,
        ], $request->user());

        return response()->json(['success' => true, 'data' => $this->fittingData($fitting->fresh())], 201);
    }

    public function recordFitting(Request $request, string $fitting): JsonResponse
    {
        $f = Fitting::where('public_id', $fitting)->orWhere('number', $fitting)->firstOrFail();
        $data = $request->validate([
            'status'  => 'required|in:attended,no_show',
            'outcome' => 'nullable|required_if:status,attended|in:approved,alterations',
            'notes'   => 'nullable|string|max:2000',
            'alterations' => 'nullable|array',
            'alterations.*.name' => 'required|string|max:120',
            'alterations.*.responsible_party' => ['nullable', Rule::in(array_keys(ProductionTask::RESPONSIBLE))],
            'alterations.*.cost_treatment' => ['nullable', Rule::in(array_keys(ProductionTask::COST_TREATMENT))],
            'alterations.*.cost' => 'nullable|numeric|min:0',
        ]);
        $f = $this->fittings->record($f, $data['status'], $data['outcome'] ?? null, $data['notes'] ?? null, $data['alterations'] ?? [], $request->user());

        return response()->json(['success' => true, 'data' => $this->fittingData($f)]);
    }

    public function storeQualityCheck(Request $request): JsonResponse
    {
        $data = $request->validate([
            'task'              => 'required|string',
            'answers'           => 'required|array',
            'answers.*.passed'  => 'required|boolean',
            'answers.*.note'    => 'nullable|string|max:255',
            'issues'            => 'nullable|string|max:2000',
        ]);
        $check = $this->quality->record($this->findTask($data['task']), null, $data['answers'], $data['issues'] ?? null, $request->user());

        return response()->json(['success' => true, 'data' => [
            'id' => $check->public_id, 'result' => $check->result, 'issues' => $check->issues, 'results' => $check->results,
            'rework_task' => $check->reworkTask?->public_id,
        ]], 201);
    }

    public function storeAlteration(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order'             => 'required|string',
            'line'              => 'nullable|integer',
            'name'              => 'required|string|max:120',
            'reason'            => 'required|string|max:255',
            'responsible_party' => ['required', Rule::in(array_keys(ProductionTask::RESPONSIBLE))],
            'cost_treatment'    => ['required', Rule::in(array_keys(ProductionTask::COST_TREATMENT))],
            'cost'              => 'nullable|numeric|min:0',
            'assigned_to'       => 'nullable|integer',
            'due_date'          => 'nullable|date',
            'instructions'      => 'nullable|string|max:2000',
        ]);
        $order = $this->order($data['order']);
        $line = ! empty($data['line']) ? OrderLine::where('order_id', $order->id)->findOrFail($data['line']) : null;
        if (! empty($data['assigned_to']) && ! User::inCurrentTenant()->whereKey($data['assigned_to'])->exists()) {
            $data['assigned_to'] = null;
        }
        $task = $this->production->addFix($order, $line, $data, $request->user());

        return response()->json(['success' => true, 'data' => $this->taskData($task->fresh(['order', 'line', 'assignee']), $request->user())], 201);
    }

    public function storeDelivery(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order'                  => 'required|string',
            'lines'                  => 'required|array|min:1',
            'lines.*'                => 'integer',
            'method'                 => 'required|in:collection,delivery',
            'recipient_name'         => 'required|string|max:120',
            'recipient_phone'        => 'nullable|string|max:30',
            'relationship'           => 'nullable|string|max:60',
            'confirmation'           => ['required', Rule::in(array_keys(Delivery::CONFIRMATIONS))],
            'confirmation_ref'       => 'nullable|string|max:120',
            'outstanding_conditions' => 'nullable|string|max:1000',
            'address'                => 'nullable|string|max:255',
            'evidence'               => 'nullable|image|max:5120',
            'material_ids'           => 'nullable|array',
            'material_ids.*'         => 'string',
            'material_quantities'    => 'nullable|array',
            'material_quantities.*'  => 'nullable|numeric|min:0',
        ]);
        $order = $this->order($data['order']);
        $d = $this->deliveries->handOver($order, ['line_ids' => $data['lines']] + $data, $request->user(), $request->file('evidence'));

        return response()->json(['success' => true, 'data' => [
            'id' => $d->public_id, 'number' => $d->number, 'handed_over_at' => $d->handed_over_at->toIso8601String(),
            'recipient' => $d->recipient_name, 'balance_at_handover' => $d->balance_at_handover, 'order_status' => $order->fresh()->status,
        ]], 201);
    }

    /* ------------------------------- helpers ------------------------------- */

    protected function order(string $ref): Order
    {
        return Order::where('public_id', $ref)->orWhere('number', $ref)->first()
            ?? throw ValidationException::withMessages(['order' => 'Unknown order.']);
    }

    protected function findTask(string $ref): ProductionTask
    {
        return ProductionTask::where('public_id', $ref)->orWhere('number', $ref)->firstOrFail();
    }

    protected function taskData(ProductionTask $t, User $u): array
    {
        return [
            'id' => $t->public_id, 'number' => $t->number, 'name' => $t->name, 'kind' => $t->kind, 'type' => $t->type,
            'status' => $t->status, 'status_label' => $t->status_label, 'priority' => $t->priority,
            'due_date' => $t->due_date?->toDateString(), 'overdue' => $t->isOverdue(),
            'assigned_to' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name] : null,
            'order' => ['id' => $t->order->public_id, 'number' => $t->order->number, 'title' => $t->order->title,
                'promised_date' => $t->order->promised_date?->toDateString(), 'risk' => $t->order->risk_level],
            'garment' => $t->line ? ['line' => $t->line->id, 'description' => $t->line->description, 'wearer' => $t->line->wearer] : null,
            'reason' => $t->reason, 'can_work' => $this->production->canWork($u, $t),
        ];
    }

    protected function fittingData(Fitting $f): array
    {
        $tz = CurrentTenant::get()->timezone;

        return [
            'id' => $f->public_id, 'number' => $f->number, 'status' => $f->status, 'outcome' => $f->outcome,
            'scheduled_at' => $f->scheduled_at->timezone($tz)->toIso8601String(), 'duration_minutes' => $f->duration_minutes,
            'order' => $f->order?->number, 'order_id' => $f->order?->public_id, 'customer' => $f->customer?->name,
            'line' => $f->order_line_id, 'garment' => $f->line?->description,
            'reminders' => $f->reminders()->where('status', 'pending')->orderBy('remind_at')->get()->map(fn ($r) => $r->remind_at->timezone($tz)->toIso8601String()),
        ];
    }
}
