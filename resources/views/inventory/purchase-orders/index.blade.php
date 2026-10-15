@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; use App\Models\PurchaseOrder; @endphp
    <x-cb.hero title="Purchase orders" icon="ri-file-list-2-line" subtitle="Order from suppliers, get approval, then receive what arrives — in one delivery or several.">
        <x-slot:actions>
            @can('Manage purchase order')<a href="{{ route('purchase-orders.create') }}" class="cb-hero-btn"><i class="ri-add-line me-1"></i>New order</a>@endcan
        </x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.nav')
    @include('inventory.partials.flash')

    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach(['open' => 'Open', 'pending_approval' => 'Awaiting approval', 'approved' => 'To receive', 'partially_received' => 'Part received', 'received' => 'Received', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $l)
            <a href="{{ route('purchase-orders.index', ['status' => $k]) }}" class="action-btn {{ $status === $k ? 'btn-primary-cb' : '' }}">{{ $l }}
                @if(isset($counts[$k]) && $k !== 'all')<span class="badge bg-light text-body ms-1">{{ $counts[$k] }}</span>@endif</a>
        @endforeach
    </div>

    <x-cb.card title="Orders" icon="ri-list-check" :count="$orders->total()" :flush="true">
        <form method="GET" class="cb-toolbar">
            <input type="hidden" name="status" value="{{ $status }}">
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ request('q') }}" placeholder="PO number or supplier…"></div>
            <button class="action-btn btn-primary-cb ms-auto"><i class="ri-search-line"></i>Search</button>
        </form>
        @if($orders->isEmpty())
            <div class="empty-state"><i class="ri-file-list-2-line"></i><h6>No purchase orders</h6><p>Raise one when you need to buy fabric or trims.</p></div>
        @else
        <div class="table-responsive">
            <table class="cb-table stack mb-0">
                <thead><tr><th>Order</th><th>Supplier</th><th>Deliver to</th><th>Expected</th>@can('View costing')<th class="text-end">Total</th>@endcan<th>Status</th></tr></thead>
                <tbody>
                @foreach($orders as $po)
                    <tr onclick="location.href='{{ route('purchase-orders.show', $po) }}'" style="cursor:pointer">
                        <td data-label="Order"><a href="{{ route('purchase-orders.show', $po) }}" class="fw-semibold">{{ $po->number }}</a><div class="small text-muted">{{ $po->order_date->format('d M Y') }} · {{ $po->creator?->name }}</div></td>
                        <td data-label="Supplier">{{ $po->supplier->name }}</td>
                        <td data-label="Deliver to">{{ $po->branch->name }}</td>
                        <td data-label="Expected" class="{{ $po->expected_date && $po->expected_date->isPast() && $po->isReceivable() ? 'text-danger fw-semibold' : '' }}">{{ $po->expected_date?->format('d M Y') ?? '—' }}</td>
                        @can('View costing')<td data-label="Total" class="text-end">{{ Money::format($po->total) }}</td>@endcan
                        <td data-label="Status"><span class="status-pill {{ $po->status_pill }}">{{ $po->status_label }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </x-cb.card>
    <div class="mt-3">{{ $orders->links() }}</div>

</div>
</div>
</div>
@endsection
