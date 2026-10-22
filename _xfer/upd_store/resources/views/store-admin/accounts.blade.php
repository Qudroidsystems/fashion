@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    <x-cb.hero title="Shoppers" icon="ri-user-heart-line" subtitle="People with an account on your shop. They become customers with their first confirmed order." />
    @include('store-admin.nav')
    <x-cb.card title="Accounts" icon="ri-list-check" :count="$accounts->total()" :flush="true">
        <form method="GET" class="cb-toolbar"><div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ request('q') }}" placeholder="Name, e-mail or phone…"></div>
            <button class="action-btn btn-primary-cb ms-auto"><i class="ri-search-line"></i>Search</button></form>
        @if($accounts->isEmpty())<div class="empty-state"><i class="ri-user-heart-line"></i><h6>No shoppers yet</h6></div>
        @else
        <div class="table-responsive"><table class="cb-table stack mb-0">
            <thead><tr><th>Shopper</th><th>Contact</th><th>Orders</th><th>Customer</th><th>Joined</th></tr></thead>
            <tbody>@foreach($accounts as $a)
                <tr><td data-label="Shopper" class="fw-semibold">{{ $a->name }}@if($a->marketing_opt_in)<span class="term-chip ms-1">Offers OK</span>@endif</td>
                    <td data-label="Contact">{{ $a->email }}<div class="small text-muted">{{ $a->phone }}</div></td>
                    <td data-label="Orders">{{ $a->orders_count }}</td>
                    <td data-label="Customer">@if($a->customer)<a href="{{ route('customers.show', $a->customer) }}">{{ $a->customer->customer_number }}</a>@else<span class="text-muted">—</span>@endif</td>
                    <td data-label="Joined">{{ $a->created_at->timezone($tz)->format('d M Y') }}<div class="small text-muted">{{ $a->last_login_at ? 'Last seen ' . $a->last_login_at->diffForHumans() : '' }}</div></td></tr>
            @endforeach</tbody>
        </table></div>
        @endif
    </x-cb.card>
    <div class="mt-3">{{ $accounts->links() }}</div>
</div></div></div>
@endsection
