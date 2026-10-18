<?php

namespace App\Services\Reports\Definitions;

use App\Models\Fitting;
use App\Models\ProductionTask;
use App\Models\QualityCheck;
use App\Models\User;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use Carbon\CarbonImmutable;

/** Workroom workload and quality (FR-REP-001 production workload; §9 "personal workload" for tailors). */
class ProductionReport extends Report
{
    public function key(): string
    {
        return 'production';
    }

    public function title(): string
    {
        return 'Workroom: workload & quality';
    }

    public function description(): string
    {
        return 'Work done and waiting per person, lateness, quality checks, rework and fittings.';
    }

    public function group(): string
    {
        return 'Workroom';
    }

    public function icon(): string
    {
        return 'ri-scissors-cut-line';
    }

    public function permissions(): array
    {
        return ['View production'];
    }

    public function features(): array
    {
        return ['reports_full'];
    }

    public function filters(): array
    {
        return ['range', 'branch', 'staff'];
    }

    public function run(ReportFilters $f, User $user): ReportResult
    {
        // People who don't run the workroom see only their own work.
        $everyone = $user->can('Manage production workflow') || $user->can('View financial reports') || $user->isOwnerOfCurrentTenant();
        $staffId = $everyone ? $f->staffId : $user->id;
        $today = CarbonImmutable::now($f->tz)->toDateString();
        $branch = fn ($q) => $q->when($f->branchId, fn ($w) => $w->whereHas('order', fn ($o) => $o->where('branch_id', $f->branchId)));
        $live = fn ($q) => $q->whereHas('order', fn ($o) => $o->whereNotIn('status', ['draft', 'cancelled']));

        $open = ProductionTask::whereIn('status', ['blocked', 'pending', 'in_progress', 'on_hold'])->tap($branch)->tap($live)
            ->when($staffId, fn ($q) => $q->where('assigned_to', $staffId))->get(['id', 'assigned_to', 'status', 'due_date', 'kind']);
        $done = ProductionTask::where('status', 'done')->whereBetween('completed_at', [$f->fromUtc(), $f->toUtc()])->tap($branch)
            ->when($staffId, fn ($q) => $q->where('assigned_to', $staffId))->get(['id', 'assigned_to', 'due_date', 'started_at', 'completed_at', 'kind', 'type']);
        $rework = ProductionTask::whereIn('kind', ['rework', 'alteration'])->whereBetween('created_at', [$f->fromUtc(), $f->toUtc()])->tap($branch)
            ->when($staffId, fn ($q) => $q->where('assigned_to', $staffId))->get(['id', 'assigned_to', 'kind', 'responsible_party', 'cost']);
        $checks = QualityCheck::whereBetween('created_at', [$f->fromUtc(), $f->toUtc()])
            ->when($f->branchId, fn ($q) => $q->whereHas('order', fn ($o) => $o->where('branch_id', $f->branchId)))
            ->when($staffId, fn ($q) => $q->where('reviewer_id', $staffId))->get(['id', 'result', 'reviewer_id']);
        $fittings = Fitting::whereBetween('scheduled_at', [$f->fromUtc(), $f->toUtc()])->whereIn('status', ['attended', 'no_show', 'scheduled'])
            ->when($f->branchId, fn ($q) => $q->where('branch_id', $f->branchId))
            ->when($staffId, fn ($q) => $q->where('staff_id', $staffId))->get(['id', 'status', 'outcome', 'staff_id', 'scheduled_at']);

        $late = fn ($t) => $t->due_date && CarbonImmutable::instance($t->completed_at)->setTimezone($f->tz)->toDateString() > $t->due_date->toDateString();
        $overdue = $open->filter(fn ($t) => $t->due_date && $t->due_date->toDateString() < $today);
        $doneWithDue = $done->filter(fn ($t) => $t->due_date);
        $passRate = $checks->count() ? round(100 * $checks->where('result', 'pass')->count() / $checks->count(), 1) : null;

        $r = new ReportResult();
        $r->card('Tasks done', $done->count(), 'int', $doneWithDue->count() ? round(100 * $doneWithDue->reject($late)->count() / $doneWithDue->count()) . '% by their due date' : null, 'teal', 'ri-checkbox-circle-line')
          ->card('Waiting now', $open->count(), 'int', $overdue->count() . ' overdue', $overdue->count() ? 'rose' : 'violet', 'ri-time-line')
          ->card('Quality checks passed', $passRate, 'percent', $checks->count() . ' check(s)', $passRate !== null && $passRate < 85 ? 'rose' : 'teal', 'ri-shield-check-line')
          ->card('Rework & alterations', $rework->count(), 'int', $rework->where('kind', 'rework')->count() . ' rework after a failed check', 'amber', 'ri-repeat-line');

        $ids = $open->pluck('assigned_to')->merge($done->pluck('assigned_to'))->merge($rework->pluck('assigned_to'))
            ->merge($fittings->pluck('staff_id'))->merge($checks->pluck('reviewer_id'))->unique()->values();
        $names = User::whereIn('id', $ids->filter())->pluck('name', 'id');
        $rows = [];
        foreach ($ids as $id) {
            $d = $done->where('assigned_to', $id);
            $dd = $d->filter(fn ($t) => $t->due_date);
            $hours = $d->filter(fn ($t) => $t->started_at && $t->completed_at)->map(fn ($t) => $t->started_at->diffInMinutes($t->completed_at) / 60);
            $o = $open->where('assigned_to', $id);
            $rows[] = [
                'person' => $id ? ($names[$id] ?? 'Former staff') : 'Not assigned', 'done' => $d->count(),
                'on_time' => $dd->count() ? round(100 * $dd->reject($late)->count() / $dd->count(), 1) : null,
                'avg_hours' => $hours->count() ? round($hours->avg(), 1) : null,
                'open' => $o->count(), 'overdue' => $o->filter(fn ($t) => $t->due_date && $t->due_date->toDateString() < $today)->count(),
                'rework' => $rework->where('assigned_to', $id)->where('kind', 'rework')->count(),
                'fittings' => $fittings->where('staff_id', $id)->where('status', 'attended')->count(),
                'checks' => $checks->where('reviewer_id', $id)->count(),
                '_tone' => $id ? null : 'warning',
            ];
        }
        usort($rows, fn ($a, $b) => [$b['done'], $b['open']] <=> [$a['done'], $a['open']]);
        $r->chart('hbar', array_column($rows, 'person'), [['name' => 'Done', 'data' => array_column($rows, 'done')], ['name' => 'Waiting', 'data' => array_column($rows, 'open')]], 'int', 'Tasks per person');
        $r->table('people', $everyone ? 'Per person' : 'My work', [
            ['key' => 'person', 'label' => 'Person'], ['key' => 'done', 'label' => 'Done', 'format' => 'int'], ['key' => 'on_time', 'label' => 'On time', 'format' => 'percent'],
            ['key' => 'avg_hours', 'label' => 'Avg hours per task', 'format' => 'qty'], ['key' => 'open', 'label' => 'Waiting', 'format' => 'int'],
            ['key' => 'overdue', 'label' => 'Overdue', 'format' => 'int'], ['key' => 'rework', 'label' => 'Rework received', 'format' => 'int'],
            ['key' => 'fittings', 'label' => 'Fittings held', 'format' => 'int'], ['key' => 'checks', 'label' => 'Checks done', 'format' => 'int'],
        ], $rows, true, null, 'No workroom activity in this period.');

        $parties = ProductionTask::RESPONSIBLE;
        $why = $rework->groupBy(fn ($t) => $t->responsible_party ?: 'unknown')->map(fn ($g, $p) => [
            'party' => $parties[$p] ?? 'Not given', 'count' => $g->count(), 'rework' => $g->where('kind', 'rework')->count(), 'cost' => round($g->sum('cost'), 2),
        ])->sortByDesc('count')->values()->all();
        $r->table('rework', 'Rework & alterations by cause', [
            ['key' => 'party', 'label' => 'Responsible'], ['key' => 'count', 'label' => 'All', 'format' => 'int'], ['key' => 'rework', 'label' => 'After failed check', 'format' => 'int'],
            ['key' => 'cost', 'label' => 'Cost recorded', 'format' => 'money'],
        ], $why, true, null, 'No rework or alterations in this period.');

        $r->table('fittings', 'Fittings', [['key' => 'what', 'label' => ''], ['key' => 'count', 'label' => 'Count', 'format' => 'int']], [
            ['what' => 'Booked in the period', 'count' => $fittings->count()],
            ['what' => 'Attended — approved', 'count' => $fittings->where('status', 'attended')->where('outcome', 'approved')->count()],
            ['what' => 'Attended — alterations needed', 'count' => $fittings->where('status', 'attended')->where('outcome', 'alterations')->count()],
            ['what' => 'Customer didn’t come', 'count' => $fittings->where('status', 'no_show')->count(), '_tone' => 'warning'],
            ['what' => 'Still to happen / not recorded', 'count' => $fittings->where('status', 'scheduled')->count()],
        ]);
        $r->note('Tasks count for the person they were assigned to when finished. Hours are from start to finish, including breaks and waiting.');

        return $r;
    }
}
