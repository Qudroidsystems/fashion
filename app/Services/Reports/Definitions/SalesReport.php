<?php

namespace App\Services\Reports\Definitions;

use App\Models\Branch;
use App\Models\Payment;
use App\Models\User;
use App\Services\Reports\FinanceFacts;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;

/** Sales & collections (SRS GET /reports/sales, FR-REP-005). */
class SalesReport extends Report
{
    public function key(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Sales & money collected';
    }

    public function description(): string
    {
        return 'Money in and out by method, what was invoiced, sales earned and deposits held for unfinished work.';
    }

    public function icon(): string
    {
        return 'ri-money-dollar-circle-line';
    }

    public function permissions(): array
    {
        return ['View financial reports'];
    }

    public function run(ReportFilters $f, User $user): ReportResult
    {
        $facts = new FinanceFacts($f);
        $money = $facts->moneyEvents();
        $sales = $facts->salesEvents();
        $r = new ReportResult();

        $r->card('Money collected', $facts->collected(), 'money', 'In ' . \App\Support\Money::format($facts->moneyIn()) . ' · out ' . \App\Support\Money::format($facts->moneyOut()), 'teal', 'ri-hand-coin-line')
          ->card('Sales earned', $facts->recognised(), 'money', 'Orders handed over + till sales', 'violet', 'ri-shopping-bag-3-line')
          ->card('Invoiced', $facts->invoiced(), 'money', $facts->invoices()->count() . ' invoice(s) issued', 'blue', 'ri-bill-line')
          ->card('Deposits held', $facts->depositsHeld(), 'money', 'Paid toward unfinished orders on ' . $f->to->format('j M'), 'amber', 'ri-safe-2-line');

        // Trend: money collected vs sales earned per period.
        $buckets = $f->buckets();
        $in = array_fill_keys(array_keys($buckets), 0.0);
        $earned = $in;
        foreach ($money as $m) {
            $k = $f->bucket($m['at']);
            isset($in[$k]) && $in[$k] += $m['amount'];
        }
        foreach ($sales as $s) {
            $k = $f->bucket($s['at']);
            isset($earned[$k]) && $earned[$k] += $s['amount'];
        }
        $r->chart('bar', array_values($buckets), [
            ['name' => 'Money collected', 'data' => array_map(fn ($v) => round($v, 2), array_values($in))],
            ['name' => 'Sales earned', 'data' => array_map(fn ($v) => round($v, 2), array_values($earned))],
        ]);

        // By method.
        $methods = Payment::METHODS + ['store_credit' => 'Store credit'];
        $rows = [];
        foreach ($money->groupBy('method') as $method => $g) {
            $rows[] = ['method' => $methods[$method] ?? ucfirst(str_replace('_', ' ', $method)),
                'in' => round($g->where('amount', '>', 0)->sum('amount'), 2),
                'out' => round(-$g->where('amount', '<', 0)->sum('amount'), 2),
                'net' => round($g->sum('amount'), 2)];
        }
        usort($rows, fn ($a, $b) => $b['net'] <=> $a['net']);
        $r->table('methods', 'Money by method', [
            ['key' => 'method', 'label' => 'Method'], ['key' => 'in', 'label' => 'Received', 'format' => 'money'],
            ['key' => 'out', 'label' => 'Refunded / reversed', 'format' => 'money'], ['key' => 'net', 'label' => 'Collected', 'format' => 'money'],
        ], $rows, true, null, 'No money moved in this period.');

        // Where the money came from.
        $kinds = ['counter' => 'Payments at the counter', 'online' => 'Online payments (Paystack)', 'till_orders' => 'Order payments at the till',
            'till_items' => 'Ready-made items at the till', 'refund' => 'Refunds paid', 'till_refund' => 'Till refunds', 'reversal' => 'Bounced / reversed payments'];
        $src = [];
        foreach ($kinds as $k => $label) {
            $g = $money->where('kind', $k);
            if ($g->isNotEmpty()) {
                $src[] = ['source' => $label, 'count' => $g->count(), 'amount' => round($g->sum('amount'), 2)];
            }
        }
        $r->table('sources', 'Where it came from', [
            ['key' => 'source', 'label' => 'Source'], ['key' => 'count', 'label' => 'Entries', 'format' => 'int'], ['key' => 'amount', 'label' => 'Amount', 'format' => 'money'],
        ], $src, true);

        // Sales earned by source.
        $earnedRows = [
            ['source' => 'Orders handed over', 'count' => $facts->deliveredOrders()->count(), 'amount' => round($sales->where('source', 'orders')->sum('amount'), 2)],
            ['source' => 'Till sales (after returns)', 'count' => null, 'amount' => round($sales->where('source', 'till')->sum('amount'), 2)],
        ];
        if (! $f->branchId) {
            $earnedRows[] = ['source' => 'Stand-alone invoices', 'count' => $sales->where('source', 'invoices')->count(), 'amount' => round($sales->where('source', 'invoices')->sum('amount'), 2)];
        }
        $r->table('earned', 'Sales earned', [
            ['key' => 'source', 'label' => 'From'], ['key' => 'count', 'label' => 'Count', 'format' => 'int'], ['key' => 'amount', 'label' => 'Amount', 'format' => 'money'],
        ], $earnedRows, true);

        // By branch, when looking at the whole house.
        if (! $f->branchId && Branch::count() > 1) {
            $b = [];
            foreach (Branch::orderBy('name')->get(['id', 'name']) as $branch) {
                $bf = clone $f;
                $bf->branchId = $branch->id;
                $bfacts = new FinanceFacts($bf);
                $b[] = ['branch' => $branch->name, 'collected' => $bfacts->collected(), 'earned' => $bfacts->recognised(), 'deposits' => $bfacts->depositsHeld()];
            }
            $r->table('branches', 'By branch', [
                ['key' => 'branch', 'label' => 'Branch'], ['key' => 'collected', 'label' => 'Collected', 'format' => 'money'],
                ['key' => 'earned', 'label' => 'Sales earned', 'format' => 'money'], ['key' => 'deposits', 'label' => 'Deposits held', 'format' => 'money'],
            ], $b, true, 'Stand-alone invoices have no branch, so branch sales can add up to slightly less than the house total.');
        }

        $r->note('Money collected is cash actually received less refunds and bounced payments. Store credit given for returns is not money.')
          ->note('Sales earned counts an order when it is handed over, at its full value; money paid before that is a deposit, not a sale.')
          ->note('Invoiced is what was billed; it can be more or less than what was collected.');

        return $r;
    }
}
