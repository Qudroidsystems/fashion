<?php

namespace App\Services\Reports\Definitions;

use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectService;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use Carbon\CarbonImmutable;

/**
 * Weddings, events and group orders (SRS FR-PRJ-007, GET /reports/project-profitability):
 * budget, actual cost, collections, what is still owed, garments finished and
 * how ready each project is for its date. Uses the same summary as the project
 * page, so the two never disagree.
 */
class ProjectsReport extends Report
{
    public function key(): string
    {
        return 'projects';
    }

    public function title(): string
    {
        return 'Weddings & projects';
    }

    public function description(): string
    {
        return 'Contract value, money collected and owed, budget against actual cost, margin and how ready each project is.';
    }

    public function group(): string
    {
        return 'Projects';
    }

    public function icon(): string
    {
        return 'ri-heart-2-line';
    }

    public function permissions(): array
    {
        return ['View project'];
    }

    public function features(): array
    {
        return ['projects', 'reports_full'];
    }

    public function filters(): array
    {
        return ['range', 'branch', 'staff', 'status'];
    }

    public function defaultPeriod(): string
    {
        return 'all';
    }

    public function asOf(): bool
    {
        return true;
    }

    public function rangeNoun(): string
    {
        return 'events on';
    }

    public function statusOptions(): array
    {
        return ['open' => 'Planning or in progress'] + array_map(fn ($s) => $s[0], Project::STATUSES);
    }

