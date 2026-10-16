<?php

namespace App\Services\Production;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductionEvent;
use App\Models\ProductionTask;
use App\Models\ProductionWorkflow;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Services\Audit\AuditLogger;
use App\Services\Numbering\DocumentNumber;
use App\Services\Orders\OrderService;
use App\Support\Orders\OrderStatus;
use App\Support\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Garment-level production (SRS §5.9, §6.2 steps 12–14).
 *
 * - Planning copies a workflow's stages into tasks per garment line, with due
 *   dates worked back from the promise date.
 * - Each task change writes a production event (append-only history).
 * - Line and order statuses are a projection of the tasks: syncLine/syncOrder
 *   move them along the normal order life cycle, never around it.
 */
class ProductionService
{
    public function __construct(protected OrderService $orders)
    {
    }

    /* ------------------------------ planning ------------------------------ */

    /** The workflow a line would use: same kind, then the style's category, then the default. */
    public function workflowFor(OrderLine $line): ?ProductionWorkflow
    {
        $categoryId = $line->styleVersion?->style?->category_id;

        return ProductionWorkflow::active()->with('stages')->get()
            ->filter(fn ($w) => in_array($line->kind, $w->line_kinds ?? [], true) && $w->stages->isNotEmpty())
            ->sortByDesc(fn ($w) => ($categoryId && $w->category_id === $categoryId ? 4 : 0) + ($w->category_id === null ? 1 : 0) + ($w->is_default ? 2 : 0))
            ->first();
    }

    /**
     * Create tasks for the order's garments (§6.2 step 12). $choices maps line id
     * => workflow id, or 0 for "nothing to make" (the line is ready as it is).
     * Lines that already have stage tasks are left alone, so this is safe to re-run.
     */
    public function planOrder(Order $order, array $choices, User $by): Collection
    {
        if (! in_array($order->status, ['confirmed', 'in_production', 'fitting', 'ready'], true)) {
            throw ValidationException::withMessages(['order' => 'Confirm the order before planning production.']);
        }

        return DB::transaction(function () use ($order, $choices, $by) {
            $created = collect();
            $order->load('lines.styleVersion.style', 'lines.tasks');
            foreach ($order->lines->whereIn('status', ['pending', 'in_production']) as $line) {
                if ($line->tasks->where('kind', 'stage')->isNotEmpty()) {
                    continue;
                }
                $choice = array_key_exists($line->id, $choices) ? (int) $choices[$line->id] : null;
                $workflow = $choice === 0 ? null
                    : ($choice ? ProductionWorkflow::active()->with('stages')->find($choice) : $this->workflowFor($line));
                if (! $workflow) {
                    // Ready-made from stock, a charge, or "nothing to make".
                    $line->update(['status' => 'ready']);
                    continue;
                }
                $created = $created->merge($this->createStageTasks($order, $line, $workflow, $by));
            }
            AuditLogger::log('production.planned', $order, after: ['tasks' => $created->count()]);
            $this->syncOrder($order->fresh());

            return $created;
        });
    }

    protected function createStageTasks(Order $order, OrderLine $line, ProductionWorkflow $workflow, User $by): Collection
    {
        $stages = $workflow->stages;
        $dues = $this->dueDates($order, $stages->pluck('duration_hours')->all());
        $priority = $order->priority === 'rush' ? 'rush' : 'normal';
        $tasks = collect();

        foreach ($stages->values() as $i => $stage) {
            $blocked = $i > 0 && $stage->requires_previous;
            $task = ProductionTask::create([
                'number' => DocumentNumber::next('production_task'), 'order_id' => $order->id, 'order_line_id' => $line->id,
                'stage_id' => $stage->id, 'kind' => 'stage', 'name' => $stage->name, 'type' => $stage->type,
                'position' => $stage->position ?: $i + 1, 'duration_hours' => $stage->duration_hours,
                'requires_previous' => $stage->requires_previous, 'customer_visible' => $stage->customer_visible,
                'checklist_id' => $stage->checklist_id, 'status' => $blocked ? 'blocked' : 'pending', 'priority' => $priority,
                'assigned_to' => $stage->type === 'work' ? $line->assigned_to : null, 'role_id' => $stage->role_id,
                'due_date' => $dues[$i], 'instructions' => $stage->instructions, 'created_by' => $by->id,
            ]);
            $this->event($task, 'created', null, $task->status, "From workflow “{$workflow->name}”");
            if ($task->assigned_to && ! $blocked) {
                $this->notifyAssignee($task);
            }
            $tasks->push($task);
        }

        return $tasks;
    }

