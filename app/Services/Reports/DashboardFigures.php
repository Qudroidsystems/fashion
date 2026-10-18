<?php

namespace App\Services\Reports;

use App\Models\Branch;
use App\Models\Fitting;
use App\Models\FollowUpTask;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\ProductionTask;
use App\Models\StockBalance;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Reports\Definitions\ReceivablesReport;
use App\Support\Orders\OrderStatus;
use App\Support\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;

/**
 * The house dashboard (SRS FR-REP-001): sales, collections, outstanding
 * balances, order pipeline, due/overdue orders, low stock, production workload
 * and follow-ups. Each block is worked out only for people allowed to see it,
 * with the same branch limit as the reports, and money uses the report
 * definitions (FinanceFacts, ReceivablesReport) so the numbers always match.
 */
class DashboardFigures
{
    protected CarbonImmutable $now;
    protected ?int $branch;
    protected string $tz;

    public function __construct(protected User $user)
    {
        $this->tz = CurrentTenant::get()?->timezone ?: config('app.timezone');
        $this->now = CarbonImmutable::now($this->tz);
        $this->branch = ReportFilters::homeBranch($user);
    }

    public function all(): array
    {
        $u = $this->user;
        $tenant = CurrentTenant::get();
        $ent = app(Entitlements::class);

        return array_filter([
            'money'      => ($u->can('View financial reports') || $u->can('View payment')) ? $this->money() : null,
            'orders'     => $u->can('View order') ? $this->orders() : null,
            'production' => $u->can('View production') ? $this->production() : null,
            'stock'      => $u->can('View inventory') && $ent->has($tenant, 'inventory') ? $this->stock() : null,
            'follow_ups' => $u->can('View customer') ? $this->followUps() : null,
            'branch'     => $this->branch ? Branch::whereKey($this->branch)->value('name') : null,
        ], fn ($v) => $v !== null);
    }

    protected function filters(string $period): ReportFilters
    {
        return ReportFilters::make(['period' => $period], $this->user);
    }

    protected function money(): array
    {
        $today = new FinanceFacts($this->filters('today'));
        $month = new FinanceFacts($this->filters('this_month'));
        $last14 = ReportFilters::make(['period' => 'custom', 'from' => $this->now->subDays(13)->toDateString(), 'to' => $this->now->toDateString()], $this->user);
        $trend = new FinanceFacts($last14);
        $days = $last14->buckets();
        $series = array_fill_keys(array_keys($days), 0.0);
        foreach ($trend->moneyEvents() as $m) {
            $k = $last14->bucket($m['at']);
            isset($series[$k]) && $series[$k] += $m['amount'];
        }

        $out = [
            'collected_today' => $today->collected(),
            'collected_month' => $month->collected(),
            'trend'           => ['labels' => array_values($days), 'data' => array_map(fn ($v) => round($v, 2), array_values($series))],
        ];
        if ($this->user->can('View financial reports')) {
            $out['earned_month'] = $month->recognised();
            $out['deposits'] = $month->depositsHeld();
        }
        // Owed: the receivables report's own figures (its first two cards).
        $rec = app(ReceivablesReport::class)->run(ReportFilters::make(['period' => 'all'], $this->user), $this->user);
        $out['owed'] = $rec->cards[0]['value'];
        $out['owed_hint'] = $rec->cards[0]['hint'];
        $out['handed_unpaid'] = $rec->cards[1]['value'];

        return $out;
    }

