<?php

namespace App\Services\Reports\Definitions;

use App\Models\Customer;
use App\Models\FollowUpTask;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SiteEnquiry;
use App\Models\User;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use Carbon\CarbonImmutable;

/** New and returning customers, where they come from, top spenders and the follow-up queue. */
class CustomersReport extends Report
{
    public function key(): string
    {
        return 'customers';
    }

    public function title(): string
    {
        return 'Customers';
    }

    public function description(): string
    {
        return 'New and returning customers, where they heard of you, who spends the most, and follow-ups waiting.';
    }

    public function group(): string
    {
        return 'Customers';
    }

    public function icon(): string
    {
        return 'ri-user-heart-line';
    }

    public function permissions(): array
    {
        return ['View customer'];
    }

    public function features(): array
    {
        return ['reports_full'];
    }

    public function run(ReportFilters $f, User $user): ReportResult
    {
        $money = $user->can('View payment') || $user->can('View financial reports');
        [$from, $to, $branch] = [$f->fromUtc(), $f->toUtc(), $f->branchId];

        $new = Customer::whereIn('status', ['active', 'archived'])->whereBetween('created_at', [$from, $to])
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))->get(['id', 'source', 'created_at']);
        $buyerIds = Order::whereBetween('confirmed_at', [$from, $to])->when($branch, fn ($q) => $q->where('branch_id', $branch))->pluck('customer_id')
            ->merge(Sale::where('status', 'completed')->whereNotNull('customer_id')->whereBetween('created_at', [$from, $to])->when($branch, fn ($q) => $q->where('branch_id', $branch))->pluck('customer_id'))
            ->unique()->values();
        $returning = $buyerIds->isEmpty() ? collect() : Order::whereIn('customer_id', $buyerIds)->where('confirmed_at', '<', $from)->distinct()->pluck('customer_id')
            ->merge(Sale::whereIn('customer_id', $buyerIds)->where('status', 'completed')->where('created_at', '<', $from)->distinct()->pluck('customer_id'))->unique();
        $enquiries = SiteEnquiry::whereBetween('created_at', [$from, $to])->where('status', '!=', 'spam')->get(['id', 'status']);
        $now = CarbonImmutable::now($f->tz);
        $follow = FollowUpTask::where('status', 'open')->with('assignee:id,name')->get(['id', 'assigned_to', 'due_at']);

        $r = new ReportResult();
        $r->card('New customers', $new->count(), 'int', $f->label(), 'teal', 'ri-user-add-line')
          ->card('Customers who bought', $buyerIds->count(), 'int', $returning->count() . ' had bought before', 'violet', 'ri-shopping-bag-3-line')
          ->card('Returning share', $buyerIds->count() ? round(100 * $returning->count() / $buyerIds->count(), 1) : null, 'percent', 'Bought before this period', 'blue', 'ri-repeat-line')
          ->card('Follow-ups overdue', $follow->filter(fn ($t) => $t->due_at && $t->due_at->lt($now))->count(), 'int', $follow->count() . ' open', 'amber', 'ri-alarm-warning-line');

        $sources = $new->groupBy(fn ($c) => $c->source ?: 'Not recorded')->map(fn ($g, $s) => ['source' => ucfirst($s), 'count' => $g->count()])->sortByDesc('count')->values()->all();
        $r->chart('donut', array_column($sources, 'source'), [['name' => 'New customers', 'data' => array_column($sources, 'count')]], 'int');
        $r->table('sources', 'Where new customers came from', [['key' => 'source', 'label' => 'Source'], ['key' => 'count', 'label' => 'Customers', 'format' => 'int']],
            $sources, true, null, 'No new customers in this period.');

        if ($money) {
            $top = Payment::whereBetween('received_at', [$from, $to])->where('status', 'confirmed')->when($branch, fn ($q) => $q->where('branch_id', $branch))
                ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', '!=', 'pos')->orWhereNotNull('sale_id'))
                ->selectRaw('customer_id, sum(amount) total, count(*) n')->groupBy('customer_id')->orderByDesc('total')->limit(20)->get();
            // Ready-made items bought at the till (order payments at the till are already payments above).
            $till = \App\Models\SaleLine::where('kind', 'product')
                ->whereHas('sale', fn ($q) => $q->where('status', 'completed')->whereNotNull('customer_id')->whereBetween('created_at', [$from, $to])->when($branch, fn ($w) => $w->where('branch_id', $branch)))
                ->with('sale:id,customer_id')->get(['sale_id', 'line_total'])
                ->groupBy(fn ($l) => $l->sale->customer_id)->map(fn ($g) => round($g->sum('line_total'), 2));
            $people = Customer::whereIn('id', $top->pluck('customer_id')->merge($till->keys()))->get(['id', 'public_id', 'first_name', 'last_name', 'phone', 'is_vip'])->keyBy('id');
            $rows = $top->map(fn ($t) => ['id' => $t->customer_id, 'paid' => (float) $t->total])->concat($till->map(fn ($v, $id) => ['id' => $id, 'paid' => (float) $v])->values())
                ->groupBy('id')->map(fn ($g, $id) => ['customer' => $people[$id]?->name ?? '—', '_link' => isset($people[$id]) ? route('customers.show', $people[$id]) : null,
                    'phone' => $people[$id]?->phone, 'vip' => ($people[$id]?->is_vip ?? false) ? 'VIP' : '', 'paid' => round($g->sum('paid'), 2)])
                ->sortByDesc('paid')->take(20)->values()->all();
            $r->table('top', 'Top customers by money paid', [['key' => 'customer', 'label' => 'Customer'], ['key' => 'phone', 'label' => 'Phone'], ['key' => 'vip', 'label' => ''],
                ['key' => 'paid', 'label' => 'Paid in period', 'format' => 'money']], $rows, null, null, 'No payments from customers in this period.');
        }

        $queue = $follow->groupBy('assigned_to')->map(fn ($g) => ['person' => $g->first()->assignee?->name ?? 'Not assigned', 'open' => $g->count(),
            'overdue' => $g->filter(fn ($t) => $t->due_at && $t->due_at->lt($now))->count()])->sortByDesc('overdue')->values()->all();
        $r->table('follow_ups', 'Follow-ups waiting now', [['key' => 'person', 'label' => 'Assigned to'], ['key' => 'open', 'label' => 'Open', 'format' => 'int'],
            ['key' => 'overdue', 'label' => 'Overdue', 'format' => 'int']], $queue, true, null, 'No follow-ups waiting.');

        if ($enquiries->isNotEmpty()) {
            $r->table('website', 'Website enquiries', [['key' => 'what', 'label' => ''], ['key' => 'count', 'label' => 'Count', 'format' => 'int']], [
                ['what' => 'Received', 'count' => $enquiries->count()],
                ['what' => 'Became customers', 'count' => $enquiries->where('status', 'converted')->count()],
                ['what' => 'Still to answer', 'count' => $enquiries->where('status', 'new')->count(), '_tone' => 'warning'],
            ]);
        }
        $r->note('A customer “bought” if an order of theirs was confirmed or they were named on a till sale in the period.');

        return $r;
    }
}