    /**
     * Due date per stage. With a promise date, work back from one working day
     * before it; without one, work forward from today. 8 hours = one day.
     */
    public function dueDates(Order $order, array $hours): array
    {
        $tz = CurrentTenant::get()?->timezone ?? config('app.timezone');
        $days = array_map(fn ($h) => max(1, (int) ceil(((int) $h) / 8)), $hours);
        $out = [];
        if ($order->promised_date) {
            $cursor = $this->subWorkingDays(Carbon::parse($order->promised_date->toDateString(), $tz), 1);
            for ($i = count($days) - 1; $i >= 0; $i--) {
                $out[$i] = $cursor->toDateString();
                $cursor = $this->subWorkingDays($cursor, $days[$i]);
            }
            ksort($out);

            return $out;
        }
        $cursor = Carbon::today($tz);
        foreach ($days as $i => $d) {
            $cursor = $this->addWorkingDays($cursor, $d);
            $out[$i] = $cursor->toDateString();
        }

        return $out;
    }

    public function workingDays(): array
    {
        return array_map('intval', CurrentTenant::get()?->setting('production_working_days', [1, 2, 3, 4, 5, 6]) ?? [1, 2, 3, 4, 5, 6]);
    }

    public function addWorkingDays(Carbon $d, int $n): Carbon
    {
        $d = $d->copy();
        $wd = $this->workingDays() ?: [1, 2, 3, 4, 5, 6];
        while ($n > 0) {
            $d->addDay();
            if (in_array($d->dayOfWeekIso, $wd, true)) {
                $n--;
            }
        }

        return $d;
    }

    public function subWorkingDays(Carbon $d, int $n): Carbon
    {
        $d = $d->copy();
        $wd = $this->workingDays() ?: [1, 2, 3, 4, 5, 6];
        while ($n > 0) {
            $d->subDay();
            if (in_array($d->dayOfWeekIso, $wd, true)) {
                $n--;
            }
        }

        return $d;
    }

    /** Working days from today up to (not including) $date; negative when past. */
    public function workingDaysUntil(Carbon $date): int
    {
        $tz = CurrentTenant::get()?->timezone ?? config('app.timezone');
        $today = Carbon::today($tz);
        $target = Carbon::parse($date->toDateString(), $tz);
        if ($target->lt($today)) {
            return -1 * $today->diffInDays($target, true);
        }
        $n = 0;
        $wd = $this->workingDays() ?: [1, 2, 3, 4, 5, 6];
        for ($d = $today->copy(); $d->lt($target); $d->addDay()) {
            if (in_array($d->dayOfWeekIso, $wd, true)) {
                $n++;
            }
        }

        return $n;
    }

    /* ------------------------------ task actions ------------------------------ */

    /** Tailors work on their own or unassigned tasks; managers on any (SRS §9 "update assigned"). */
    public function canWork(User $user, ProductionTask $task): bool
    {
        if ($user->can('Manage production workflow')) {
            return true;
        }

        return $user->can('Update production') && ($task->assigned_to === null || $task->assigned_to === $user->id);
    }

    public function start(ProductionTask $task, User $by, ?string $note = null): ProductionTask
    {
        $this->assertActive($task);
        if ($task->status === 'blocked') {
            throw ValidationException::withMessages(['task' => 'This task is waiting on ' . $this->waitingOn($task) . '.']);
        }
        if (! in_array($task->status, ['pending', 'on_hold'], true)) {
            throw ValidationException::withMessages(['task' => 'Only a task that is to do or on hold can be started.']);
        }

        return $this->mutate($task, function (ProductionTask $task) use ($by, $note) {
            $from = $task->status;
            $task->update(['status' => 'in_progress', 'started_at' => $task->started_at ?? now(), 'assigned_to' => $task->assigned_to ?? $by->id]);
            $this->event($task, $from === 'on_hold' ? 'resumed' : 'started', $from, 'in_progress', $note);
        });
    }

    public function hold(ProductionTask $task, string $reason): ProductionTask
    {
        $this->assertActive($task);
        if ($task->status !== 'in_progress' && $task->status !== 'pending') {
            throw ValidationException::withMessages(['task' => 'Only work that is to do or in progress can be put on hold.']);
        }

        return $this->mutate($task, function (ProductionTask $task) use ($reason) {
            $from = $task->status;
            $task->update(['status' => 'on_hold']);
            $this->event($task, 'paused', $from, 'on_hold', $reason);
        });
    }

