<?php

namespace App\Services\Reports;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\MaterialIssue;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ProjectExpense;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
use App\Models\StockMovement;
use Illuminate\Support\Collection;

/**
 * The money figures, each with one definition (SRS FR-REP-005), shared by the
 * reports, the dashboard and the weekly summary:
 *
 *  - Money in:      payments received (counter, till, online) + ready-made items sold at the till.
 *                   Store credit given for returns is not money in.
 *  - Money out:     refunds paid, till refunds (not store credit), payments reversed (bounced).
 *  - Collected:     money in − money out.
 *  - Invoiced:      invoices issued in the period (voided ones left out).
 *  - Recognised sales: orders handed over in the period (their full value) + till sales net of
 *                   returns + stand-alone invoices issued. Deposits on unfinished work are NOT sales yet.
 *  - Deposits held: money paid toward orders not yet handed over or cancelled, as of the period end.
 *  - Cost of sales: cost of ready-made items sold (net of restocked returns) + materials issued to
 *                   orders handed over in the period. Labour is a cost only when recorded as an expense.
 *  - Expenses:      house expenses + project expenses, by the date spent.
 *  - Stock written off: wastage and stock-count / adjustment differences, at cost.
 */
class FinanceFacts
{
    protected array $memo = [];

    public function __construct(public ReportFilters $f)
    {
    }

    protected function memo(string $key, callable $fn)
    {
        return $this->memo[$key] ??= $fn();
    }

    /* ------------------------------ money in/out ------------------------------ */

    /** Rows: at (UTC), method, amount (+ in / − out), kind. */
    public function moneyEvents(): Collection
    {
        return $this->memo('money', function () {
            [$from, $to, $branch] = [$this->f->fromUtc(), $this->f->toUtc(), $this->f->branchId];
            $rows = collect();

            // Payments received; store credit from till returns (channel pos, no sale) isn't money.
            Payment::whereBetween('received_at', [$from, $to])
                ->when($branch, fn ($q) => $q->where('branch_id', $branch))
                ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', '!=', 'pos')->orWhereNotNull('sale_id'))
                ->get(['received_at', 'method', 'amount', 'channel'])
                ->each(fn ($p) => $rows->push(['at' => $p->received_at, 'method' => $p->method, 'amount' => (float) $p->amount,
                    'kind' => $p->channel === 'online' ? 'online' : ($p->channel === 'pos' ? 'till_orders' : 'counter')]));

            // Ready-made items at the till (order payments at the till are already payments above).
            Sale::where('status', 'completed')->whereBetween('created_at', [$from, $to])
                ->when($branch, fn ($q) => $q->where('branch_id', $branch))
                ->with(['lines' => fn ($q) => $q->where('kind', 'product')->select('id', 'sale_id', 'line_total'), 'tenders:id,sale_id,method,amount'])
                ->get(['id', 'created_at', 'total'])
                ->each(function ($s) use ($rows) {
                    $product = (float) $s->lines->sum('line_total');
                    if ($product <= 0 || $s->total <= 0) {
                        return;
                    }
                    $share = min(1, $product / (float) $s->total);
                    foreach ($s->tenders as $t) {
                        $rows->push(['at' => $s->created_at, 'method' => $t->method, 'amount' => round($t->amount * $share, 2), 'kind' => 'till_items']);
                    }
                });

            // Out: refunds paid, till refunds in money, bounced payments.
            Refund::where('status', 'paid')->whereBetween('approved_at', [$from, $to])
                ->when($branch, fn ($q) => $q->whereHas('payment', fn ($p) => $p->where('branch_id', $branch)))
                ->get(['approved_at', 'method', 'amount'])
                ->each(fn ($r) => $rows->push(['at' => $r->approved_at, 'method' => $r->method, 'amount' => -(float) $r->amount, 'kind' => 'refund']));
            SaleReturn::where('refund_method', '!=', 'store_credit')->whereBetween('created_at', [$from, $to])
                ->when($branch, fn ($q) => $q->whereHas('sale', fn ($s) => $s->where('branch_id', $branch)))
                ->get(['created_at', 'refund_method', 'amount'])
                ->each(fn ($r) => $rows->push(['at' => $r->created_at, 'method' => $r->refund_method, 'amount' => -(float) $r->amount, 'kind' => 'till_refund']));
            Payment::where('status', 'reversed')->whereBetween('reversed_at', [$from, $to])
                ->when($branch, fn ($q) => $q->where('branch_id', $branch))
                ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', '!=', 'pos')->orWhereNotNull('sale_id'))
                ->get(['reversed_at', 'method', 'amount'])
                ->each(fn ($p) => $rows->push(['at' => $p->reversed_at, 'method' => $p->method, 'amount' => -(float) $p->amount, 'kind' => 'reversal']));

            return $rows;
        });
    }

