@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; @endphp
    <x-cb.hero title="Goods received" icon="ri-inbox-archive-line" subtitle="Every delivery that added stock, with or without a purchase order.">
        <x-slot:actions>@can('Manage inventory')<a href="{{ route('goods-receipts.create') }}" class="cb-hero-btn"><i class="ri-add-line me-1"></i>Receive without an order</a>@endcan</x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.nav')
    @include('inventory.partials.flash')

    <x-cb.card title="Deliveries" icon="ri-list-check" :count="$receipts->total()" :flush="true">
        <form method="GET" class="cb-toolbar">
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ request('q') }}" placeholder="GRN, delivery note or supplier…"></div>
            <button class="action-btn btn-primary-cb ms-auto"><i class="ri-search-line"></i>Search</button>
        </form>
        @if($receipts->isEmpty())
            <div class="empty-state"><i class="ri-inbox-archive-line"></i><h6>Nothing received yet</h6></div>
        @else
        <div class="table-responsive">
            <table class="cb-table stack mb-0">
                <thead><tr><th>Received</th><th>Supplier</th><th>Order</th><th>Location</th>@can('View costing')<th class="text-end">Value</th>@endcan<th>By</th></tr></thead>
                <tbody>
                @foreach($receipts as $g)
                    <tr onclick="location.href='{{ route('goods-receipts.show', $g) }}'" style="cursor:pointer">
                        <td data-label="Received"><a href="{{ route('goods-receipts.show', $g) }}" class="fw-semibold">{{ $g->number }}</a><div class="small text-muted">{{ $g->received_at->timezone($tz)->format('d M Y, H:i') }}</div></td>
                        <td data-label="Supplier">{{ $g->supplier?->name ?? '—' }}@if($g->supplier_reference)<div class="small text-muted">note {{ $g->supplier_reference }}</div>@endif</td>
                        <td data-label="Order">{{ $g->purchaseOrder?->number ?? 'Direct' }}</td>
                        <td data-label="Location">{{ $g->branch->name }}</td>
                        @can('View costing')<td data-label="Value" class="text-end">{{ Money::format($g->total) }}</td>@endcan
                        <td data-label="By" class="small">{{ $g->receiver?->name }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </x-cb.card>
    <div class="mt-3">{{ $receipts->links() }}</div>

</div>
</div>
</div>
@endsection