    /**
     * Finish a task. A QC task is finished by recording a passing check, not
     * directly. Finishing releases the next stage and any task this one held up.
     */
    public function complete(ProductionTask $task, User $by, ?string $note = null, ?UploadedFile $evidence = null, bool $viaQc = false): ProductionTask
    {
        $this->assertActive($task);
        if ($task->type === 'qc' && ! $viaQc) {
            throw ValidationException::withMessages(['task' => 'Record the quality check to finish this task.']);
        }
        if ($task->status === 'blocked') {
            throw ValidationException::withMessages(['task' => 'This task is waiting on ' . $this->waitingOn($task) . '.']);
        }
        if (! in_array($task->status, ['pending', 'in_progress', 'on_hold'], true)) {
            throw ValidationException::withMessages(['task' => 'This task is already finished.']);
        }
        if ($task->kind !== 'stage' && ! $task->resolution && ! $note) {
            throw ValidationException::withMessages(['note' => 'Say what was done to resolve it.']);
        }

        return $this->mutate($task, function (ProductionTask $task) use ($by, $note, $evidence, $viaQc) {
            $from = $task->status;
            $task->update([
                'status' => 'done', 'started_at' => $task->started_at ?? now(), 'completed_at' => now(), 'completed_by' => $by->id,
                'assigned_to' => $task->assigned_to ?? $by->id,
                'resolution' => $task->kind !== 'stage' ? ($task->resolution ?: $note) : $task->resolution,
            ]);
            $this->event($task, $viaQc ? 'qc_passed' : 'completed', $from, 'done', $note, $evidence, $task->customer_visible);
            $this->release($task);
        });
    }

    /** Undo a finished task (a mistake, or work that must be redone). */
    public function reopen(ProductionTask $task, string $reason): ProductionTask
    {
        if ($task->status !== 'done') {
            throw ValidationException::withMessages(['task' => 'Only finished tasks can be reopened.']);
        }
        if (in_array($task->order->status, ['delivered', 'closed', 'cancelled'], true)) {
            throw ValidationException::withMessages(['task' => 'The order is ' . strtolower(OrderStatus::label($task->order->status)) . '; add an alteration instead.']);
        }

        return $this->mutate($task, function (ProductionTask $task) use ($reason) {
            $task->update(['status' => 'pending', 'completed_at' => null, 'completed_by' => null]);
            $this->event($task, 'reopened', 'done', 'pending', $reason);
            // Later stages that depended on it wait again.
            $this->laterStages($task)->whereIn('status', ['pending'])->each(function ($t) {
                $t->update(['status' => 'blocked']);
                $this->event($t, 'blocked', 'pending', 'blocked', 'An earlier stage was reopened');
            });
        });
    }

    public function assign(ProductionTask $task, ?int $userId, User $by): ProductionTask
    {
        if ($userId && ! User::inCurrentTenant()->whereKey($userId)->exists()) {
            throw ValidationException::withMessages(['assigned_to' => 'Choose someone in this house.']);
        }
        if ((int) $task->assigned_to === (int) $userId) {
            return $task;
        }

        return $this->mutate($task, function (ProductionTask $task) use ($userId) {
            $task->update(['assigned_to' => $userId]);
            $this->event($task, 'assigned', null, null, $userId ? 'To ' . User::find($userId)->name : 'Unassigned');
            if ($userId) {
                $this->notifyAssignee($task);
            }
        });
    }

    public function updateDetails(ProductionTask $task, array $data): ProductionTask
    {
        return $this->mutate($task, function (ProductionTask $task) use ($data) {
            $before = $task->due_date?->toDateString();
            $task->update(array_intersect_key($data, array_flip(['due_date', 'priority', 'instructions'])));
            if (array_key_exists('due_date', $data) && $before !== $task->due_date?->toDateString()) {
                $this->event($task, 'rescheduled', null, null, 'Due ' . ($task->due_date?->format('d M') ?? 'not set') . ($before ? ' (was ' . Carbon::parse($before)->format('d M') . ')' : ''));
            }
        });
    }

    public function note(ProductionTask $task, string $note, ?UploadedFile $evidence = null, bool $customerVisible = false): ProductionEvent
    {
        return DB::transaction(fn () => $this->event($task, 'note', null, null, $note, $evidence, $customerVisible));
    }