    public function moneyIn(): float
    {
        return round($this->moneyEvents()->where('amount', '>', 0)->sum('amount'), 2);
    }

    public function moneyOut(): float
    {
        return round(-$this->moneyEvents()->where('amount', '<', 0)->sum('amount'), 2);
    }

    public function collected(): float
    {
        return round($this->moneyEvents()->sum('amount'), 2);
    }

    /* -------------------------------- invoiced -------------------------------- */

    public function invoices(): Collection
    {
        return $this->memo('invoices', fn () => Invoice::where('status', 'issued')
            ->whereBetween('issue_date', $this->f->dateRange())
            ->when($this->f->branchId, fn ($q) => $q->whereHas('order', fn ($o) => $o->where('branch_id', $this->f->branchId)))
            ->get(['id', 'order_id', 'kind', 'issue_date', 'total']));
    }

    public function invoiced(): float
    {
        return round((float) $this->invoices()->sum('total'), 2);
    }

    /* ---------------------------- recognised sales ---------------------------- */

    /** Rows: at, amount, source (orders | till | invoices), ref. */
    public function salesEvents(): Collection
    {
        return $this->memo('sales', function () {
            [$from, $to, $branch] = [$this->f->fromUtc(), $this->f->toUtc(), $this->f->branchId];
            $rows = collect();
            $this->deliveredOrders()->each(fn ($o) => $rows->push(['at' => $o->delivered_at, 'amount' => (float) $o->total, 'source' => 'orders']));
            SaleLine::where('kind', 'product')
                ->whereHas('sale', fn ($s) => $s->where('status', 'completed')->whereBetween('created_at', [$from, $to])->when($branch, fn ($q) => $q->where('branch_id', $branch)))
                ->with('sale:id,created_at')->get(['sale_id', 'line_total'])
                ->each(fn ($l) => $rows->push(['at' => $l->sale->created_at, 'amount' => (float) $l->line_total, 'source' => 'till']));
            SaleReturn::whereBetween('created_at', [$from, $to])
                ->when($branch, fn ($q) => $q->whereHas('sale', fn ($s) => $s->where('branch_id', $branch)))
                ->get(['created_at', 'amount'])
                ->each(fn ($r) => $rows->push(['at' => $r->created_at, 'amount' => -(float) $r->amount, 'source' => 'till']));
            if (! $branch) {
                $this->invoices()->whereNull('order_id')
                    ->each(fn ($i) => $rows->push(['at' => $i->issue_date->toDateString(), 'amount' => (float) $i->total, 'source' => 'invoices']));
            }

            return $rows;
        });
    }

    public function deliveredOrders(): Collection
    {
        return $this->memo('delivered', fn () => Order::whereNotNull('delivered_at')->where('status', '!=', 'cancelled')
            ->whereBetween('delivered_at', [$this->f->fromUtc(), $this->f->toUtc()])
            ->when($this->f->branchId, fn ($q) => $q->where('branch_id', $this->f->branchId))
            ->get(['id', 'number', 'customer_id', 'total', 'delivered_at', 'promised_date', 'confirmed_at', 'project_id']));
    }

    public function recognised(): float
    {
        return round($this->salesEvents()->sum('amount'), 2);
    }

    /* ----------------------------- deposits held ------------------------------ */

