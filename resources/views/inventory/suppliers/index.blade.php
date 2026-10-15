@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; @endphp
    <x-cb.hero title="Suppliers" icon="ri-truck-line" subtitle="The people and markets you buy fabric and materials from.">
        <x-slot:actions>
            @can('Manage supplier')<a href="{{ route('suppliers.create') }}" class="cb-hero-btn"><i class="ri-add-line me-1"></i>New supplier</a>@endcan
        </x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.nav')
    @include('inventory.partials.flash')

    <x-cb.card title="Suppliers" icon="ri-truck-line" :count="$suppliers->total()" :flush="true">
        <form method="GET" class="cb-toolbar">
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ request('q') }}" placeholder="Name, code, phone or contact…"></div>
            <select name="status" class="cb-select" onchange="this.form.submit()">
                <option value="active" @selected(request('status', 'active') === 'active')>Active</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
            </select>
            <button class="action-btn btn-primary-cb ms-auto"><i class="ri-filter-3-line"></i>Apply</button>
        </form>
        @if($suppliers->isEmpty())
            <div class="empty-state"><i class="ri-truck-line"></i><h6>No suppliers yet</h6><p>Add the shops and markets you buy from to raise purchase orders.</p></div>
        @else
        <div class="table-responsive">
            <table class="cb-table stack mb-0">
                <thead><tr><th>Supplier</th><th>Contact</th><th>Terms</th><th class="text-end">Open orders</th>@can('View costing')<th class="text-end">Ordered to date</th>@endcan<th></th></tr></thead>
                <tbody>
                @foreach($suppliers as $s)
                    <tr>
                        <td data-label="Supplier"><a href="{{ route('suppliers.edit', $s) }}" class="fw-semibold">{{ $s->name }}</a><div class="small text-muted">{{ $s->code }}</div></td>
                        <td data-label="Contact">{{ $s->contact_person ?? '—' }}@if($s->phone)<div class="small text-muted">{{ $s->phone }}</div>@endif</td>
                        <td data-label="Terms">{{ $s->payment_terms_days !== null ? ($s->payment_terms_days ? $s->payment_terms_days . ' days' : 'Pay on delivery') : '—' }}</td>
                        <td data-label="Open orders" class="text-end">{{ $s->open_orders ?: '—' }}</td>
                        @can('View costing')<td data-label="Ordered" class="text-end">{{ $s->ordered_total ? Money::format($s->ordered_total) : '—' }}</td>@endcan
                        <td class="text-end">
                            @can('Manage purchase order')<a href="{{ route('purchase-orders.create', ['supplier' => $s->public_id]) }}" class="action-btn"><i class="ri-file-list-2-line"></i>Order</a>@endcan
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </x-cb.card>
    <div class="mt-3">{{ $suppliers->links() }}</div>

</div>
</div>
</div>
@endsection
