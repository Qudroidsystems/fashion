@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @php use App\Support\Money; @endphp
    <x-cb.hero title="Online store" icon="ri-store-3-line" subtitle="Orders from your shop and WhatsApp, in one place.">
        <x-slot:pills>
            <span class="status-pill {{ $open ? 'st-success' : 'st-muted' }}">{{ $open ? 'Shop open' : 'Shop closed' }}</span>
            @foreach($methods as $k => $l)<span class="cb-meta-pill">{{ \App\Models\ShopOrder::PAYMENT_METHODS[$k] }}</span>@endforeach
        </x-slot:pills>
        <x-slot:actions>
            <a href="{{ $url }}" target="_blank" rel="noopener" class="cb-hero-btn"><i class="ri-external-link-line me-1"></i>View shop</a>
            @can('Manage online orders')<a href="{{ route('store.orders.create') }}" class="cb-hero-btn"><i class="ri-whatsapp-line me-1"></i>Take an order</a>@endcan
        </x-slot:actions>
    </x-cb.hero>
    @include('store-admin.nav')
    @include('inventory.partials.flash')

    @unless($open)
        <div class="cb-banner warning"><i class="ri-alert-line"></i><div>The shop is closed to customers.
            @can('Manage online store') <a href="{{ route('store.settings.edit') }}">Open it in Settings</a> (your website must be switched on too).@endcan</div></div>
    @endunless

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><x-cb.stat label="Online sales today" :value="Money::format($stats['today'])" icon="ri-shopping-bag-3-line" accent="teal" :hint="$stats['today_n'] . ' order(s)'" /></div>
        <div class="col-6 col-xl-3"><x-cb.stat label="This month" :value="Money::format($stats['month'])" icon="ri-calendar-line" accent="violet" :hint="$stats['month_n'] . ' order(s) · ' . Money::format($stats['collected']) . ' collected'" /></div>
        <div class="col-6 col-xl-3"><a href="{{ route('store.orders.index', ['tab' => 'confirm']) }}" class="text-reset"><x-cb.stat label="To confirm" :value="$stats['to_confirm']" icon="ri-whatsapp-line" accent="amber" :hint="$stats['whatsapp'] . ' from WhatsApp'" /></a></div>
        <div class="col-6 col-xl-3"><a href="{{ route('store.orders.index', ['tab' => 'payments']) }}" class="text-reset"><x-cb.stat label="Receipts to check · To send" :value="$stats['proofs'] . ' · ' . $stats['to_ship']" icon="ri-bank-line" accent="rose" /></a></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card title="Latest orders" icon="ri-list-check" :flush="true">
                <x-slot:tools><a href="{{ route('store.orders.index', ['tab' => 'all']) }}" class="action-btn">All orders</a></x-slot:tools>
                @if($recent->isEmpty())
                    <div class="empty-state"><i class="ri-shopping-bag-3-line"></i><h6>No online orders yet</h6><p>Orders placed on your shop or taken on WhatsApp appear here.</p></div>
                @else
                <div class="table-responsive"><table class="cb-table stack mb-0">
                    <thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Payment</th><th>Status</th></tr></thead>
                    <tbody>@foreach($recent as $o)
                        <tr onclick="location='{{ route('store.orders.show', $o->public_id) }}'" style="cursor:pointer">
                            <td data-label="Order"><a href="{{ route('store.orders.show', $o->public_id) }}" class="fw-semibold">{{ $o->number }}</a><div class="small text-muted">{{ $o->created_at->timezone($tenant->timezone)->format('d M, H:i') }}</div></td>
                            <td data-label="Customer">{{ $o->name }}<div class="small text-muted">{{ $o->phone }}</div></td>
                            <td data-label="Total">{{ Money::format($o->total) }}</td>
                            <td data-label="Payment"><span class="status-pill {{ $o->payment_status_pill }}">{{ $o->payment_status_label }}</span><div class="small text-muted">{{ $o->payment_method_label }}</div></td>
                            <td data-label="Status"><span class="status-pill {{ $o->status_pill }}">{{ $o->status_label }}</span></td>
                        </tr>
                    @endforeach</tbody>
                </table></div>
                @endif
            </x-cb.card>
        </div>
        <div class="col-xl-4">
            <x-cb.card title="Best sellers this month" icon="ri-fire-line">
                @forelse($top as $t)
                    <div class="d-flex justify-content-between py-2 border-bottom"><span>{{ $t->name }}</span><span class="text-muted">{{ (int) $t->qty }} · {{ Money::format($t->amount) }}</span></div>
                @empty<p class="text-muted mb-0">Nothing sold online yet this month.</p>@endforelse
            </x-cb.card>
            <x-cb.card title="Running low in the shop" icon="ri-alarm-warning-line" class="mt-3">
                @forelse($lowStock as $r)
                    <div class="d-flex justify-content-between py-2 border-bottom"><span>{{ $r['product']->name }} <span class="text-muted small">{{ $r['variant']->label }}</span></span>
                        <span class="status-pill {{ $r['left'] <= 0 ? 'st-danger' : 'st-warning' }}">{{ $r['left'] <= 0 ? 'Sold out' : $r['left'] . ' left' }}</span></div>
                @empty<p class="text-muted mb-0">Stock looks fine.</p>@endforelse
            </x-cb.card>
        </div>
    </div>
</div></div></div>
@endsection