    /**
     * Alteration or rework task (FR-PRD-007). When it holds up another task
     * (the fitting or QC that found the problem), that task waits until it is done.
     */
    public function addFix(Order $order, ?OrderLine $line, array $data, User $by, string $kind = 'alteration', ?ProductionTask $holds = null): ProductionTask
    {
        if (in_array($order->status, ['draft', 'cancelled', 'closed'], true)) {
            throw ValidationException::withMessages(['order' => 'Alterations can only be added to confirmed or delivered orders.']);
        }

        return DB::transaction(function () use ($order, $line, $data, $by, $kind, $holds) {
            $tz = CurrentTenant::get()->timezone;
            $task = ProductionTask::create([
                'number' => DocumentNumber::next('production_task'), 'order_id' => $order->id, 'order_line_id' => $line?->id,
                'kind' => $kind, 'name' => Str::limit($data['name'] ?? ($kind === 'rework' ? 'Rework' : 'Alteration'), 120, ''),
                'type' => 'work', 'position' => 900, 'duration_hours' => (int) ($data['duration_hours'] ?? 4),
                'requires_previous' => false, 'status' => 'pending',
                'priority' => $data['priority'] ?? ($kind === 'rework' ? 'high' : ($order->priority === 'rush' ? 'rush' : 'normal')),
                'assigned_to' => $data['assigned_to'] ?? null, 'due_date' => $data['due_date'] ?? $this->addWorkingDays(Carbon::today($tz), 1)->toDateString(),
                'instructions' => $data['instructions'] ?? null, 'blocks_task_id' => $holds?->id,
                'reason' => $data['reason'] ?? null, 'responsible_party' => $data['responsible_party'] ?? null,
                'cost_treatment' => $data['cost_treatment'] ?? null, 'cost' => $data['cost'] ?? null, 'created_by' => $by->id,
            ]);
            $this->event($task, 'created', null, 'pending', $task->reason);
            if ($holds && $holds->isOpen() && $holds->status !== 'blocked') {
                $from = $holds->status;
                $holds->update(['status' => 'blocked']);
                $this->event($holds, 'blocked', $from, 'blocked', "Waiting for {$task->number} ({$task->name})");
            }
            if ($line && in_array($line->status, ['ready', 'pending'], true)) {
                $line->update(['status' => 'in_production']);
            }
            if ($task->assigned_to) {
                $this->notifyAssignee($task);
            }
            AuditLogger::log("production.{$kind}_added", $order, after: $task->only(['number', 'name', 'reason', 'responsible_party', 'cost_treatment', 'cost']));
            $this->syncOrder($order->fresh());

            return $task;
        });
    }

    /** Order cancelled: open work stops (called inside OrderService::cancel). */
    public function cancelOpenWork(Order $order, string $reason): void
    {
        ProductionTask::where('order_id', $order->id)->open()->get()->each(function ($t) use ($reason) {
            $from = $t->status;
            $t->update(['status' => 'cancelled']);
            $this->event($t, 'cancelled', $from, 'cancelled', $reason);
        });
        app(FittingService::class)->cancelForOrder($order, $reason);
    }

    /* ------------------------------ projection ------------------------------ */

    /** Garment status from its tasks. */
    public function syncLine(OrderLine $line): void
    {
        if (! in_array($line->status, ['pending', 'in_production', 'ready'], true)) {
            return;
        }
        $tasks = ProductionTask::where('order_line_id', $line->id)->where('status', '!=', 'cancelled')->get();
        if ($tasks->isEmpty()) {
            return;
        }
        $to = $tasks->every(fn ($t) => $t->status === 'done') ? 'ready'
            : ($tasks->contains(fn ($t) => in_array($t->status, ['in_progress', 'on_hold', 'done'], true)) ? 'in_production' : 'pending');
        if ($to !== $line->status) {
            $line->update(['status' => $to]);
        }
    }

    /**
     * Order status from its garments and fittings, moved one allowed step at a
     * time so the normal history and customer-visible events are written.
     */
    public function syncOrder(Order $order): void
    {
        if (! in_array($order->status, ['confirmed', 'in_production', 'fitting', 'ready'], true)) {
            return;
        }
        $lines = $order->lines()->whereNotIn('status', ['cancelled', 'delivered'])->get();
        if ($lines->isEmpty()) {
            return;
        }
        $hasWork = ProductionTask::where('order_id', $order->id)->where('status', '!=', 'cancelled')->exists();
        $openWork = ProductionTask::where('order_id', $order->id)->open()->exists();

        if ($lines->every(fn ($l) => $l->status === 'ready') && ! $openWork) {
            $target = 'ready';
        } elseif (\App\Models\Fitting::where('order_id', $order->id)->where('status', 'scheduled')->exists()) {
            $target = 'fitting';
        } elseif ($hasWork || $lines->contains(fn ($l) => $l->status !== 'pending')) {
            $target = 'in_production';
        } else {
            return;
        }
        $this->moveOrder($order, $target, 'Updated from production');
    }

    public function moveOrder(Order $order, string $target, string $note): void
    {
        if ($order->status === $target) {
            return;
        }
        $path = $this->path($order->status, $target);
        foreach ($path as $step) {
            $order = $this->orders->transition($order->fresh(), $step, $note);
        }
    }