    /** Money paid toward orders still open at the period end (not handed over, not cancelled). */
    public function depositsHeld(): float
    {
        return $this->memo('deposits', function () {
            $to = $this->f->toUtc();
            $ids = Order::whereNotNull('confirmed_at')->where('confirmed_at', '<=', $to)
                ->where(fn ($q) => $q->whereNull('delivered_at')->orWhere('delivered_at', '>', $to))
                ->where(fn ($q) => $q->whereNull('cancelled_at')->orWhere('cancelled_at', '>', $to))
                ->when($this->f->branchId, fn ($q) => $q->where('branch_id', $this->f->branchId))
                ->pluck('id');
            if ($ids->isEmpty()) {
                return 0.0;
            }
            $sum = 0.0;
            foreach ($ids->chunk(500) as $chunk) {
                $sum += (float) PaymentAllocation::where('created_at', '<=', $to)
                    ->where(fn ($q) => $q->whereIn('order_id', $chunk)->orWhereIn('invoice_id', Invoice::whereIn('order_id', $chunk)->select('id')))
                    ->sum('amount');
            }

            return round(max(0, $sum), 2);
        });
    }

    /* ------------------------------ cost of sales ----------------------------- */

    public function tillCost(): float
    {
        return $this->memo('till_cost', function () {
            [$from, $to, $branch] = [$this->f->fromUtc(), $this->f->toUtc(), $this->f->branchId];
            $sold = (float) SaleLine::where('kind', 'product')
                ->whereHas('sale', fn ($s) => $s->where('status', 'completed')->whereBetween('created_at', [$from, $to])->when($branch, fn ($q) => $q->where('branch_id', $branch)))
                ->get(['quantity', 'unit_cost'])->sum(fn ($l) => $l->quantity * (float) $l->unit_cost);
            $back = (float) SaleReturnLine::whereHas('saleReturn', fn ($r) => $r->where('restock', true)->whereBetween('created_at', [$from, $to])
                    ->when($branch, fn ($q) => $q->whereHas('sale', fn ($s) => $s->where('branch_id', $branch))))
                ->with('saleLine:id,unit_cost')->get(['sale_line_id', 'quantity'])->sum(fn ($l) => $l->quantity * (float) ($l->saleLine?->unit_cost ?? 0));

            return round($sold - $back, 2);
        });
    }

    public function orderMaterialCost(): float
    {
        return $this->memo('order_cost', function () {
            $ids = $this->deliveredOrders()->pluck('id');

            return $ids->isEmpty() ? 0.0 : round((float) MaterialIssue::whereIn('order_id', $ids)->sum('total_cost'), 2);
        });
    }

    public function costOfSales(): float
    {
        return round($this->tillCost() + $this->orderMaterialCost(), 2);
    }

    /* -------------------------------- expenses -------------------------------- */

    /** category => amount (house expenses), plus 'Projects & weddings' for project costs. */
    public function expensesByCategory(): array
    {
        return $this->memo('expenses', function () {
            $out = Expense::counted()->whereBetween('spent_on', $this->f->dateRange())
                ->when($this->f->branchId, fn ($q) => $q->where('branch_id', $this->f->branchId))
                ->selectRaw('category, sum(amount) total')->groupBy('category')->pluck('total', 'category')
                ->map(fn ($v) => round((float) $v, 2))->sortDesc()->all();
            $project = $this->projectExpenses();
            if ($project > 0) {
                $out['Projects & weddings'] = $project;
            }

            return $out;
        });
    }

    public function projectExpenses(): float
    {
        return $this->memo('project_expenses', fn () => round((float) ProjectExpense::whereBetween('spent_on', $this->f->dateRange())
            ->when($this->f->branchId, fn ($q) => $q->whereHas('project', fn ($p) => $p->where('branch_id', $this->f->branchId)))
            ->sum('amount'), 2));
    }

    public function expenses(): float
    {
        return round(array_sum($this->expensesByCategory()), 2);
    }

    public function stockWrittenOff(): float
    {
        return $this->memo('written_off', fn () => round(-(float) StockMovement::whereIn('type', ['wastage', 'adjustment', 'count'])
            ->whereBetween('occurred_at', [$this->f->fromUtc(), $this->f->toUtc()])
            ->when($this->f->branchId, fn ($q) => $q->where('branch_id', $this->f->branchId))
            ->whereHas('item', fn ($i) => $i->where('type', '!=', 'customer_material'))
            ->get(['quantity', 'unit_cost'])->sum(fn ($m) => $m->quantity * (float) $m->unit_cost), 2));
    }

    public function grossProfit(): float
    {
        return round($this->recognised() - $this->costOfSales(), 2);
    }

    public function netProfit(): float
    {
        return round($this->grossProfit() - $this->expenses() - $this->stockWrittenOff(), 2);
    }
}
