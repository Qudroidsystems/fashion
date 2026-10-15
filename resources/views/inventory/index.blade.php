@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; use App\Models\InventoryItem; $u = auth()->user(); @endphp

    <x-cb.hero title="Stock" icon="ri-archive-line" subtitle="Fabric, trims, consumables and ready-made items at every location. Every change is recorded in the stock ledger.">
        <x-slot:actions>
            @can('Manage inventory')
                <a href="{{ route('goods-receipts.create') }}" class="cb-hero-btn"><i class="ri-inbox-archive-line me-1"></i>Receive stock</a>
                <a href="{{ route('inventory.items.create') }}" class="cb-hero-btn"><i class="ri-add-line me-1"></i>New item</a>
            @endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('inventory.partials.nav')
    @include('inventory.partials.flash')

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><x-cb.stat label="Active items" :value="number_format($stats['items'])" icon="ri-archive-line" accent="teal" /></div>
        <div class="col-6 col-lg-3"><a href="{{ route('inventory.index', ['show' => 'low']) }}" class="text-decoration-none"><x-cb.stat label="Below reorder level" :value="$stats['low']" icon="ri-alarm-warning-line" :accent="$stats['low'] ? 'rose' : 'teal'" /></a></div>
        <div class="col-6 col-lg-3"><a href="{{ route('purchase-orders.index') }}" class="text-decoration-none"><x-cb.stat label="Awaiting approval" :value="$stats['approvals']" icon="ri-shield-check-line" accent="amber" :hint="$stats['incoming'] . ' order(s) on the way'" /></a></div>
        <div class="col-6 col-lg-3">
            @if($stats['value'] !== null)
                <x-cb.stat label="Stock value" :value="Money::format($stats['value'])" icon="ri-money-dollar-circle-line" accent="violet" hint="at average cost, excl. customer material" />
            @else
                <x-cb.stat label="Locations" :value="$branches->count()" icon="ri-store-2-line" accent="violet" />
            @endif
        </div>
    </div>

    <x-cb.card title="Items" icon="ri-list-check" :count="$items->total()" :flush="true">
        <x-slot:tools>
            @can('Adjust stock')
                <a href="{{ route('stock-adjustments.create', ['kind' => 'count']) }}" class="action-btn"><i class="ri-clipboard-line"></i>Count stock</a>
            @endcan
            @can('Issue material')
                <a href="{{ route('material-issues.create') }}" class="action-btn"><i class="ri-scissors-cut-line"></i>Issue material</a>
            @endcan
        </x-slot:tools>
        <form method="GET" class="cb-toolbar">
            <div class="cb-search"><i class="ri-search-line"></i>
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Name, code, colour or scan a barcode…">
            </div>
            <select name="branch" class="cb-select" onchange="this.form.submit()">
                <option value="">All locations</option>
                @foreach($branches as $b)<option value="{{ $b->id }}" @selected($branchId == $b->id)>{{ $b->name }}</option>@endforeach
            </select>
            <select name="type" class="cb-select" onchange="this.form.submit()">
                <option value="">All types</option>
                @foreach(InventoryItem::TYPES as $k => $l)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $l }}</option>@endforeach
            </select>
            @if($categories->isNotEmpty())
                <select name="category" class="cb-select" onchange="this.form.submit()">
                    <option value="">All categories</option>
                    @foreach($categories as $c)<option @selected(request('category') === $c)>{{ $c }}</option>@endforeach
                </select>
            @endif
            <select name="show" class="cb-select" onchange="this.form.submit()">
                @foreach(['all' => 'Any stock level', 'in_stock' => 'In stock', 'low' => 'Below reorder level', 'out' => 'Out of stock'] as $k => $l)<option value="{{ $k }}" @selected($show === $k)>{{ $l }}</option>@endforeach
            </select>
            <button class="action-btn btn-primary-cb ms-auto"><i class="ri-filter-3-line"></i>Apply</button>
        </form>

        @if($items->isEmpty())
            <div class="empty-state"><i class="ri-archive-line"></i><h6>No items here</h6>
                <p>Add the fabrics, trims and consumables you keep, then receive them or record opening stock.</p>
                @can('Manage inventory')<a href="{{ route('inventory.items.create') }}" class="action-btn btn-primary-cb mt-2"><i class="ri-add-line"></i>New item</a>@endcan
            </div>
        @else
        <div class="table-responsive">
            <table class="cb-table stack mb-0">
                <thead><tr><th>Item</th><th>Type</th><th class="text-end">On hand</th><th class="text-end">Reserved</th><th class="text-end">Free</th><th class="text-end">Reorder at</th>@can('View costing')<th class="text-end">Avg cost</th>@endcan</tr></thead>
                <tbody>
                @foreach($items as $i)
                    @php
                        $free = $i->on_hand_sum - $i->reserved_sum;
                        $low = $i->reorder_level !== null && $free < $i->reorder_level;
                    @endphp
                    <tr onclick="location.href='{{ route('inventory.items.show', $i) }}'" style="cursor:pointer">
                        <td data-label="Item">
                            <div class="d-flex align-items-center gap-2">
                                <span class="d-grid rounded-2 flex-shrink-0" style="width:34px;height:34px;background:#f3f5f9"><i class="{{ InventoryItem::TYPE_ICONS[$i->type] ?? 'ri-archive-line' }} m-auto text-muted"></i></span>
                                <div>
                                    <a href="{{ route('inventory.items.show', $i) }}" class="fw-semibold text-body">{{ $i->label }}</a>
                                    <div class="small text-muted">{{ $i->code }}@if($i->category) · {{ $i->category }}@endif @if($i->customer) · {{ $i->customer->name }}@endif</div>
                                </div>
                            </div>
                        </td>
                        <td data-label="Type"><span class="small">{{ $i->type_label }}</span></td>
                        <td data-label="On hand" class="text-end">{{ $i->qty($i->on_hand_sum) }}</td>
                        <td data-label="Reserved" class="text-end text-muted">{{ $i->reserved_sum > 0 ? $i->qty($i->reserved_sum) : '—' }}</td>
                        <td data-label="Free" class="text-end fw-semibold {{ $free <= 0 ? 'text-danger' : ($low ? 'text-warning' : '') }}">
                            {{ $i->qty($free) }}
                            @if($low)<i class="ri-alarm-warning-line ms-1" title="Below reorder level"></i>@endif
                        </td>
                        <td data-label="Reorder at" class="text-end text-muted">{{ $i->reorder_level !== null ? $i->qty($i->reorder_level) : '—' }}</td>
                        @can('View costing')<td data-label="Avg cost" class="text-end">{{ $i->isCustomerOwned() ? '—' : Money::format($i->average_cost) }}</td>@endcan
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </x-cb.card>
    <div class="mt-3">{{ $items->links() }}</div>

</div>
</div>
</div>
@endsection
