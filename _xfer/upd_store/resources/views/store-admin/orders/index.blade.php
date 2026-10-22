@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @php use App\Support\Money; use App\Models\ShopOrder; @endphp
    <x-cb.hero title="Online orders" icon="ri-shopping-bag-3-line" subtitle="Shop checkouts and orders taken on WhatsApp. Confirm them, check payments, pack and send.">
        <x-slot:actions>
            @can('Manage online orders')<a href="{{ route('store.orders.create') }}" class="cb-hero-btn"><i class="ri-whatsapp-line me-1"></i>Take an order</a>@endcan
        </x-slot:actions>
    </x-cb.hero>
    @include('store-admin.nav')
    @include('inventory.partials.flash')

    <ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">
        @foreach($tabs as $k => $t)
            <li class="nav-item"><a class="nav-link text-nowrap {{ $tab === $k ? 'active' : '' }}" href="{{ route('store.orders.index', ['tab' => $k] + request()->only('q', 'method', 'source')) }}">{{ $t['label'] }}@if($t['count'])<span class="badge rounded-pill bg-danger ms-1">{{ $t['count'] }}</span>@endif</a></li>
        @endforeach
    </ul>

    <x-cb.card title="{{ $tabs[$tab]['label'] }}" icon="ri-list-check" :count="$orders->total()" :flush="true">
        <form method="GET" class="cb-toolbar">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ request('q') }}" placeholder="Order number, name, phone or e-mail…"></div>
            <select name="method" class="cb-select" onchange="this.form.submit()">
                <option value="">Any payment</option>
                @foreach(ShopOrder::PAYMENT_METHODS as $k => $l)<option value="{{ $k }}" @selected(request('method') === $k)>{{ $l }}</option>@endforeach
            </select>
            <select name="source" class="cb-select" onchange="this.form.submit()">
                <option value="">Any source</option>
                @foreach(['web' => 'Shop', 'whatsapp' => 'WhatsApp', 'phone' => 'Phone', 'instagram' => 'Instagram', 'walk_in' => 'Walk-in', 'other' => 'Other'] as $k => $l)<option value="{{ $k }}" @selected(request('source') === $k)>{{ $l }}</option>@endforeach
            </select>
            <button class="action-btn btn-primary-cb ms-auto"><i class="ri-filter-3-line"></i>Apply</button>
        </form>
        @if($orders->isEmpty())
            <div class="empty-state"><i class="ri-checkbox-circle-line"></i><h6>Nothing here</h6><p>No orders in this list right now.</p></div>
        @else
        <div class="table-responsive"><table class="cb-table stack mb-0">
            <thead><tr><th>Order</th><th>Customer</th><th>Items</th><th>Total</th><th>Payment</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @foreach($orders as $o)
                <tr>
                    <td data-label="Order"><a href="{{ route('store.orders.show', $o->public_id) }}" class="fw-semibold">{{ $o->number }}</a>
                        <div class="small text-muted">{{ $o->created_at->timezone($tz)->format('d M Y, H:i') }}
                            @if($o->source !== 'web')<span class="term-chip">{{ $o->source === 'whatsapp' ? 'WhatsApp' : ucfirst(str_replace('_', ' ', $o->source)) }}</span>@endif</div></td>
                    <td data-label="Customer">{{ $o->name }}<div class="small text-muted">{{ $o->phone }}</div></td>
                    <td data-label="Items">{{ $o->itemCount() }} · <span class="small text-muted">{{ \Illuminate\Support\Str::limit($o->items->pluck('name')->implode(', '), 40) }}</span>
                        <div class="small text-muted">{{ $o->fulfilment === 'pickup' ? 'Pickup' : 'Delivery' . ($o->state ? ' · ' . $o->state : '') }}</div></td>
                    <td data-label="Total" class="fw-semibold">{{ Money::format($o->total) }}</td>
                    <td data-label="Payment"><span class="status-pill {{ $o->payment_status_pill }}">{{ $o->payment_status_label }}</span><div class="small text-muted">{{ $o->payment_method_label }}</div></td>
                    <td data-label="Status"><span class="status-pill {{ $o->status_pill }}">{{ $o->status_label }}</span></td>
                    <td class="text-end"><a href="{{ route('store.orders.show', $o->public_id) }}" class="action-btn"><i class="ri-arrow-right-line"></i>Open</a></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @endif
    </x-cb.card>
    <div class="mt-3">{{ $orders->links() }}</div>
</div></div></div>
@endsection
