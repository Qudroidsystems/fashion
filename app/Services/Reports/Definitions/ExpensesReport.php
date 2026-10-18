<?php

namespace App\Services\Reports\Definitions;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\ProjectExpense;
use App\Models\User;
use App\Services\Reports\FinanceFacts;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;

class ExpensesReport extends Report
{
    public function key(): string
    {
        return 'expenses';
    }

    public function title(): string
    {
        return 'Expenses';
    }

    public function description(): string
    {
        return 'Running costs by category and branch, with wedding and project costs alongside.';
    }

    public function icon(): string
    {
        return 'ri-money-dollar-box-line';
    }

    public function permissions(): array
    {
        return ['View financial reports'];
    }

    public function filters(): array
    {
        return ['range', 'branch', 'category'];
    }

    public function categoryOptions(): array
    {
        return collect(Expense::CATEGORIES)->merge(Expense::query()->distinct()->pluck('category'))->unique()->sort()->mapWithKeys(fn ($c) => [$c => $c])->all();
    }

    public function run(ReportFilters $f, User $user): ReportResult
    {
        $base = Expense::counted()->whereBetween('spent_on', $f->dateRange())
            ->when($f->branchId, fn ($q) => $q->where('branch_id', $f->branchId))
            ->when($f->category, fn ($q) => $q->where('category', $f->category));
        $items = (clone $base)->with('branch:id,name', 'recorder:id,name')->orderBy('spent_on')->orderBy('id')->get();
        $projects = $f->category ? collect() : ProjectExpense::whereBetween('spent_on', $f->dateRange())
            ->when($f->branchId, fn ($q) => $q->whereHas('project', fn ($p) => $p->where('branch_id', $f->branchId)))
            ->with('project:id,public_id,number,name')->orderBy('spent_on')->get();
        $house = round((float) $items->sum('amount'), 2);
        $proj = round((float) $projects->sum('amount'), 2);
        $sales = (new FinanceFacts($f))->recognised();

        $r = new ReportResult();
        $r->card('Total spent', round($house + $proj, 2), 'money', $f->label(), 'rose', 'ri-money-dollar-box-line')
          ->card('Running costs', $house, 'money', $items->count() . ' expense(s)', 'violet', 'ri-store-2-line')
          ->card('Project & wedding costs', $proj, 'money', $projects->count() . ' expense(s)', 'amber', 'ri-heart-2-line')
          ->card('As share of sales earned', $sales > 0 ? round(100 * ($house + $proj) / $sales, 1) : null, 'percent', 'Sales earned ' . \App\Support\Money::format($sales), 'teal', 'ri-percent-line');

        $byCat = $items->groupBy('category')->map(fn ($g, $c) => ['category' => $c, 'count' => $g->count(), 'amount' => round($g->sum('amount'), 2)])
            ->sortByDesc('amount')->values()->all();
        if ($proj > 0) {
            $byCat[] = ['category' => 'Projects & weddings', 'count' => $projects->count(), 'amount' => $proj];
        }
        $r->chart('donut', array_column($byCat, 'category'), [['name' => 'Spent', 'data' => array_column($byCat, 'amount')]]);
        $r->table('categories', 'By category', [
            ['key' => 'category', 'label' => 'Category'], ['key' => 'count', 'label' => 'Count', 'format' => 'int'], ['key' => 'amount', 'label' => 'Amount', 'format' => 'money'],
        ], $byCat, true, null, 'Nothing recorded in this period.');

        if (! $f->branchId && Branch::count() > 1) {
            $byBranch = $items->groupBy(fn ($e) => $e->branch?->name ?? 'House-wide')
                ->map(fn ($g, $b) => ['branch' => $b, 'amount' => round($g->sum('amount'), 2)])->sortByDesc('amount')->values()->all();
            $r->table('branches', 'By branch', [['key' => 'branch', 'label' => 'Branch'], ['key' => 'amount', 'label' => 'Amount', 'format' => 'money']], $byBranch, true);
        }

        $r->table('list', 'Running costs', [
            ['key' => 'date', 'label' => 'Date', 'format' => 'date'], ['key' => 'number', 'label' => 'No.'], ['key' => 'category', 'label' => 'Category'],
            ['key' => 'description', 'label' => 'What'], ['key' => 'paid_to', 'label' => 'Paid to'], ['key' => 'branch', 'label' => 'Branch'],
            ['key' => 'amount', 'label' => 'Amount', 'format' => 'money'],
        ], $items->map(fn ($e) => ['date' => $e->spent_on->toDateString(), 'number' => $e->number, 'category' => $e->category, 'description' => $e->description,
            'paid_to' => $e->paid_to, 'branch' => $e->branch?->name ?? 'House-wide', 'amount' => (float) $e->amount])->all(), true, null, 'Nothing recorded in this period.');

        if ($projects->isNotEmpty()) {
            $r->table('projects', 'Project & wedding costs', [
                ['key' => 'date', 'label' => 'Date', 'format' => 'date'], ['key' => 'project', 'label' => 'Project'], ['key' => 'category', 'label' => 'Category'],
                ['key' => 'description', 'label' => 'What'], ['key' => 'amount', 'label' => 'Amount', 'format' => 'money'],
            ], $projects->map(fn ($e) => ['date' => $e->spent_on->toDateString(), 'project' => $e->project?->number . ' ' . $e->project?->name,
                '_link' => $e->project ? route('projects.show', $e->project) : null, '_link_col' => 'project', 'category' => ucfirst($e->category), 'description' => $e->description,
                'amount' => (float) $e->amount])->all(), true);
        }
        $r->note('Voided expenses are left out. Fabric and stock bought are in the stock ledger, not here; they become a cost when used or sold.');

        return $r;
    }
}
