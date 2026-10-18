<?php

namespace App\Services\Reports\Definitions;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Support\Orders\OrderStatus;
use Carbon\CarbonImmutable;

/** Who owes the house what, and for how long (SRS GET /reports/receivables). */
class ReceivablesReport extends Report
{
    public const BUCKETS = ['0–30 days', '31–60 days', '61–90 days', 'Over 90 days'];

    public function key(): string
    {
        return 'receivables';
    }

    public function title(): string
    {
        return 'Money owed (receivables)';
    }

    public function description(): string
    {
        return 'Balances on orders and invoices, how old they are, and garments released before full payment.';
    }

    public function icon(): string
    {
        return 'ri-hand-coin-line';
    }

    public function permissions(): array
    {
        return ['View financial reports', 'View payment'];
    }

    public function filters(): array
    {
        return ['range', 'branch', 'staff'];
    }

    public function defaultPeriod(): string
    {
        return 'all';
    }

    /** Balances are always today's; the dates pick which orders (by confirmation) and invoices (by issue date) are counted. */
    public function asOf(): bool
    {
        return true;
    }

    public function rangeNoun(): string
    {
        return 'orders and invoices dated';
    }

    public function run(ReportFilters $f, User $user): ReportResult
    {
        $now = CarbonImmutable::now($f->tz);
        $orders = Order::whereIn('status', array_merge(OrderStatus::OPEN, ['delivered', 'closed']))
            ->when($f->branchId, fn ($q) => $q->where('branch_id', $f->branchId))
            ->when($f->staffId, fn ($q) => $q->where('owner_id', $f->staffId))
            ->when(! $f->allTime, fn ($q) => $q->whereBetween('confirmed_at', [$f->fromUtc(), $f->toUtc()]))
            ->with('customer:id,public_id,first_name,last_name,phone', 'owner:id,name')
            ->get(['id', 'public_id', 'number', 'customer_id', 'owner_id', 'status', 'total', 'confirmed_at', 'promised_date', 'delivered_at']);

        // Paid per order, directly or through its invoices — two grouped queries, not one per order.
        $paid = [];
        foreach ($orders->pluck('id')->chunk(500) as $chunk) {
            PaymentAllocation::whereIn('order_id', $chunk)->selectRaw('order_id, sum(amount) amt')->groupBy('order_id')->get()
                ->each(function ($r) use (&$paid) { $paid[$r->order_id] = ($paid[$r->order_id] ?? 0) + (float) $r->amt; });
            PaymentAllocation::join('invoices', 'invoices.id', '=', 'payment_allocations.invoice_id')->whereIn('invoices.order_id', $chunk)
                ->selectRaw('invoices.order_id oid, sum(payment_allocations.amount) amt')->groupBy('invoices.order_id')->get()
                ->each(function ($r) use (&$paid) { $paid[$r->oid] = ($paid[$r->oid] ?? 0) + (float) $r->amt; });
        }

        $rows = [];
        foreach ($orders as $o) {
            $balance = round((float) $o->total - ($paid[$o->id] ?? 0), 2);
            if ($balance <= 0.004) {
                continue;
            }
            $since = $o->confirmed_at ? CarbonImmutable::instance($o->confirmed_at)->setTimezone($f->tz) : $now;
            $age = (int) $since->startOfDay()->diffInDays($now->startOfDay());
            $handed = in_array($o->status, ['delivered', 'closed'], true);
            $rows[] = [
                'ref' => $o->number, '_link' => route('orders.show', $o), 'customer' => $o->customer?->name, 'phone' => $o->customer?->phone,
                'status' => $handed ? 'Handed over — not fully paid' : OrderStatus::label($o->status),
                'total' => (float) $o->total, 'paid' => round($paid[$o->id] ?? 0, 2), 'balance' => $balance,
                'since' => $o->confirmed_at ? $since->toDateString() : null, 'age' => $age, 'bucket' => $this->bucket($age),
                'owner' => $o->owner?->name, '_tone' => $handed ? 'danger' : null, '_handed' => $handed, '_customer_id' => $o->customer_id,
            ];
        }

        // Stand-alone invoices (no order): age from the due date, else the issue date.
        if (! $f->branchId && ! $f->staffId) {
            $invoices = Invoice::where('status', 'issued')->whereNull('order_id')->when(! $f->allTime, fn ($q) => $q->whereBetween('issue_date', $f->dateRange()))->with('customer:id,public_id,first_name,last_name,phone')->get();
            $invPaid = PaymentAllocation::whereIn('invoice_id', $invoices->pluck('id'))->selectRaw('invoice_id, sum(amount) amt')->groupBy('invoice_id')->pluck('amt', 'invoice_id');
            foreach ($invoices as $i) {
                $balance = round((float) $i->total - (float) ($invPaid[$i->id] ?? 0), 2);
                if ($balance <= 0.004) {
                    continue;
                }
                $since = CarbonImmutable::parse(($i->due_date ?? $i->issue_date ?? $i->created_at)->toDateString(), $f->tz);
                $age = max(0, (int) $since->diffInDays($now->startOfDay(), false));
                $rows[] = [
                    'ref' => $i->number, '_link' => route('invoices.show', $i), 'customer' => $i->customer?->name, 'phone' => $i->customer?->phone,
                    'status' => 'Invoice' . ($i->due_date && $i->due_date->lt($now->startOfDay()) ? ' — overdue' : ''),
                    'total' => (float) $i->total, 'paid' => round((float) ($invPaid[$i->id] ?? 0), 2), 'balance' => $balance,
                    'since' => $since->toDateString(), 'age' => $age, 'bucket' => $this->bucket($age), 'owner' => null,
                    '_tone' => null, '_handed' => false, '_customer_id' => $i->customer_id,
                ];
            }
        }
        usort($rows, fn ($a, $b) => [$b['age'], $b['balance']] <=> [$a['age'], $a['balance']]);
        $all = collect($rows);

        $credit = Payment::where('status', 'confirmed')->when($f->branchId, fn ($q) => $q->where('branch_id', $f->branchId))
            ->withSum('allocations as applied', 'amount')->withSum(['refunds as refunded' => fn ($q) => $q->where('status', 'paid')], 'amount')
            ->get(['id', 'amount'])
            ->sum(fn ($p) => max(0, (float) $p->amount - (float) $p->applied - (float) $p->refunded));
        $owed = round($all->sum('balance'), 2);
        $old = round($all->where('age', '>', 60)->sum('balance'), 2);
        $handed = $all->where('_handed', true);

        $r = new ReportResult();
        $r->card('Total owed', $owed, 'money', $all->count() . ' order(s) / invoice(s)', 'amber', 'ri-hand-coin-line')
          ->card('Handed over, not fully paid', round($handed->sum('balance'), 2), 'money', $handed->count() . ' order(s)', 'rose', 'ri-alert-line')
          ->card('Owed over 60 days', $old, 'money', $owed > 0 ? round(100 * $old / $owed) . '% of what is owed' : null, 'rose', 'ri-time-line')
          ->card('Customer credit held', round($credit, 2), 'money', 'Paid in but not yet used', 'teal', 'ri-wallet-3-line');

        $aging = [];
        foreach (self::BUCKETS as $b) {
            $g = $all->where('bucket', $b);
            $aging[] = ['bucket' => $b, 'count' => $g->count(), 'balance' => round($g->sum('balance'), 2)];
        }
        $r->chart('bar', self::BUCKETS, [['name' => 'Owed', 'data' => array_column($aging, 'balance')]]);
        $r->table('aging', 'How old the balances are', [
            ['key' => 'bucket', 'label' => 'Age (since confirmed / due)'], ['key' => 'count', 'label' => 'Count', 'format' => 'int'], ['key' => 'balance', 'label' => 'Owed', 'format' => 'money'],
        ], $aging, true);

        $byCustomer = $all->groupBy('_customer_id')->map(fn ($g) => [
            'customer' => $g->first()['customer'], 'phone' => $g->first()['phone'], 'items' => $g->count(),
            'oldest' => $g->max('age'), 'balance' => round($g->sum('balance'), 2),
        ])->sortByDesc('balance')->take(25)->values()->all();
        $r->table('customers', 'Who owes the most', [
            ['key' => 'customer', 'label' => 'Customer'], ['key' => 'phone', 'label' => 'Phone'], ['key' => 'items', 'label' => 'Orders / invoices', 'format' => 'int'],
            ['key' => 'oldest', 'label' => 'Oldest', 'format' => 'days'], ['key' => 'balance', 'label' => 'Owed', 'format' => 'money'],
        ], $byCustomer, null, null, 'Nobody owes the house anything.');

        $r->table('detail', 'Every balance', [
            ['key' => 'ref', 'label' => 'Order / invoice'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'status', 'label' => 'Stage'],
            ['key' => 'since', 'label' => 'Since', 'format' => 'date'], ['key' => 'age', 'label' => 'Age', 'format' => 'days'],
            ['key' => 'total', 'label' => 'Total', 'format' => 'money'], ['key' => 'paid', 'label' => 'Paid', 'format' => 'money'],
            ['key' => 'balance', 'label' => 'Owed', 'format' => 'money'], ['key' => 'owner', 'label' => 'Order owner'],
        ], $rows, true, null, 'Nobody owes the house anything.');

        $r->note('Balances are as of now. Pick dates to see only orders confirmed (and stand-alone invoices issued) in that period. Age counts from the day an order was confirmed, or an invoice\'s due date.')
          ->note('Draft and cancelled orders are left out. Deposits still to be paid on confirmed orders are included.');

        return $r;
    }

    protected function bucket(int $age): string
    {
        return $age <= 30 ? self::BUCKETS[0] : ($age <= 60 ? self::BUCKETS[1] : ($age <= 90 ? self::BUCKETS[2] : self::BUCKETS[3]));
    }
}
