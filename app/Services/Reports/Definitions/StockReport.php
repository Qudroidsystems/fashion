<?php

namespace App\Services\Reports\Definitions;

use App\Models\InventoryItem;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;

/** Stock on hand and its value (SRS GET /reports/stock-valuation, FR-INV-010), plus material used in the period. */
class StockReport extends Report
{
    public function key(): string
    {
        return 'stock';
    }

    public function title(): string
    {
        return 'Stock value & material use';
    }

    public function description(): string
    {
        return 'What is on the shelves and what it is worth now, what is running low, and material used or lost in the period.';
    }

    public function group(): string
    {
        return 'Stock';
    }

    public function icon(): string
    {
        return 'ri-archive-line';
    }

    public function permissions(): array
    {
        return ['View inventory'];
    }

    public function features(): array
    {
        return ['inventory'];
    }

    public function filters(): array
    {
        return ['range', 'branch', 'category'];
    }

    public function categoryOptions(): array
    {
        return collect(InventoryItem::TYPES)->except('customer_material')->all();
    }

    public function run(ReportFilters $f, User $user): ReportResult
    {
        $cost = $user->can('View costing') || $user->can('View financial reports');
        $balances = StockBalance::where('on_hand', '!=', 0)
            ->when($f->branchId, fn ($q) => $q->where('branch_id', $f->branchId))
            ->whereHas('item', fn ($i) => $i->when($f->category, fn ($w) => $w->where('type', $f->category)))
            ->with('item:id,public_id,code,name,type,unit,decimals,average_cost,reorder_level,customer_id,is_active', 'item.customer:id,first_name,last_name', 'branch:id,name')
            ->get(['id', 'item_id', 'branch_id', 'on_hand', 'reserved']);
        [$customer, $own] = $balances->partition(fn ($b) => $b->item->type === 'customer_material');

        $items = $own->groupBy('item_id')->map(function ($g) {
            $i = $g->first()->item;
            $qty = (float) $g->sum('on_hand');

            return ['code' => $i->code, 'name' => $i->name, '_link' => route('inventory.items.show', $i), 'type' => InventoryItem::TYPES[$i->type] ?? $i->type,
                'on_hand' => round($qty, 3), 'unit' => $i->unit, 'reserved' => round((float) $g->sum('reserved'), 3),
                'avg_cost' => (float) $i->average_cost, 'value' => round($qty * (float) $i->average_cost, 2), '_type' => $i->type];
        })->sortByDesc('value')->values();

        // Low / out per location (the reorder level applies at each location, as the alerts do).
        $levels = InventoryItem::active()->where('type', '!=', 'customer_material')->whereNotNull('reorder_level')->where('reorder_level', '>', 0)
            ->when($f->category, fn ($q) => $q->where('type', $f->category))->get(['id', 'public_id', 'code', 'name', 'unit', 'reorder_level', 'reorder_quantity']);
        $branchIds = $f->branchId ? [$f->branchId] : \App\Models\Branch::active()->pluck('id')->all();
        $branchNames = \App\Models\Branch::pluck('name', 'id');
        $byKey = StockBalance::whereIn('item_id', $levels->pluck('id'))->whereIn('branch_id', $branchIds)
            ->selectRaw('item_id, branch_id, sum(on_hand) on_hand, sum(reserved) reserved')->groupBy('item_id', 'branch_id')->get()
            ->keyBy(fn ($b) => $b->item_id . ':' . $b->branch_id);
        $low = [];
        foreach ($levels as $i) {
            foreach ($branchIds as $bid) {
                $b = $byKey->get($i->id . ':' . $bid);
                $available = round(($b ? (float) $b->on_hand - (float) $b->reserved : 0), 3);
                if ($available < $i->reorder_level) {
                    $low[] = ['name' => $i->name, '_link' => route('inventory.items.show', $i), 'branch' => $branchNames[$bid] ?? '—', 'available' => $available,
                        'level' => (float) $i->reorder_level, 'reorder' => $i->reorder_quantity, 'unit' => $i->unit, '_tone' => $available <= 0 ? 'danger' : 'warning'];
                }
            }
        }
        usort($low, fn ($a, $b) => $a['available'] <=> $b['available']);

        // Movement in the period, at cost.
        $moves = StockMovement::whereBetween('occurred_at', [$f->fromUtc(), $f->toUtc()])
            ->when($f->branchId, fn ($q) => $q->where('branch_id', $f->branchId))
            ->whereHas('item', fn ($i) => $i->where('type', '!=', 'customer_material')->when($f->category, fn ($w) => $w->where('type', $f->category)))
            ->with('item:id,public_id,name,unit')->get(['item_id', 'type', 'quantity', 'unit_cost']);
        $kinds = ['receipt' => 'received', 'issue' => 'issued', 'production_return' => 'returned', 'sale' => 'sold', 'sale_return' => 'sold',
            'wastage' => 'lost', 'adjustment' => 'lost', 'count' => 'lost'];
        $usage = $moves->groupBy('item_id')->map(function ($g) use ($kinds) {
            $sum = fn (string $k, int $sign) => round($sign * $g->filter(fn ($m) => ($kinds[$m->type] ?? null) === $k)->sum('quantity'), 3);
            $val = fn (string $k, int $sign) => round($sign * $g->filter(fn ($m) => ($kinds[$m->type] ?? null) === $k)->sum(fn ($m) => $m->quantity * (float) $m->unit_cost), 2);
            $i = $g->first()->item;

            return ['name' => $i->name, '_link' => route('inventory.items.show', $i), 'unit' => $i->unit, 'received' => $sum('received', 1),
                'used' => round($sum('issued', -1) - $sum('returned', 1), 3), 'sold' => $sum('sold', -1), 'lost' => $sum('lost', -1),
                'used_value' => round($val('issued', -1) - $val('returned', 1), 2), 'lost_value' => $val('lost', -1)];
        })->filter(fn ($r) => $r['received'] || $r['used'] || $r['sold'] || $r['lost'])->sortByDesc('used_value')->values();

        $r = new ReportResult();
        $value = round($items->sum('value'), 2);
        $r->card('Stock value now', $cost ? $value : null, 'money', $items->count() . ' item(s) in stock', 'teal', 'ri-archive-line')
          ->card('Running low', collect($low)->where('_tone', 'warning')->count(), 'int', 'Below reorder level', 'amber', 'ri-alert-line')
          ->card('Out of stock', collect($low)->where('_tone', 'danger')->count(), 'int', 'Nothing available to use or sell', 'rose', 'ri-close-circle-line')
          ->card('Material used', $cost ? round($usage->sum('used_value'), 2) : round($usage->sum('used'), 2), $cost ? 'money' : 'qty', $f->label(), 'violet', 'ri-scissors-cut-line');

        $byType = $items->groupBy('_type')->map(fn ($g, $t) => ['type' => InventoryItem::TYPES[$t] ?? $t, 'items' => $g->count(), 'value' => round($g->sum('value'), 2)])->values()->all();
        if ($cost && $byType) {
            $r->chart('donut', array_column($byType, 'type'), [['name' => 'Value', 'data' => array_column($byType, 'value')]]);
            $r->table('types', 'Value by kind', [['key' => 'type', 'label' => 'Kind'], ['key' => 'items', 'label' => 'Items', 'format' => 'int'],
                ['key' => 'value', 'label' => 'Value', 'format' => 'money']], $byType, true);
        }
        $r->table('low', 'Running low', [
            ['key' => 'name', 'label' => 'Item'], ['key' => 'branch', 'label' => 'Location'], ['key' => 'available', 'label' => 'Available', 'format' => 'qty'],
            ['key' => 'level', 'label' => 'Reorder level', 'format' => 'qty'], ['key' => 'reorder', 'label' => 'Usually order', 'format' => 'qty'], ['key' => 'unit', 'label' => 'Unit'],
        ], $low, null, null, 'Nothing is below its reorder level.');

        $cols = [['key' => 'code', 'label' => 'Code'], ['key' => 'name', 'label' => 'Item'], ['key' => 'type', 'label' => 'Kind'],
            ['key' => 'on_hand', 'label' => 'On hand', 'format' => 'qty'], ['key' => 'reserved', 'label' => 'Reserved', 'format' => 'qty'], ['key' => 'unit', 'label' => 'Unit']];
        if ($cost) {
            $cols[] = ['key' => 'avg_cost', 'label' => 'Average cost', 'format' => 'money'];
            $cols[] = ['key' => 'value', 'label' => 'Value', 'format' => 'money'];
        }
        $r->table('valuation', 'Stock on hand', $cols, $items->map(fn ($i) => $cost ? $i : array_diff_key($i, ['avg_cost' => 1, 'value' => 1]))->all(), $cost ? ['code' => 'Total', 'value' => $value] : null, null, 'Nothing in stock.');

        $ucols = [['key' => 'name', 'label' => 'Item'], ['key' => 'unit', 'label' => 'Unit'], ['key' => 'received', 'label' => 'Received', 'format' => 'qty'],
            ['key' => 'used', 'label' => 'Used in production', 'format' => 'qty'], ['key' => 'sold', 'label' => 'Sold', 'format' => 'qty'], ['key' => 'lost', 'label' => 'Lost / written off', 'format' => 'qty']];
        if ($cost) {
            $ucols[] = ['key' => 'used_value', 'label' => 'Used, at cost', 'format' => 'money'];
            $ucols[] = ['key' => 'lost_value', 'label' => 'Lost, at cost', 'format' => 'money'];
        }
        $r->table('usage', 'Movement in ' . $f->label(), $ucols, $usage->all(), $cost ? ['name' => 'Total', 'used_value' => round($usage->sum('used_value'), 2), 'lost_value' => round($usage->sum('lost_value'), 2)] : null,
            null, 'No stock moved in this period.');

        if ($customer->isNotEmpty() && ! $f->category) {
            $r->table('customer', 'Customers’ own material held (not the house’s stock)', [
                ['key' => 'name', 'label' => 'Material'], ['key' => 'owner', 'label' => 'Belongs to'], ['key' => 'branch', 'label' => 'Location'],
                ['key' => 'on_hand', 'label' => 'Quantity', 'format' => 'qty'], ['key' => 'unit', 'label' => 'Unit'],
            ], $customer->map(fn ($b) => ['name' => $b->item->name, '_link' => route('inventory.items.show', $b->item), 'owner' => $b->item->customer?->name,
                'branch' => $b->branch?->name, 'on_hand' => (float) $b->on_hand, 'unit' => $b->item->unit])->values()->all());
        }
        $r->note('Stock is valued at weighted-average cost now; customers’ own material is never counted in the value (FR-INV-010).');

        return $r;
    }
}
