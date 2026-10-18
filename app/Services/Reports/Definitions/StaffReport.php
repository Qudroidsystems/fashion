<?php

namespace App\Services\Reports\Definitions;

use App\Models\CashShift;
use App\Models\Customer;
use App\Models\FollowUpTask;
use App\Models\MeasurementSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductionTask;
use App\Models\Sale;
use App\Models\User;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;

/** What each person did in the period. Money columns only for people who see financial reports. */
class StaffReport extends Report
{
    public function key(): string
    {
        return 'staff';
    }

    public function title(): string
    {
        return 'Staff activity';
    }

    public function description(): string
    {
        return 'Orders taken, customers measured, money received, till sales, cash differences and work finished — per person.';
    }

    public function group(): string
    {
        return 'Workroom';
    }

    public function icon(): string
    {
        return 'ri-team-line';
    }

    public function permissions(): array
    {
        return ['View user', 'View financial reports'];
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
        $money = $user->can('View financial reports');
        [$from, $to, $branch] = [$f->fromUtc(), $f->toUtc(), $f->branchId];
        $b = fn ($q) => $q->when($branch, fn ($w) => $w->where('branch_id', $branch));
        $who = fn ($col) => fn ($q) => $q->when($f->staffId, fn ($w) => $w->where($col, $f->staffId));

        $orders = Order::whereBetween('confirmed_at', [$from, $to])->tap($b)->get(['owner_id', 'created_by', 'total'])
            ->groupBy(fn ($o) => $o->owner_id ?: $o->created_by);
        $customers = Customer::whereBetween('created_at', [$from, $to])->tap($b)->tap($who('created_by'))->selectRaw('created_by, count(*) n')->groupBy('created_by')->pluck('n', 'created_by');
        $measured = MeasurementSession::whereIn('status', ['submitted', 'approved', 'superseded'])->whereBetween('measured_at', [$from, $to])->tap($b)
            ->tap($who('measured_by'))->selectRaw('measured_by, count(*) n')->groupBy('measured_by')->pluck('n', 'measured_by');
        $payments = Payment::whereBetween('received_at', [$from, $to])->tap($b)->tap($who('received_by'))
            ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', '!=', 'pos')->orWhereNotNull('sale_id'))
            ->selectRaw('received_by, sum(amount) total, count(*) n')->groupBy('received_by')->get()->keyBy('received_by');
        $sales = Sale::where('status', 'completed')->whereBetween('created_at', [$from, $to])->tap($b)->tap($who('cashier_id'))
            ->selectRaw('cashier_id, sum(total) total, count(*) n')->groupBy('cashier_id')->get()->keyBy('cashier_id');
        $shifts = CashShift::where('status', 'closed')->whereBetween('closed_at', [$from, $to])->tap($b)->tap($who('user_id'))
            ->selectRaw('user_id, sum(variance) variance, count(*) n')->groupBy('user_id')->get()->keyBy('user_id');
        $tasks = ProductionTask::where('status', 'done')->whereBetween('completed_at', [$from, $to])
            ->when($branch, fn ($q) => $q->whereHas('order', fn ($o) => $o->where('branch_id', $branch)))->tap($who('assigned_to'))
            ->selectRaw('assigned_to, count(*) n')->groupBy('assigned_to')->pluck('n', 'assigned_to');
        $follow = FollowUpTask::where('status', 'done')->whereBetween('completed_at', [$from, $to])->tap($who('completed_by'))
            ->selectRaw('completed_by, count(*) n')->groupBy('completed_by')->pluck('n', 'completed_by');

        $people = User::inCurrentTenant()->when($f->staffId, fn ($q) => $q->where('users.id', $f->staffId))->orderBy('name')->get(['users.id', 'name']);
        $rows = [];
        foreach ($people as $p) {
            $o = $orders->get($p->id, collect());
            $row = [
                'person' => $p->name, 'orders' => $o->count(), 'order_value' => $money ? round($o->sum('total'), 2) : null,
                'customers' => (int) ($customers[$p->id] ?? 0), 'measured' => (int) ($measured[$p->id] ?? 0),
                'payments' => $money ? round((float) ($payments[$p->id]->total ?? 0), 2) : null,
                'till_sales' => (int) ($sales[$p->id]->n ?? 0), 'till_value' => $money ? round((float) ($sales[$p->id]->total ?? 0), 2) : null,
                'variance' => $money && isset($shifts[$p->id]) ? round((float) $shifts[$p->id]->variance, 2) : null,
                'tasks' => (int) ($tasks[$p->id] ?? 0), 'follow_ups' => (int) ($follow[$p->id] ?? 0),
            ];
            $row['_tone'] = $money && $row['variance'] !== null && abs($row['variance']) >= 0.01 ? 'warning' : null;
            if ($row['orders'] + $row['customers'] + $row['measured'] + $row['till_sales'] + $row['tasks'] + $row['follow_ups'] > 0 || ($money && $row['payments'] > 0)) {
                $rows[] = $row;
            }
        }

        $r = new ReportResult();
        $r->card('People active', count($rows), 'int', $f->label(), 'violet', 'ri-team-line')
          ->card('Orders taken', array_sum(array_column($rows, 'orders')), 'int', null, 'blue', 'ri-file-add-line')
          ->card('Customers measured', array_sum(array_column($rows, 'measured')), 'int', null, 'teal', 'ri-ruler-2-line');
        if ($money) {
            $r->card('Cash differences at close', round(array_sum(array_map(fn ($x) => $x['variance'] ?? 0, $rows)), 2), 'money', 'Over (+) / short (−)', 'amber', 'ri-scales-3-line');
        } else {
            $r->card('Tasks finished', array_sum(array_column($rows, 'tasks')), 'int', null, 'amber', 'ri-checkbox-circle-line');
        }

        $cols = [['key' => 'person', 'label' => 'Person'], ['key' => 'orders', 'label' => 'Orders', 'format' => 'int']];
        if ($money) {
            $cols[] = ['key' => 'order_value', 'label' => 'Order value', 'format' => 'money'];
        }
        $cols[] = ['key' => 'customers', 'label' => 'New customers', 'format' => 'int'];
        $cols[] = ['key' => 'measured', 'label' => 'Measured', 'format' => 'int'];
        if ($money) {
            $cols[] = ['key' => 'payments', 'label' => 'Payments taken', 'format' => 'money'];
        }
        $cols[] = ['key' => 'till_sales', 'label' => 'Till sales', 'format' => 'int'];
        if ($money) {
            $cols[] = ['key' => 'till_value', 'label' => 'Till value', 'format' => 'money'];
            $cols[] = ['key' => 'variance', 'label' => 'Cash over / short', 'format' => 'money'];
        }
        $cols[] = ['key' => 'tasks', 'label' => 'Tasks done', 'format' => 'int'];
        $cols[] = ['key' => 'follow_ups', 'label' => 'Follow-ups done', 'format' => 'int'];
        $r->table('people', 'Per person', $cols, $rows, true, null, 'Nobody recorded any activity in this period.');
        $r->note('Orders count for the order owner (or whoever created it) when confirmed. Cash over/short is the difference found when closing till shifts.');

        return $r;
    }
}
