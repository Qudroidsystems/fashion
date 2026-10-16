<?php

namespace App\Services\Production;

use App\Models\OrderLine;
use App\Models\ProductionTask;
use App\Models\QualityCheck;
use App\Models\QualityChecklist;
use App\Models\User;
use App\Notifications\QualityCheckFailed;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Quality checks (SRS FR-PRD-006, §10 "Quality check failed").
 * A pass finishes the QC task; a fail creates rework that holds the QC task
 * until it is done, and tells the production managers and the rework owner.
 */
class QualityService
{
    public function __construct(protected ProductionService $production)
    {
    }

    /**
     * @param  array  $answers  index => ['passed' => bool, 'note' => ?string] for each checklist item
     */
    public function record(?ProductionTask $task, ?OrderLine $line, array $answers, ?string $issues, User $reviewer, ?int $checklistId = null, ?int $reworkAssignee = null): QualityCheck
    {
        $line ??= $task?->line;
        $order = $task?->order ?? $line?->order;
        if (! $order) {
            throw ValidationException::withMessages(['task' => 'Choose the garment being checked.']);
        }
        if ($task && ($task->type !== 'qc' || ! in_array($task->status, ['pending', 'in_progress', 'on_hold'], true))) {
            throw ValidationException::withMessages(['task' => $task->status === 'blocked' ? 'This check is waiting on ' . $this->production->waitingOn($task) . '.' : 'This is not an open quality check.']);
        }
        $checklist = QualityChecklist::find($task?->checklist_id ?? $checklistId);
        $items = $checklist?->items ?: [['label' => 'Garment is acceptable', 'required' => true]];

        $results = [];
        $failed = [];
        foreach (array_values($items) as $i => $item) {
            $passed = (bool) ($answers[$i]['passed'] ?? false);
            $note = trim((string) ($answers[$i]['note'] ?? '')) ?: null;
            $results[] = ['label' => $item['label'], 'required' => (bool) ($item['required'] ?? true), 'passed' => $passed, 'note' => $note];
            if (! $passed && ($item['required'] ?? true)) {
                $failed[] = $item['label'] . ($note ? " — {$note}" : '');
            }
        }
        $result = $failed ? 'fail' : 'pass';
        if ($result === 'fail' && ! trim((string) $issues)) {
            $issues = implode('; ', $failed);
        }

        return DB::transaction(function () use ($task, $line, $order, $checklist, $results, $result, $issues, $failed, $reviewer, $reworkAssignee) {
            $check = QualityCheck::create([
                'order_id' => $order->id, 'order_line_id' => $line?->id, 'task_id' => $task?->id, 'checklist_id' => $checklist?->id,
                'results' => $results, 'result' => $result, 'issues' => $issues, 'reviewer_id' => $reviewer->id,
            ]);

            if ($result === 'pass') {
                if ($task) {
                    $this->production->complete($task, $reviewer, 'Quality check passed' . ($issues ? ": {$issues}" : ''), viaQc: true);
                }
            } else {
                $assignee = $reworkAssignee ?: $this->lastWorker($line);
                $rework = $this->production->addFix($order, $line, [
                    'name' => 'Rework: ' . Str::limit($failed[0] ?? $issues, 100), 'reason' => $issues,
                    'responsible_party' => 'staff', 'cost_treatment' => 'absorbed', 'assigned_to' => $assignee,
                    'instructions' => "Quality check failed:\n• " . implode("\n• ", $failed ?: [$issues]),
                ], $reviewer, 'rework', $task);
                $check->update(['rework_task_id' => $rework->id]);
                if ($task) {
                    $this->production->event($task->fresh(), 'qc_failed', null, null, $issues);
                }
                $this->notifyFailure($check->fresh(), $rework);
            }
            AuditLogger::log('production.quality_' . $result, $order, after: ['line' => $line?->description, 'issues' => $issues]);

            return $check;
        });
    }

    /** Whoever last finished work on this garment fixes it by default. */
    protected function lastWorker(?OrderLine $line): ?int
    {
        return $line ? ProductionTask::where('order_line_id', $line->id)->where('type', 'work')->where('status', 'done')
            ->orderByDesc('completed_at')->orderByDesc('id')->value('completed_by') : null;
    }

    protected function notifyFailure(QualityCheck $check, ProductionTask $rework): void
    {
        $people = User::inCurrentTenant()->get()->filter(fn (User $u) => $u->can('Manage production workflow'))
            ->push(User::find($rework->assigned_to))->filter()->unique('id')->reject(fn ($u) => $u->id === auth()->id());
        DB::afterCommit(fn () => $people->each(fn (User $u) => $u->notify(new QualityCheckFailed($check, $rework))));
    }
}
