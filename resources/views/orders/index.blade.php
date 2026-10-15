@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; use App\Support\Orders\OrderStatus; $canMoney = auth()->user()->can('View payment'); @endphp
    <x-cb.hero title="Orders" icon="ri-file-list-3-line" subtitle="Every garment from deposit to delivery, with what has been paid and what is still owed.">
        <x-slot:actions>
            @can('Manage quotation')<a href="{{ route('quotations.create') }}" class="cb-hero-btn"><i class="ri-draft-line me-1"></i>New quotation</a>@endcan
            @can('Create order')<a href="{{ route('orders.create') }}" class="cb-hero-btn"><i class="ri-add-line me-1"></i>New order</a>@endcan
        </x-slot:actions>
    </x-cb.hero>
    @include('orders.partials.nav')
    @include('inventory.partials.flash')

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><a href="{{ route('orders.index') }}" class="text-decoration-none"><x-cb.stat label="Open orders" :value="$stats['open']" icon="ri-file-list-3-line" accent="teal" /></a></div>
        <div class="col-6 col-lg-3"><a href="{{ route('orders.index', ['view' => 'due']) }}" class="text-decoration-none"><x-cb.stat label="Due in 7 days" :value="$stats['due']" icon="ri-calendar-event-line" accent="amber" /></a></div>
        <div class="col-6 col-lg-3"><a href="{{ route('orders.index', ['view' => 'late']) }}" class="text-decoration-none"><x-cb.stat label="Past promised date" :value="$stats['late']" icon="ri-alarm-warning-line" :accent="$stats['late'] ? 'rose' : 'teal'" /></a></div>
        <div class="col-6 col-lg-3">@if($canMoney)<x-cb.stat label="Open order value" :value="Money::format($stats['value'])" icon="ri-money-dollar-circle-line" accent="violet" />@endif</div>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach(['open' => 'Open', 'draft' => OrderStatus::label('draft'), 'confirmed' => OrderStatus::label('confirmed'), 'in_production' => OrderStatus::label('in_production'), 'fitting' => OrderStatus::label('fitting'), 'ready' => OrderStatus::label('ready'), 'delivered' => OrderStatus::label('delivered'), 'closed' => OrderStatus::label('closed'), 'cancelled' => OrderStatus::label('cancelled'), 'all' => 'All'] as $k => $l)
            <a href="{{ route('orders.index', ['view' => $k]) }}" class="action-btn {{ $view === $k ? 'btn-primary-cb' : '' }}">{{ $l }}
                @if(isset($counts[$k]))<span class="badge bg-light text-body ms-1">{{ $counts[$k] }}</span>@endif</a>
        @endforeach
    </div>

    <x-cb.card title="Orders" icon="ri-list-check" :count="$orders->total()" :flush="true">
        <form method="GET" class="cb-toolbar">
            <input type="hidden" name="view" value="{{ $view }}">
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ request('q') }}" placeholder="Order number, title or customer…"></div>
            <label class="small d-flex gap-1 align-items-center"><input type="checkbox" name="mine" value="1" @checked(request('mine')) onchange="this.form.submit()"> Mine</label>
            <button class="action-btn btn-primary-cb ms-auto"><i class="ri-search-line"></i>Search</button>
        </form>
        @if($orders->isEmpty())
            <div class="empty-state"><i class="ri-file-list-3-line"></i><h6>No orders here</h6><p>Start from a quotation or create an order directly.</p></div>
        @else
        <div class="table-responsive">
            <table class="cb-table stack mb-0">
                <thead><tr><th>Order</th><th>Customer</th><th>Promised</th><th class="text-end">Garments</th>@if($canMoney)<th class="text-end">Total</th><th class="text-end">Balance</th>@endif<th>Status</th></tr></thead>
                <tbody>
                @foreach($orders as $o)
                    @php $bal = $canMoney ? $o->balance() : null; @endphp
                    <tr onclick="location.href='{{ route('orders.show', $o) }}'" style="cursor:pointer">
                        <td data-label="Order"><a href="{{ route('orders.show', $o) }}" class="fw-semibold">{{ $o->number }}</a>
                            @if($o->priority === 'rush')<span class="status-pill st-danger ms-1">Rush</span>@endif
                            <div class="small text-muted">{{ $o->title ?? '—' }}</div></td>
                        <td data-label="Customer">{{ $o->customer->name }}<div class="small text-muted">{{ $o->customer->customer_number }}</div></td>
                        <td data-label="Promised" class="{{ $o->isLate() ? 'text-danger fw-semibold' : '' }}">{{ $o->promised_date?->format('d M Y') ?? '—' }}@if($o->event_date)<div class="small text-muted">event {{ $o->event_date->format('d M') }}</div>@endif</td>
                        <td data-label="Garments" class="text-end">{{ $o->lines_count }}</td>
                        @if($canMoney)
                            <td data-label="Total" class="text-end">{{ Money::format($o->total) }}</td>
                            <td data-label="Balance" class="text-end fw-semibold {{ $bal > 0 ? 'text-danger' : ($bal < 0 ? 'text-success' : 'text-muted') }}">{{ $bal == 0 ? 'Paid' : Money::format($bal) }}</td>
                        @endif
                        <td data-label="Status"><span class="status-pill {{ $o->status_pill }}">{{ $o->status_label }}</span></td>
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
