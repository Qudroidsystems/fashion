<?php

namespace App\Services\Reports\Definitions;

use App\Models\User;
use App\Services\Reports\FinanceFacts;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use Carbon\CarbonImmutable;

/** Estimated profit & loss (FR-REP-005 "estimated/actual profit"). */
class ProfitReport extends Report
{
    public function key(): string
    {
        return 'profit';
    }

    public function title(): string
    {
        return 'Profit & loss (estimated)';
    }

    public function description(): string
    {
        return 'Sales earned less the cost of what was sold, expenses and stock written off.';
    }

    public function icon(): string
    {
        return 'ri-line-chart-line';
    }

    public function permissions(): array
    {
        return ['View financial reports'];
    }

    public function features(): array
    {
        return ['reports_full'];
    }

    public function run(ReportFilters $f, User $user): ReportResult
    {
        $facts = new FinanceFacts($f);
        $sales = $facts->recognised();
        $net = $facts->netProfit();
        $r = new ReportResult();
        $pct = fn ($v) => $sales > 0 ? round(100 * $v / $sales, 1) : null;

        $r->card('Sales earned', $sales, 'money', null, 'violet', 'ri-shopping-bag-3-line')
          ->card('Gross profit', $facts->grossProfit(), 'money', $pct($facts->grossProfit()) !== null ? $pct($facts->grossProfit()) . '% of sales' : null, 'teal', 'ri-scales-3-line')
          ->card('Expenses', $facts->expenses(), 'money', null, 'rose', 'ri-money-dollar-box-line')
          ->card($net >= 0 ? 'Estimated profit' : 'Estimated loss', $net, 'money', $pct($net) !== null ? $pct($net) . '% of sales' : null, $net >= 0 ? 'teal' : 'rose', 'ri-line-chart-line');

        $rows = [
            ['line' => 'Orders handed over', 'amount' => round($facts->salesEvents()->where('source', 'orders')->sum('amount'), 2), '_indent' => true],
            ['line' => 'Till sales (after returns)', 'amount' => round($facts->salesEvents()->where('source', 'till')->sum('amount'), 2), '_indent' => true],
        ];
        if (! $f->branchId) {
            $rows[] = ['line' => 'Stand-alone invoices', 'amount' => round($facts->salesEvents()->where('source', 'invoices')->sum('amount'), 2), '_indent' => true];
        }
        $rows[] = ['line' => 'Sales earned', 'amount' => $sales, '_strong' => true];
        $rows[] = ['line' => 'Materials used on those orders', 'amount' => -$facts->orderMaterialCost(), '_indent' => true];
        $rows[] = ['line' => 'Cost of ready-made items sold', 'amount' => -$facts->tillCost(), '_indent' => true];
        $rows[] = ['line' => 'Gross profit', 'amount' => $facts->grossProfit(), '_strong' => true];
        foreach ($facts->expensesByCategory() as $cat => $amt) {
            $rows[] = ['line' => $cat, 'amount' => -$amt, '_indent' => true];
        }
        $rows[] = ['line' => 'Total expenses', 'amount' => -$facts->expenses(), '_strong' => true];
        $rows[] = ['line' => 'Stock written off (wastage, counts, adjustments)', 'amount' => -$facts->stockWrittenOff(), '_indent' => true];
        $rows[] = ['line' => $net >= 0 ? 'Estimated profit' : 'Estimated loss', 'amount' => $net, '_strong' => true];
        foreach ($rows as &$row) {
            $row['share'] = $pct($row['amount']);
        }
        $r->table('statement', 'Statement for ' . $f->label(), [
            ['key' => 'line', 'label' => ''], ['key' => 'amount', 'label' => 'Amount', 'format' => 'money'], ['key' => 'share', 'label' => '% of sales', 'format' => 'percent'],
        ], $rows);

        // Month by month when the range covers more than one month.
        $months = [];
        $cursor = $f->from->startOfMonth();
        while ($cursor->lte($f->to) && count($months) < 36) {
            $months[] = $cursor;
            $cursor = $cursor->addMonthNoOverflow()->startOfMonth();
        }
        if (count($months) > 1) {
            $trend = [];
            foreach ($months as $m) {
                $mf = clone $f;
                $mf->from = CarbonImmutable::parse(max($m->toDateString(), $f->fromDate()), $f->tz)->startOfDay();
                $mf->to = CarbonImmutable::parse(min($m->endOfMonth()->toDateString(), $f->toDate()), $f->tz)->endOfDay();
                $mfacts = new FinanceFacts($mf);
                $trend[] = ['month' => $m->format('M Y'), 'sales' => $mfacts->recognised(), 'cost' => $mfacts->costOfSales(),
                    'expenses' => round($mfacts->expenses() + $mfacts->stockWrittenOff(), 2), 'profit' => $mfacts->netProfit()];
            }
            $r->chart('bar', array_column($trend, 'month'), [
                ['name' => 'Sales earned', 'data' => array_column($trend, 'sales')],
                ['name' => 'Estimated profit', 'data' => array_column($trend, 'profit')],
            ]);
            $r->table('months', 'Month by month', [
                ['key' => 'month', 'label' => 'Month'], ['key' => 'sales', 'label' => 'Sales earned', 'format' => 'money'],
                ['key' => 'cost', 'label' => 'Cost of sales', 'format' => 'money'], ['key' => 'expenses', 'label' => 'Expenses & write-offs', 'format' => 'money'],
                ['key' => 'profit', 'label' => 'Estimated profit', 'format' => 'money'],
            ], $trend, true);
        }

        $r->note('This is an estimate from what is recorded: tailors\' pay and other labour count only when recorded as expenses (for example under Salaries & wages).')
          ->note('Materials are counted when an order is handed over, at the stock cost when they were issued (weighted average).')
          ->note('Deposits for orders not yet handed over are not in sales; see Sales & money collected.');

        return $r;
    }
}
