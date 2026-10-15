@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    @php use App\Support\Money; $list = $sales->getCollection(); @endphp
    <x-cb.hero title="Sales" icon="ri-receipt-line" :subtitle="'Till sales on ' . $date->timezone($tz)->format('l d M Y') . '.'">
        <x-slot:actions><a href="{{ route('pos.terminal') }}" class="cb-hero-btn"><i class="ri-shopping-cart-2-line me-1"></i>Open till</a></x-slot:actions>
    </x-cb.hero>
    @include('pos.partials-nav')
    @include('inventory.partials.flash')

    <x-cb.card title="Sales" icon="ri-list-check" :count="$sales->total()" :flush="true">
        <form method="GET" class="cb-toolbar">
            <input type="date" name="date" value="{{ $date->timezone($tz)->toDateString() }}" class="cb-select" onchange="this.form.submit()">
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ request('q') }}" placeholder="Sale number…"></div>
            <span class="small text-muted ms-auto">{{ Money::format($list->where('status', 'completed')->sum('total')) }} on this page</span>
        </form>
        @if($sales->isEmpty())
            <div class="empty-state"><i class="ri-receipt-line"></i><h6>No sales</h6></div>
        @else
        <div class="table-responsive"><table class="cb-table stack mb-0">
            <thead><tr><th>Sale</th><th>Customer</th><th class="text-end">Items</th><th class="text-end">Total</th><th>Cashier</th><th>Status</th></tr></thead>
            <tbody>
            @foreach($sales as $s)
                <tr onclick="location.href='{{ route('pos.sales.show', $s) }}'" style="cursor:pointer" class="{{ $s->status === 'voided' ? 'opacity-50' : '' }}">
                    <td data-label="Sale"><a href="{{ route('pos.sales.show', $s) }}" class="fw-semibold">{{ $s->number }}</a><div class="small text-muted">{{ $s->created_at->timezone($tz)->format('d M, H:i') }} · {{ $s->branch->name }}</div></td>
                    <td data-label="Customer">{{ $s->customer?->name ?? 'Walk-in' }}</td>
                    <td data-label="Items" class="text-end">{{ $s->lines_count }}</td>
                    <td data-label="Total" class="text-end fw-semibold">{{ Money::format($s->total) }}</td>
                    <td data-label="Cashier" class="small">{{ $s->cashier?->name }}</td>
                    <td data-label="Status"><span class="status-pill {{ $s->status === 'voided' ? 'st-danger' : 'st-success' }}">{{ $s->status === 'voided' ? 'Voided' : 'Paid' }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @endif
    </x-cb.card>
    <div class="mt-3">{{ $sales->links() }}</div>
</div>
</div>
</div>
@endsection