    public function run(ReportFilters $f, User $user): ReportResult
    {
        $money = $user->can('View payment') || $user->can('View financial reports');
        $cost = $user->can('View costing') || $user->can('View financial reports');
        $now = CarbonImmutable::now($f->tz);
        $today = $now->toDateString();

        // As of now: every project (upcoming ones matter most), soonest event first.
        $projects = Project::visibleTo($user)
            ->when(! $f->allTime, fn ($q) => $q->where(fn ($w) => $w->whereBetween('event_date', $f->dateRange())
                ->orWhere(fn ($n) => $n->whereNull('event_date')->whereBetween('created_at', [$f->fromUtc(), $f->toUtc()]))))
            ->when($f->branchId, fn ($q) => $q->where('branch_id', $f->branchId))
            ->when($f->staffId, fn ($q) => $q->where('manager_id', $f->staffId))
            ->when($f->status === 'open', fn ($q) => $q->whereIn('status', ['planning', 'active']))
            ->when($f->status && $f->status !== 'open', fn ($q) => $q->where('status', $f->status))
            ->when(! $f->status, fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->with('customer:id,first_name,last_name', 'manager:id,name')
            ->orderByRaw('event_date is null')->orderBy('event_date')->limit(300)->get();

        $service = app(ProjectService::class);
        $rows = [];
        foreach ($projects as $p) {
            $s = $service->summary($p);
            $days = $p->event_date ? (int) $now->startOfDay()->diffInDays(CarbonImmutable::parse($p->event_date->toDateString(), $f->tz), false) : null;
            $open = in_array($p->status, ['planning', 'active'], true);
            $atRisk = $open && $days !== null && $days <= 14 && $s['garments']['readiness'] < 100;
            $rows[] = [
                'project' => $p->name, '_link' => route('projects.show', $p), 'number' => $p->number,
                'client' => $p->customer?->name, 'type' => Project::TYPES[$p->type] ?? $p->type,
                'status' => Project::STATUSES[$p->status][0] ?? $p->status,
                'event' => $p->event_date?->toDateString(), 'days' => $open ? $days : null,
                'manager' => $p->manager?->name,
                'garments' => $s['garments']['total'], 'ready' => $s['garments']['ready'], 'readiness' => $s['garments']['readiness'],
                'late_milestones' => $s['late_milestones'],
                'value' => $s['money']['value'] ?: $s['money']['planned'], 'contract' => (float) $p->contract_amount,
                'collected' => $s['money']['collected'], 'owed' => $s['money']['outstanding'],
                'budget' => $s['costs']['budget'], 'actual' => $s['costs']['actual'], 'margin' => $s['costs']['margin'], 'margin_percent' => $s['costs']['margin_percent'],
                '_tone' => $atRisk ? 'danger' : ($s['late_milestones'] > 0 && $open ? 'warning' : null), '_risk' => $atRisk,
            ];
        }
        $all = collect($rows);

        $r = new ReportResult();
        $r->card('Projects', $all->count(), 'int', $all->whereIn('status', ['Planning', 'In progress'])->count() . ' still open', 'violet', 'ri-heart-2-line')
          ->card('Garments ready', $all->sum('ready'), 'int', 'of ' . number_format($all->sum('garments')) . ' garment(s)', 'teal', 'ri-t-shirt-air-line');
        if ($money) {
            $r->card('Collected', round($all->sum('collected'), 2), 'money', 'Owed ' . \App\Support\Money::format(round($all->sum('owed'), 2)), 'blue', 'ri-hand-coin-line');
        }
        if ($cost) {
            $r->card('Estimated margin', round($all->sum('margin'), 2), 'money', 'After materials and project expenses', $all->sum('margin') >= 0 ? 'teal' : 'rose', 'ri-scales-3-line');
        } else {
            $r->card('Close to the date and not ready', $all->where('_risk', true)->count(), 'int', 'Event within 14 days', 'rose', 'ri-alarm-warning-line');
        }

        if ($all->isNotEmpty()) {
            $chartRows = $all->take(12);
            $r->chart('bar', $chartRows->pluck('project')->all(), [['name' => 'Ready %', 'data' => $chartRows->pluck('readiness')->all()]], 'percent', 'How ready each project is');
        }

        $columns = [
            ['key' => 'project', 'label' => 'Project'], ['key' => 'client', 'label' => 'Client'], ['key' => 'status', 'label' => 'Stage'],
            ['key' => 'event', 'label' => 'Event', 'format' => 'date'], ['key' => 'days', 'label' => 'Days to go', 'format' => 'int'],
            ['key' => 'ready', 'label' => 'Ready', 'format' => 'int'], ['key' => 'garments', 'label' => 'Garments', 'format' => 'int'],
            ['key' => 'readiness', 'label' => 'Ready %', 'format' => 'percent'], ['key' => 'late_milestones', 'label' => 'Late milestones', 'format' => 'int'],
        ];
        if ($money) {
            array_push($columns, ['key' => 'value', 'label' => 'Garment value', 'format' => 'money'], ['key' => 'collected', 'label' => 'Collected', 'format' => 'money'],
                ['key' => 'owed', 'label' => 'Owed', 'format' => 'money']);
        }
        $r->table('projects', 'Projects', $columns, $rows, $money ? ['project' => 'Total', 'ready' => $all->sum('ready'), 'garments' => $all->sum('garments'),
            'value' => round($all->sum('value'), 2), 'collected' => round($all->sum('collected'), 2), 'owed' => round($all->sum('owed'), 2)] : null, null,
            'No projects in this period.');

        if ($cost) {
            $costRows = $all->map(fn ($p) => [
                'project' => $p['project'], '_link' => $p['_link'], 'contract' => $p['contract'] ?: null, 'value' => $p['value'],
                'budget' => $p['budget'] ?: null, 'actual' => $p['actual'],
                'over' => $p['budget'] > 0 && $p['actual'] > $p['budget'] ? round($p['actual'] - $p['budget'], 2) : null,
                'margin' => $p['margin'], 'margin_percent' => $p['margin_percent'],
                '_tone' => $p['budget'] > 0 && $p['actual'] > $p['budget'] ? 'danger' : null,
            ])->all();
            $r->table('profit', 'Budget, cost and margin', [
                ['key' => 'project', 'label' => 'Project'], ['key' => 'contract', 'label' => 'Contract', 'format' => 'money'],
                ['key' => 'value', 'label' => 'Garment value', 'format' => 'money'], ['key' => 'budget', 'label' => 'Budget', 'format' => 'money'],
                ['key' => 'actual', 'label' => 'Actual cost', 'format' => 'money'], ['key' => 'over', 'label' => 'Over budget', 'format' => 'money'],
                ['key' => 'margin', 'label' => 'Margin', 'format' => 'money'], ['key' => 'margin_percent', 'label' => 'Margin %', 'format' => 'percent'],
            ], $costRows, true, 'Actual cost = material issued to the project\'s orders + project expenses. Labour counts only when recorded as an expense.', 'No projects in this period.');
        }

        $r->note('Money and readiness are as of now, soonest event first. Pick dates to see only projects whose event falls in that period (or, without an event date, that were created then). Cancelled projects are left out unless you pick that stage.')
          ->note('Red rows: the event is within 14 days and not every garment is ready. Amber: a milestone is past its date.');
        if (! $money) {
            $r->note('Money columns are shown to people who can view payments.');
        }

        return $r;
    }
}