    /** Shortest allowed route between two statuses (breadth-first over OrderStatus::TRANSITIONS). */
    protected function path(string $from, string $to): array
    {
        $queue = [[$from, []]];
        $seen = [$from => true];
        while ($queue) {
            [$s, $p] = array_shift($queue);
            foreach (OrderStatus::TRANSITIONS[$s] ?? [] as $n) {
                if ($n === 'cancelled' || isset($seen[$n])) {
                    continue;
                }
                if ($n === $to) {
                    return [...$p, $n];
                }
                $seen[$n] = true;
                $queue[] = [$n, [...$p, $n]];
            }
        }

        return [];
    }

    /* ------------------------------ helpers ------------------------------ */

    protected function mutate(ProductionTask $task, callable $fn): ProductionTask
    {
        return DB::transaction(function () use ($task, $fn) {
            $task = ProductionTask::whereKey($task->id)->lockForUpdate()->first();
            $fn($task);
            if ($task->line) {
                $this->syncLine($task->line->fresh());
            }
            $this->syncOrder($task->order->fresh());
            app(RiskService::class)->refresh($task->order->fresh(), notify: false);

            return $task->fresh();
        });
    }

    /** After a task finishes: unblock next stages and anything it was holding up. */
    protected function release(ProductionTask $task): void
    {
        $candidates = $this->laterStages($task)->where('status', 'blocked');
        if ($task->blocks_task_id) {
            $candidates = $candidates->push(ProductionTask::find($task->blocks_task_id))->filter();
        }
        foreach ($candidates as $next) {
            if ($this->isClear($next)) {
                $next->update(['status' => 'pending']);
                $this->event($next, 'unblocked', 'blocked', 'pending', "{$task->name} is done");
                if ($next->assigned_to) {
                    $this->notifyAssignee($next, ready: true);
                }
            }
        }
    }

    /** Earlier stages done and nothing holding it up. */
    public function isClear(ProductionTask $task): bool
    {
        if (ProductionTask::where('blocks_task_id', $task->id)->open()->exists()) {
            return false;
        }
        if (! $task->requires_previous || $task->kind !== 'stage' || ! $task->order_line_id) {
            return true;
        }

        return ! ProductionTask::where('order_line_id', $task->order_line_id)->where('kind', 'stage')
            ->where('position', '<', $task->position)->whereNotIn('status', ['done', 'cancelled'])->exists();
    }

    public function waitingOn(ProductionTask $task): string
    {
        $fix = ProductionTask::where('blocks_task_id', $task->id)->open()->first();
        if ($fix) {
            return "{$fix->number} ({$fix->name})";
        }
        $prev = ProductionTask::where('order_line_id', $task->order_line_id)->where('kind', 'stage')
            ->where('position', '<', $task->position)->whereNotIn('status', ['done', 'cancelled'])->orderBy('position')->first();

        return $prev ? "“{$prev->name}”" : 'earlier work';
    }

    protected function laterStages(ProductionTask $task): Collection
    {
        if ($task->kind !== 'stage' || ! $task->order_line_id) {
            return collect();
        }

        return ProductionTask::where('order_line_id', $task->order_line_id)->where('kind', 'stage')
            ->where('position', '>', $task->position)->where('requires_previous', true)->orderBy('position')->get();
    }

    protected function assertActive(ProductionTask $task): void
    {
        if ($task->status === 'cancelled' || in_array($task->order->status, ['cancelled', 'closed'], true)) {
            throw ValidationException::withMessages(['task' => 'This work was cancelled.']);
        }
    }

    public function event(ProductionTask $task, string $type, ?string $from, ?string $to, ?string $note = null, ?UploadedFile $evidence = null, bool $customerVisible = false): ProductionEvent
    {
        $path = $evidence?->storeAs('tenants/' . CurrentTenant::get()->public_id . '/production', Str::random(12) . '.' . $evidence->extension(), 'public');

        return ProductionEvent::create([
            'task_id' => $task->id, 'order_id' => $task->order_id, 'order_line_id' => $task->order_line_id, 'type' => $type,
            'from_status' => $from, 'to_status' => $to, 'note' => $note, 'evidence_path' => $path,
            'customer_visible' => $customerVisible, 'user_id' => auth()->id(),
        ]);
    }

    protected function notifyAssignee(ProductionTask $task, bool $ready = false): void
    {
        $user = User::find($task->assigned_to);
        if ($user && $user->id !== auth()->id()) {
            DB::afterCommit(fn () => $user->notify(new TaskAssigned($task, $ready)));
        }
    }
}
