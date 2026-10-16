<?php

namespace App\Http\Controllers\Production;

use App\Http\Controllers\Controller;
use App\Models\Fitting;
use App\Models\MaterialIssue;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductionTask;
use App\Models\ProductionWorkflow;
use App\Models\User;
use App\Services\Production\ProductionService;
use App\Services\Production\QualityService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Production board and garment tasks (SRS FR-PRD-001…004, FR-PRD-006/007). */
class ProductionController extends Controller
{
    public function __construct(protected ProductionService $production, protected QualityService $quality)
    {
        $this->middleware('permission:View production', ['only' => ['board', 'show']]);
        $this->middleware('permission:Update production', ['only' => ['start', 'hold', 'complete', 'note']]);
        $this->middleware('permission:Manage production workflow', ['only' => ['plan', 'assign', 'update', 'reopen', 'addAlteration']]);
        $this->middleware('permission:Record quality check', ['only' => ['qualityCheck']]);
    }

    /** The production board: open work by state, plus what needs attention. */
    public function board(Request $request): View
    {
        $u = $request->user();
        $manager = $u->can('Manage production workflow');
        $who = $request->input('who', $manager ? 'all' : 'mine');
        $tz = CurrentTenant::get()->timezone;
        $today = now($tz)->toDateString();

        $base = ProductionTask::query()
            ->whereHas('order', fn ($o) => $o->whereNotIn('status', ['cancelled', 'draft']))
            ->where(fn ($q) => $q->whereNull('order_line_id')->orWhereHas('line', fn ($l) => $l->where('status', '!=', 'cancelled')))
            ->when($who === 'mine', fn ($q) => $q->where(fn ($w) => $w->where('assigned_to', $u->id)->orWhereNull('assigned_to')))
            ->when(is_numeric($who), fn ($q) => $q->where('assigned_to', (int) $who))
            ->when($who === 'unassigned', fn ($q) => $q->whereNull('assigned_to'))
            ->when($request->filled('stage'), fn ($q) => $q->where('name', $request->stage))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', "%{$request->q}%")
                ->orWhereHas('order', fn ($o) => $o->where('number', 'like', "%{$request->q}%")->orWhere('title', 'like', "%{$request->q}%")
                    ->orWhereHas('customer', fn ($c) => $c->search($request->q)))));

        $with = ['order:id,public_id,number,title,customer_id,promised_date,priority,risk_level,status', 'order.customer:id,first_name,last_name', 'line:id,order_id,description,wearer', 'assignee:id,name'];
        $sort = fn ($q) => $q->orderByRaw("case priority when 'rush' then 0 when 'high' then 1 else 2 end")->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('id');
        $columns = [
            'pending'     => $sort((clone $base)->where('status', 'pending')->with($with))->limit(120)->get(),
            'in_progress' => $sort((clone $base)->where('status', 'in_progress')->with($with))->limit(120)->get(),
            'on_hold'     => $sort((clone $base)->where('status', 'on_hold')->with($with))->limit(60)->get(),
            'done'        => (clone $base)->where('status', 'done')->where('completed_at', '>=', now()->subDays(3))->with($with)->latest('completed_at')->limit(40)->get(),
        ];
        $open = (clone $base)->open();

        return view('production.board', [
            'pagetitle' => 'Production board',
            'columns'   => $columns,
            'waiting'   => (clone $base)->where('status', 'blocked')->count(),
            'stats'     => [
                'open'     => (clone $open)->count(),
                'overdue'  => (clone $open)->whereDate('due_date', '<', $today)->count(),
                'today'    => (clone $open)->whereDate('due_date', $today)->count(),
                'fittings' => Fitting::where('status', 'scheduled')->whereBetween('scheduled_at', [now($tz)->startOfDay()->utc(), now($tz)->endOfDay()->utc()])->count(),
            ],
            'atRisk'    => $manager || $u->can('View order') ? Order::whereIn('risk_level', ['high', 'watch'])->whereIn('status', ['confirmed', 'in_production', 'fitting', 'ready'])
                ->with('customer:id,first_name,last_name')->orderByRaw("case risk_level when 'high' then 0 else 1 end")->orderBy('promised_date')->limit(8)->get() : collect(),
            'toPlan'    => $manager ? Order::whereIn('status', ['confirmed', 'in_production'])->whereHas('lines', fn ($l) => $l->whereIn('status', ['pending']))
                ->whereDoesntHave('tasks')->with('customer:id,first_name,last_name')->orderBy('promised_date')->limit(8)->get() : collect(),
            'staff'     => User::inCurrentTenant()->orderBy('name')->get(['users.id', 'name']),
            'stages'    => ProductionTask::where('kind', 'stage')->distinct()->orderBy('name')->pluck('name'),
            'who'       => $who,
            'manager'   => $manager,
            'tz'        => $tz,
            'today'     => $today,
        ]);
    }

    public function show(Request $request, ProductionTask $task): View
    {
        $task->load('order.customer', 'line.styleVersion.style', 'line.measurementSession.templateVersion.template', 'assignee', 'role', 'checklist',
            'events.user', 'blocksTask', 'blockers', 'qualityChecks.reviewer', 'qualityChecks.reworkTask', 'stage');

        return view('production.task', [
            'pagetitle' => $task->number,
            'task'      => $task,
            'siblings'  => $task->order_line_id ? ProductionTask::where('order_line_id', $task->order_line_id)->with('assignee:id,name')->orderBy('position')->orderBy('id')->get() : collect(),
            'issues'    => MaterialIssue::where('task_id', $task->id)->orWhere(fn ($q) => $q->where('order_line_id', $task->order_line_id)->whereNotNull('order_line_id'))->with('lines.item')->latest('issued_at')->get(),
            'fittings'  => $task->type === 'fitting' ? Fitting::where('task_id', $task->id)->latest('scheduled_at')->get() : collect(),
            'canWork'   => $this->production->canWork($request->user(), $task),
            'waitingOn' => $task->status === 'blocked' ? $this->production->waitingOn($task) : null,
            'staff'     => User::inCurrentTenant()->orderBy('name')->get(['users.id', 'name']),
            'tz'        => CurrentTenant::get()->timezone,
        ]);
    }

    /* ------------------------------ actions ------------------------------ */

    public function plan(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['workflow' => 'nullable|array', 'workflow.*' => 'nullable|integer']);
        $choices = collect($data['workflow'] ?? [])->map(fn ($v) => (int) $v)
            ->filter(fn ($v, $lineId) => OrderLine::where('order_id', $order->id)->whereKey($lineId)->exists() && ($v === 0 || ProductionWorkflow::whereKey($v)->exists()))->all();
        $tasks = $this->production->planOrder($order, $choices, $request->user());

        return back()->with('success', $tasks->count() ? "Production planned: {$tasks->count()} task(s) on the board." : 'Nothing new to plan.');
    }

    public function start(Request $request, ProductionTask $task): RedirectResponse
    {
        $this->authorizeWork($request, $task);
        $this->production->start($task, $request->user(), $request->input('note'));

        return back()->with('success', "{$task->name} started.");
    }

    public function hold(Request $request, ProductionTask $task): RedirectResponse
    {
        $this->authorizeWork($request, $task);
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $this->production->hold($task, $data['reason']);

        return back()->with('success', "{$task->name} is on hold.");
    }

    public function complete(Request $request, ProductionTask $task): RedirectResponse
    {
        $this->authorizeWork($request, $task);
        $data = $request->validate(['note' => 'nullable|string|max:1000', 'evidence' => 'nullable|image|max:5120']);
        $this->production->complete($task, $request->user(), $data['note'] ?? null, $request->file('evidence'));

        return back()->with('success', "{$task->name} done.");
    }

    public function note(Request $request, ProductionTask $task): RedirectResponse
    {
        abort_unless($this->production->canWork($request->user(), $task) || $request->user()->can('Record quality check'), 403);
        $data = $request->validate(['note' => 'required|string|max:1000', 'evidence' => 'nullable|image|max:5120', 'customer_visible' => 'nullable|boolean']);
        $this->production->note($task, $data['note'], $request->file('evidence'), $request->boolean('customer_visible') && $request->user()->can('Manage production workflow'));

        return back()->with('success', 'Note added.');
    }

    public function reopen(Request $request, ProductionTask $task): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $this->production->reopen($task, $data['reason']);

        return back()->with('success', "{$task->name} reopened.");
    }

    public function assign(Request $request, ProductionTask $task): RedirectResponse
    {
        $data = $request->validate(['assigned_to' => 'nullable|integer']);
        $this->production->assign($task, $data['assigned_to'] ?? null, $request->user());

        return back()->with('success', 'Assignment saved.');
    }

    public function update(Request $request, ProductionTask $task): RedirectResponse
    {
        $data = $request->validate([
            'due_date'     => 'nullable|date',
            'priority'     => ['required', Rule::in(array_keys(ProductionTask::PRIORITIES))],
            'instructions' => 'nullable|string|max:2000',
            'assigned_to'  => 'nullable|integer',
        ]);
        $this->production->updateDetails($task, $data);
        if ($request->has('assigned_to')) {
            $this->production->assign($task->fresh(), $data['assigned_to'] ?? null, $request->user());
        }

        return back()->with('success', 'Task updated.');
    }

    public function addAlteration(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'order_line_id'     => ['nullable', Rule::exists('order_lines', 'id')->where('order_id', $order->id)],
            'name'              => 'required|string|max:120',
            'reason'            => 'required|string|max:255',
            'responsible_party' => ['required', Rule::in(array_keys(ProductionTask::RESPONSIBLE))],
            'cost_treatment'    => ['required', Rule::in(array_keys(ProductionTask::COST_TREATMENT))],
            'cost'              => 'nullable|numeric|min:0',
            'assigned_to'       => 'nullable|integer',
            'due_date'          => 'nullable|date',
            'instructions'      => 'nullable|string|max:2000',
        ]);
        if (! empty($data['assigned_to']) && ! User::inCurrentTenant()->whereKey($data['assigned_to'])->exists()) {
            $data['assigned_to'] = null;
        }
        $line = ! empty($data['order_line_id']) ? OrderLine::find($data['order_line_id']) : null;
        $task = $this->production->addFix($order, $line, $data, $request->user());

        return back()->with('success', "Alteration {$task->number} added to the board.");
    }

    public function qualityCheck(Request $request, ProductionTask $task): RedirectResponse
    {
        $data = $request->validate([
            'answers'           => 'required|array',
            'answers.*.passed'  => 'nullable|boolean',
            'answers.*.note'    => 'nullable|string|max:255',
            'issues'            => 'nullable|string|max:2000',
            'rework_assignee'   => 'nullable|integer',
        ]);
        $assignee = ! empty($data['rework_assignee']) && User::inCurrentTenant()->whereKey($data['rework_assignee'])->exists() ? (int) $data['rework_assignee'] : null;
        $check = $this->quality->record($task, null, $data['answers'], $data['issues'] ?? null, $request->user(), reworkAssignee: $assignee);

        return back()->with($check->result === 'pass' ? 'success' : 'warning',
            $check->result === 'pass' ? 'Passed — the garment moves on.' : 'Failed — rework ' . $check->reworkTask->number . ' was created and the check waits for it.');
    }

    protected function authorizeWork(Request $request, ProductionTask $task): void
    {
        abort_unless($this->production->canWork($request->user(), $task), 403, 'This task is assigned to someone else.');
    }
}
