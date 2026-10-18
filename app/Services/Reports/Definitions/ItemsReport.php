<?php

namespace App\Services\Reports\Definitions;

use App\Models\CatalogueCategory;
use App\Models\OrderLine;
use App\Models\SaleLine;
use App\Models\User;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;

/** What sells: styles ordered and ready-made items sold. */
class ItemsReport extends Report
{
    public function key(): string
    {
        return 'items';
    }

    public function title(): string
    {
        return 'Best sellers';
    }

    public function description(): string
    {
        return 'Which styles are ordered most and which ready-made items sell, with their margin.';
    }

    public function group(): string
    {
        return 'Sales & orders';
    }

    public function icon(): string
    {
        return 'ri-t-shirt-air-line';
    }

    public function permissions(): array
    {
        return ['View order', 'View financial reports'];
    }

    public function features(): array
    {
        return ['reports_full'];
    }

    public function filters(): array
    {
        return ['range', 'branch', 'category'];
    }

    public function categoryOptions(): array
    {
        return CatalogueCategory::orderBy('name')->pluck('name', 'id')->all();
    }

    public function run(ReportFilters $f, User $user): ReportResult
    {
        $cost = $user->can('View costing') || $user->can('View financial reports');
        $cat = $f->category ? (int) $f->category : null;

        // Made-to-measure: garments on orders confirmed in the period.
        $lines = OrderLine::where('status', '!=', 'cancelled')->where('kind', 'bespoke')
            ->whereHas('order', fn ($o) => $o->whereNotIn('status', ['draft', 'cancelled'])->whereBetween('confirmed_at', [$f->fromUtc(), $f->toUtc()])
                ->when($f->branchId, fn ($q) => $q->where('branch_id', $f->branchId)))
            ->when($cat, fn ($q) => $q->whereHas('style', fn ($s) => $s->where('category_id', $cat)))
            ->with('style:id,public_id,name,category_id', 'style.category:id,name')->get(['id', 'style_id', 'description', 'quantity', 'line_total']);
        $styles = $lines->groupBy(fn ($l) => $l->style_id ?: 'x:' . $l->description)->map(fn ($g) => [
            'name' => $g->first()->style?->name ?? $g->first()->description, '_link' => $g->first()->style ? route('styles.show', $g->first()->style) : null,
            'category' => $g->first()->style?->category?->name ?? '—', 'garments' => (float) $g->sum('quantity'), 'value' => round($g->sum('line_total'), 2),
            'average' => $g->sum('quantity') > 0 ? round($g->sum('line_total') / $g->sum('quantity'), 2) : null,
        ])->sortByDesc('value')->values();

        // Ready-made: till sales (net of returns) and ready-made lines on orders.
        $sold = SaleLine::where('kind', 'product')
            ->whereHas('sale', fn ($s) => $s->where('status', 'completed')->whereBetween('created_at', [$f->fromUtc(), $f->toUtc()])->when($f->branchId, fn ($q) => $q->where('branch_id', $f->branchId)))
            ->when($cat, fn ($q) => $q->whereHas('variant.product', fn ($p) => $p->where('category_id', $cat)))
            ->with('variant:id,product_id,size,colour', 'variant.product:id,public_id,name,category_id', 'variant.product.category:id,name')
            ->get(['id', 'product_variant_id', 'description', 'quantity', 'returned_quantity', 'line_total', 'unit_cost']);
        $products = $sold->groupBy(fn ($l) => $l->variant?->product_id ?: 'x:' . $l->description)->map(function ($g) use ($cost) {
            $qty = $g->sum(fn ($l) => $l->quantity - $l->returned_quantity);
            $value = $g->sum(fn ($l) => $l->quantity > 0 ? $l->line_total * ($l->quantity - $l->returned_quantity) / $l->quantity : 0);
            $c = $g->sum(fn ($l) => ($l->quantity - $l->returned_quantity) * (float) $l->unit_cost);
            $p = $g->first()->variant?->product;

            return ['name' => $p?->name ?? $g->first()->description, '_link' => $p ? route('products.edit', $p) : null, 'category' => $p?->category?->name ?? '—',
                'sold' => round($qty, 2), 'value' => round($value, 2), 'cost' => $cost ? round($c, 2) : null,
                'margin' => $cost && $value > 0 ? round(100 * ($value - $c) / $value, 1) : null];
        })->filter(fn ($r) => $r['sold'] > 0)->sortByDesc('value')->values();

        $r = new ReportResult();
        $r->card('Garments ordered', (float) $lines->sum('quantity'), 'int', $styles->count() . ' style(s)', 'violet', 'ri-scissors-cut-line')
          ->card('Made-to-measure value', round($lines->sum('line_total'), 2), 'money', null, 'blue', 'ri-file-list-3-line')
          ->card('Ready-made sold', $products->sum('sold'), 'int', $products->count() . ' product(s)', 'teal', 'ri-shopping-bag-3-line')
          ->card('Ready-made sales', round($products->sum('value'), 2), 'money', $cost && $products->sum('value') > 0 ? 'Margin ' . round(100 * ($products->sum('value') - $products->sum('cost')) / $products->sum('value'), 1) . '%' : null, 'amber', 'ri-store-2-line');

        $top = $styles->take(10);
        if ($top->isNotEmpty()) {
            $r->chart('hbar', $top->pluck('name')->all(), [['name' => 'Value ordered', 'data' => $top->pluck('value')->all()]], 'money', 'Top styles by value');
        }
        $r->table('styles', 'Styles ordered (made to measure)', [
            ['key' => 'name', 'label' => 'Style'], ['key' => 'category', 'label' => 'Category'], ['key' => 'garments', 'label' => 'Garments', 'format' => 'qty'],
            ['key' => 'value', 'label' => 'Value', 'format' => 'money'], ['key' => 'average', 'label' => 'Average price', 'format' => 'money'],
        ], $styles->all(), true, null, 'No made-to-measure orders were confirmed in this period.');
        $cols = [['key' => 'name', 'label' => 'Product'], ['key' => 'category', 'label' => 'Category'], ['key' => 'sold', 'label' => 'Sold', 'format' => 'qty'],
            ['key' => 'value', 'label' => 'Sales', 'format' => 'money']];
        if ($cost) {
            $cols[] = ['key' => 'cost', 'label' => 'Cost', 'format' => 'money'];
            $cols[] = ['key' => 'margin', 'label' => 'Margin', 'format' => 'percent'];
        }
        $r->table('products', 'Ready-made items sold at the till', $cols, $products->all(), true, null, 'No ready-made items were sold at the till in this period.');
        $r->note('Styles count when the order is confirmed; ready-made items when sold, less anything returned.');

        return $r;
    }
}