    protected function orders(): array
    {
        $today = $this->now->toDateString();
        $base = Order::query()->when($this->branch, fn ($q) => $q->where('branch_id', $this->branch));
        $open = (clone $base)->whereIn('status', OrderStatus::OPEN);
        $pipeline = (clone $open)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');

        $due = (clone $open)->whereIn('status', ['confirmed', 'in_production', 'fitting', 'ready'])->whereNotNull('promised_date')
            ->whereDate('promised_date', '<=', $this->now->addDays(7)->toDateString())
            ->with('customer:id,public_id,first_name,last_name')
            ->orderBy('promised_date')->orderByRaw("case priority when 'rush' then 0 else 1 end")->limit(8)
            ->get(['id', 'public_id', 'number', 'title', 'customer_id', 'status', 'promised_date', 'priority', 'risk_level']);

        return [
            'open'      => (int) $pipeline->sum(),
            'pipeline'  => collect(OrderStatus::OPEN)->mapWithKeys(fn ($s) => [$s => (int) ($pipeline[$s] ?? 0)])->all(),
            'drafts'    => (clone $base)->where('status', 'draft')->count(),
            'due_week'  => (clone $open)->whereNotNull('promised_date')->whereBetween('promised_date', [$today, $this->now->addDays(7)->toDateString() . ' 23:59:59'])->count(),
            'overdue'   => (clone $open)->where('status', '!=', 'ready')->whereNotNull('promised_date')->whereDate('promised_date', '<', $today)->count(),
            'ready'     => (int) ($pipeline['ready'] ?? 0),
            'at_risk'   => (clone $open)->where('risk_level', 'high')->count(),
            'due'       => $due,
            'today'     => $today,
        ];
    }

    protected function production(): array
    {
        $today = $this->now->toDateString();
        $base = ProductionTask::open()->whereHas('order', fn ($o) => $o->whereNotIn('status', ['cancelled', 'draft'])
            ->when($this->branch, fn ($q) => $q->where('branch_id', $this->branch)));
        $people = (clone $base)->selectRaw('assigned_to, count(*) n, sum(case when due_date < ? then 1 else 0 end) late', [$today])
            ->groupBy('assigned_to')->get();
        $names = User::whereIn('id', $people->pluck('assigned_to')->filter())->pluck('name', 'id');

        return [
            'open'     => (clone $base)->count(),
            'mine'     => (clone $base)->where('assigned_to', $this->user->id)->count(),
            'overdue'  => (clone $base)->whereNotNull('due_date')->whereDate('due_date', '<', $today)->count(),
            'fittings' => Fitting::where('status', 'scheduled')
                ->whereBetween('scheduled_at', [$this->now->startOfDay()->utc(), $this->now->endOfDay()->utc()])->count(),
            'people'   => $people->map(fn ($p) => ['name' => $p->assigned_to ? ($names[$p->assigned_to] ?? 'Former staff') : 'Not assigned',
                'open' => (int) $p->n, 'late' => (int) $p->late, 'id' => $p->assigned_to])
                ->sortByDesc('open')->take(6)->values()->all(),
        ];
    }

    protected function stock(): array
    {
        $items = InventoryItem::active()->where('type', '!=', 'customer_material')->where('reorder_level', '>', 0)
            ->get(['id', 'public_id', 'name', 'unit', 'decimals', 'reorder_level']);
        $branches = $this->branch ? [$this->branch] : Branch::active()->pluck('id')->all();
        $names = Branch::pluck('name', 'id');
        $bal = StockBalance::whereIn('item_id', $items->pluck('id'))->whereIn('branch_id', $branches)
            ->selectRaw('item_id, branch_id, sum(on_hand) on_hand, sum(reserved) reserved')->groupBy('item_id', 'branch_id')->get()
            ->keyBy(fn ($b) => $b->item_id . ':' . $b->branch_id);
        $low = [];
        foreach ($items as $i) {
            foreach ($branches as $bid) {
                $b = $bal->get($i->id . ':' . $bid);
                $available = round($b ? (float) $b->on_hand - (float) $b->reserved : 0, 3);
                if ($available < $i->reorder_level) {
                    $low[] = ['item' => $i, 'branch' => count($branches) > 1 ? ($names[$bid] ?? null) : null, 'available' => $available, 'out' => $available <= 0];
                }
            }
        }
        usort($low, fn ($a, $b) => $a['available'] <=> $b['available']);

        return ['low' => count($low), 'out' => count(array_filter($low, fn ($l) => $l['out'])), 'items' => array_slice($low, 0, 6)];
    }

    protected function followUps(): array
    {
        return [
            'mine'    => FollowUpTask::open()->where('assigned_to', $this->user->id)
                ->where(fn ($q) => $q->whereNull('due_at')->orWhere('due_at', '<=', $this->now->endOfDay()->utc()))->count(),
            'overdue' => FollowUpTask::overdue()->count(),
            'open'    => FollowUpTask::open()->count(),
        ];
    }
}
