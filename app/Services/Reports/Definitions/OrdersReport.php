<?php

namespace App\Services\Reports\Definitions;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Support\Orders\OrderStatus;
use Carbon\CarbonImmutable;

/** Order pipeline and throughput (SRS GET /reports/order-throughput, FR-REP-001 pipeline / due / overdue). */
class OrdersReport extends Report
{
    public function key(): string
    {
        return 'orders';
    }

    public function title(): string
    {
        return 'Orders: pipeline & delivery';
    }

    public function description(): string
    {
        return 'Orders taken and handed over, how many were on time, how long they take, and what is late now.';
    }

    public function group(): string
    {
        return 'Sales & orders';
    }

    public function icon(): string
    {
        return 'ri-file-list-3-line';
    }

    public function permissions(): array
    {
        return ['View order'];
    }

    public function filters(): array
    {
        return ['range', 'branch', 'staff'];
    }

    public function run(ReportFilters $f, User $user): ReportResult
    {
        $money = $user->can('View payment') || $user->can('View financial reports');
        $scope = fn ($q) => $q->when($f->branchId, fn ($w) => $w->where('branch_id', $f->branchId))->when($f->staffId, fn ($w) => $w->where('owner_id', $f->staffId));
        $today = CarbonImmutable::now($f->tz)->toDateString();

        $confirmed = $scope(Order::whereBetween('confirmed_at', [$f->fromUtc(), $f->toUtc()]))->get(['id', 'total', 'confirmed_at', 'status']);
        $delivered = $scope(Order::whereNotNull('delivered_at')->where('status', '!=', 'cancelled')->whereBetween('delivered_at', [$f->fromUtc(), $f->toUtc()]))
            ->with('customer:id,first_name,last_name')->get(['id', 'public_id', 'number', 'customer_id', 'total', 'confirmed_at', 'delivered_at', 'promised_date']);
        $cancelled = $scope(Order::where('status', 'cancelled')->whereBetween('cancelled_at', [$f->fromUtc(), $f->toUtc()]))->count();

        $withPromise = $delivered->filter(fn ($o) => $o->promised_date);
        $late = $withPromise->filter(fn ($o) => CarbonImmutable::instance($o->delivered_at)->setTimezone($f->tz)->toDateString() > $o->promised_date->toDateString());
        $onTime = $withPromise->count() ? round(100 * ($withPromise->count() - $late->count()) / $withPromise->count(), 1) : null;
        $durations = $delivered->filter(fn ($o) => $o->confirmed_at)->map(fn ($o) => $o->confirmed_at->diffInHours($o->delivered_at) / 24);
        $open = $scope(Order::whereIn('status', OrderStatus::OPEN))->with('customer:id,first_name,last_name', 'owner:id,name')
            ->withCount(['lines as garments' => fn ($q) => $q->where('status', '!=', 'cancelled')])
            ->get(['id', 'public_id', 'number', 'customer_id', 'owner_id', 'status', 'total', 'promised_date', 'risk_level']);
        $overdue = $open->filter(fn ($o) => $o->promised_date && $o->promised_date->toDateString() < $today);

        $r = new ReportResult();
        $r->card('Orders confirmed', $confirmed->count(), 'int', $money ? 'Worth ' . \App\Support\Money::format($confirmed->sum('total')) : null, 'blue', 'ri-file-add-line')
          ->card('Handed over', $delivered->count(), 'int', $cancelled ? $cancelled . ' cancelled in the period' : null, 'teal', 'ri-hand-heart-line')
          ->card('On time', $onTime, 'percent', $withPromise->count() ? $late->count() . ' of ' . $withPromise->count() . ' late' : 'No promise dates', $onTime !== null && $onTime < 80 ? 'rose' : 'teal', 'ri-timer-line')
          ->card('Overdue now', $overdue->count(), 'int', 'Average ' . ($durations->count() ? round($durations->avg(), 1) : '—') . ' days from confirmed to handed over', $overdue->count() ? 'rose' : 'teal', 'ri-alarm-warning-line');

        $buckets = $f->buckets();
        $in = array_fill_keys(array_keys($buckets), 0);
        $out = $in;
        foreach ($confirmed as $o) {
            $k = $f->bucket($o->confirmed_at);
            isset($in[$k]) && $in[$k]++;
        }
        foreach ($delivered as $o) {
            $k = $f->bucket($o->delivered_at);
            isset($out[$k]) && $out[$k]++;
        }
        $r->chart('bar', array_values($buckets), [['name' => 'Confirmed', 'data' => array_values($in)], ['name' => 'Handed over', 'data' => array_values($out)]], 'int');

        $pipe = [];
        foreach (OrderStatus::OPEN as $s) {
            $g = $open->where('status', $s);
            $pipe[] = ['stage' => OrderStatus::label($s), 'orders' => $g->count(), 'garments' => (int) $g->sum('garments'),
                'value' => $money ? round($g->sum('total'), 2) : null, 'overdue' => $g->filter(fn ($o) => $o->promised_date && $o->promised_date->toDateString() < $today)->count()];
        }
        $cols = [['key' => 'stage', 'label' => 'Stage now'], ['key' => 'orders', 'label' => 'Orders', 'format' => 'int'], ['key' => 'garments', 'label' => 'Garments', 'format' => 'int'],
            ['key' => 'overdue', 'label' => 'Overdue', 'format' => 'int']];
        if ($money) {
            $cols[] = ['key' => 'value', 'label' => 'Value', 'format' => 'money'];
        }
        $r->table('pipeline', 'Open orders by stage', $cols, $pipe, true);

        $r->table('overdue', 'Late now', [
            ['key' => 'number', 'label' => 'Order'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'stage', 'label' => 'Stage'],
            ['key' => 'promised', 'label' => 'Promised', 'format' => 'date'], ['key' => 'days', 'label' => 'Days late', 'format' => 'int'], ['key' => 'owner', 'label' => 'Owner'],
        ], $overdue->sortBy('promised_date')->map(fn ($o) => ['number' => $o->number, '_link' => route('orders.show', $o), 'customer' => $o->customer?->name,
            'stage' => OrderStatus::label($o->status), 'promised' => $o->promised_date->toDateString(),
            'days' => (int) CarbonImmutable::parse($o->promised_date->toDateString(), $f->tz)->diffInDays(CarbonImmutable::parse($today, $f->tz)),
            'owner' => $o->owner?->name, '_tone' => 'danger'])->values()->all(), null, null, 'Nothing is late. ');

        $r->table('late', 'Handed over late in this period', [
            ['key' => 'number', 'label' => 'Order'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'promised', 'label' => 'Promised', 'format' => 'date'],
            ['key' => 'delivered', 'label' => 'Handed over', 'format' => 'date'], ['key' => 'days', 'label' => 'Days late', 'format' => 'int'],
        ], $late->map(fn ($o) => ['number' => $o->number, '_link' => route('orders.show', $o), 'customer' => $o->customer?->name, 'promised' => $o->promised_date->toDateString(),
            'delivered' => CarbonImmutable::instance($o->delivered_at)->setTimezone($f->tz)->toDateString(),
            'days' => (int) CarbonImmutable::parse($o->promised_date->toDateString(), $f->tz)->diffInDays(CarbonImmutable::instance($o->delivered_at)->setTimezone($f->tz)->startOfDay())])
            ->sortByDesc('days')->values()->all(), null, null, 'Everything handed over in this period was on time.');

        $r->note('“On time” compares the day an order was handed over with its promised date. Orders without a promised date are not counted either way.');

        return $r;
    }
}
