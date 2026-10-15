@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; $u = auth()->user(); $a = $availability; @endphp

    <x-cb.hero :title="$item->label" icon="ri-archive-line"
               :subtitle="$item->code . ' · ' . $item->type_label . ($item->category ? ' · ' . $item->category : '')"
               :back="route('inventory.index')" back-label="Stock">
        <x-slot:pills>
            @unless($item->is_active)<span class="cb-meta-pill">Inactive</span>@endunless
            @if($item->track_lots)<span class="cb-meta-pill"><i class="ri-stack-line me-1"></i>Tracked by roll / lot</span>@endif
            @if($item->width)<span class="cb-meta-pill">Width {{ $item->width }}</span>@endif
            @if($item->customer)<span class="cb-meta-pill"><i class="ri-user-line me-1"></i>Belongs to {{ $item->customer->name }}</span>@endif
            @if($item->barcode)<span class="cb-meta-pill font-monospace">{{ $item->barcode }}</span>@endif
        </x-slot:pills>
        <x-slot:actions>
            @if($u->can('Manage purchase order') && ! $item->isCustomerOwned())
                <a href="{{ route('purchase-orders.create', ['item' => $item->public_id]) }}" class="cb-hero-btn"><i class="ri-file-list-2-line me-1"></i>Order more</a>
            @endif
            @if($u->can('Manage inventory') && ! ($item->type === 'product' && $item->product_variant_id))
                <a href="{{ route('inventory.items.edit', $item) }}" class="cb-hero-btn"><i class="ri-pencil-line me-1"></i>Edit</a>
            @elseif($item->productVariant)
                <a href="{{ route('products.edit', $item->productVariant->product) }}" class="cb-hero-btn"><i class="ri-shopping-bag-3-line me-1"></i>Product</a>
            @endif
        </x-slot:actions>
    </x-cb.hero>

    @include('inventory.partials.nav')
    @include('inventory.partials.flash')

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg"><x-cb.stat label="On hand" :value="$item->qty($a['on_hand'])" icon="ri-archive-line" accent="teal" /></div>
        <div class="col-6 col-lg"><x-cb.stat label="Reserved" :value="$item->qty($a['reserved'])" icon="ri-lock-line" accent="amber" /></div>
        <div class="col-6 col-lg"><x-cb.stat label="Free to use" :value="$item->qty($a['available'])" icon="ri-checkbox-circle-line" :accent="$item->reorder_level !== null && $a['available'] < $item->reorder_level ? 'rose' : 'teal'" :hint="$item->reorder_level !== null ? 'reorder at ' . $item->qty($item->reorder_level) : null" /></div>
        <div class="col-6 col-lg"><x-cb.stat label="On order" :value="$item->qty($a['incoming'])" icon="ri-truck-line" accent="violet" :hint="'can promise ' . $item->qty($a['atp'])" /></div>
        @can('View costing')
            @unless($item->isCustomerOwned())
                <div class="col-6 col-lg"><x-cb.stat label="Average cost" :value="Money::format($item->average_cost)" icon="ri-price-tag-3-line" accent="violet" :hint="'value ' . Money::format($a['on_hand'] * $item->average_cost)" /></div>
            @endunless
        @endcan
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card title="By location" icon="ri-store-2-line" :flush="true">
                @if($byBranch->isEmpty())
                    <p class="text-muted small p-3 mb-0">No stock anywhere yet.
                        @can('Manage inventory')<a href="{{ route('goods-receipts.create') }}">Receive stock</a>@endcan
                        @can('Adjust stock') or <a href="{{ route('stock-adjustments.create', ['kind' => $item->isCustomerOwned() ? 'customer_material' : 'opening', 'item' => $item->public_id, 'customer' => $item->customer_id]) }}">record what you already have</a>.@endcan
                    </p>
                @else
                <div class="table-responsive">
                    <table class="cb-table stack mb-0">
                        <thead><tr><th>Location</th><th class="text-end">On hand</th><th class="text-end">Reserved</th><th class="text-end">Free</th><th>Rolls / lots</th></tr></thead>
                        <tbody>
                        @foreach($byBranch as $row)
                            <tr>
                                <td data-label="Location" class="fw-semibold">{{ $row['branch']?->name ?? '—' }}</td>
                                <td data-label="On hand" class="text-end">{{ $item->qty($row['on_hand']) }}</td>
                                <td data-label="Reserved" class="text-end text-muted">{{ $row['reserved'] > 0 ? $item->qty($row['reserved']) : '—' }}</td>
                                <td data-label="Free" class="text-end fw-semibold">{{ $item->qty($row['on_hand'] - $row['reserved']) }}</td>
                                <td data-label="Rolls / lots">
                                    <div class="d-flex flex-wrap gap-1">
                                        @foreach($row['lots'] as $b)
                                            <span class="term-chip {{ $b->lot?->kind === 'remnant' ? 'border-warning' : '' }}">{{ $b->lot?->label }}: <strong>{{ $item->qty($b->on_hand, false) }}</strong></span>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </x-cb.card>

            <x-cb.card title="Recent movements" icon="ri-git-commit-line" class="mt-3" :flush="true">
                <x-slot:tools><a href="{{ route('inventory.movements', ['item' => $item->public_id]) }}" class="action-btn"><i class="ri-list-check"></i>Full ledger</a></x-slot:tools>
                @include('inventory.partials.movement-table', ['movements' => $movements, 'showItem' => false])
            </x-cb.card>
        </div>

        <div class="col-xl-4">
            <x-cb.card title="Reserved for jobs" icon="ri-lock-line" :count="$reservations->count()">
                @forelse($reservations as $r)
                    <div class="d-flex justify-content-between align-items-start gap-2 py-2 border-bottom">
                        <div>
                            <div class="fw-semibold">{{ $item->qty($r->open_quantity) }} · {{ $r->reference }}</div>
                            <div class="small text-muted">{{ $r->branch?->name }}@if($r->customer) · {{ $r->customer->name }}@endif @if($r->needed_by) · needed {{ $r->needed_by->format('d M') }}@endif</div>
                        </div>
                        @if($u->can('Manage inventory') || $u->can('Issue material'))
                            <form method="POST" action="{{ route('inventory.reservations.release', $r->id) }}" onsubmit="return confirm('Release this reservation?')">@csrf
                                <button class="btn btn-sm btn-light" title="Release"><i class="ri-lock-unlock-line"></i></button></form>
                        @endif
                    </div>
                @empty
                    <p class="small text-muted mb-2">Nothing reserved.</p>
                @endforelse

                @can('Manage inventory')
                    <button class="action-btn mt-2" data-bs-toggle="collapse" data-bs-target="#reserveBox" type="button"><i class="ri-add-line"></i>Reserve for a job</button>
                    <div class="collapse mt-2" id="reserveBox">
                        <form method="POST" action="{{ route('inventory.reservations.store', $item) }}" class="row g-2">
                            @csrf
                            <div class="col-6"><select name="branch_id" class="form-select form-select-sm" required>@foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></div>
                            <div class="col-6"><div class="input-group input-group-sm"><input type="number" step="any" min="0" name="quantity" class="form-control" required placeholder="Qty"><span class="input-group-text">{{ $item->unit }}</span></div></div>
                            <div class="col-12"><input name="reference" class="form-control form-control-sm" required maxlength="120" placeholder="For what? e.g. Mrs Eze — wedding gown"></div>
                            <div class="col-7"><select name="customer_id" class="form-select form-select-sm"><option value="">Customer (optional)</option>@foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
                            <div class="col-5"><input type="date" name="needed_by" class="form-control form-control-sm" title="Needed by"></div>
                            <div class="col-12"><button class="action-btn btn-primary-cb w-100 justify-content-center"><i class="ri-lock-line"></i>Reserve</button></div>
                        </form>
                    </div>
                @endcan
            </x-cb.card>

            @if($incoming->isNotEmpty())
                <x-cb.card title="On order" icon="ri-truck-line" class="mt-3">
                    @foreach($incoming as $l)
                        <a href="{{ route('purchase-orders.show', $l->order) }}" class="d-flex justify-content-between py-2 border-bottom text-body">
                            <span><span class="fw-semibold">{{ $l->order->number }}</span><span class="d-block small text-muted">{{ $l->order->supplier->name }} → {{ $l->order->branch->name }}@if($l->order->expected_date) · due {{ $l->order->expected_date->format('d M') }}@endif</span></span>
                            <span class="fw-semibold">{{ $item->qty($l->outstanding) }}</span>
                        </a>
                    @endforeach
                </x-cb.card>
            @endif

            <x-cb.card title="Details" icon="ri-information-line" class="mt-3">
                <dl class="row small mb-0">
                    <dt class="col-5 text-muted fw-normal">Unit</dt><dd class="col-7">{{ $item->unit }} ({{ $item->decimals }} decimals)</dd>
                    @if($item->preferredSupplier)<dt class="col-5 text-muted fw-normal">Supplier</dt><dd class="col-7"><a href="{{ route('suppliers.edit', $item->preferredSupplier) }}">{{ $item->preferredSupplier->name }}</a></dd>@endif
                    @if($item->reorder_quantity)<dt class="col-5 text-muted fw-normal">Reorder qty</dt><dd class="col-7">{{ $item->qty($item->reorder_quantity) }}</dd>@endif
                    @if($item->sale_price)<dt class="col-5 text-muted fw-normal">Sale price</dt><dd class="col-7">{{ Money::format($item->sale_price) }}</dd>@endif
                    @can('View costing')@if($item->last_cost)<dt class="col-5 text-muted fw-normal">Last cost</dt><dd class="col-7">{{ Money::format($item->last_cost) }}</dd>@endif @endcan
                    @if($item->description)<dt class="col-5 text-muted fw-normal">Notes</dt><dd class="col-7">{{ $item->description }}</dd>@endif
                </dl>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    @can('Adjust stock')
                        <a href="{{ route('stock-adjustments.create', ['kind' => 'adjustment', 'item' => $item->public_id]) }}" class="action-btn"><i class="ri-scales-3-line"></i>Adjust</a>
                        <a href="{{ route('stock-adjustments.create', ['kind' => 'wastage', 'item' => $item->public_id]) }}" class="action-btn"><i class="ri-delete-bin-6-line"></i>Wastage</a>
                    @endcan
                    @can('Manage inventory')<a href="{{ route('stock-transfers.create') }}" class="action-btn"><i class="ri-arrow-left-right-line"></i>Transfer</a>@endcan
                </div>
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>
@endsection
