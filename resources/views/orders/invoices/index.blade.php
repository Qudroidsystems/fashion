@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; use App\Models\Invoice; @endphp
    <x-cb.hero title="Invoices" icon="ri-bill-line" subtitle="Deposit and balance requests for orders, and stand-alone bills. Issued invoices are never edited — void and re-issue instead.">
        <x-slot:actions>@can('Manage invoice')<a href="{{ route('invoices.create') }}" class="cb-hero-btn"><i class="ri-add-line me-1"></i>Invoice without an order</a>@endcan</x-slot:actions>
    </x-cb.hero>
    @include('orders.partials.nav')
    @include('inventory.partials.flash')

    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach(['unpaid' => 'Unpaid', 'draft' => 'Drafts', 'void' => 'Void', 'all' => 'All'] as $k => $l)
            <a href="{{ route('invoices.index', ['status' => $k]) }}" class="action-btn {{ $status === $k ? 'btn-primary-cb' : '' }}">{{ $l }}</a>
        @endforeach
    </div>
    <x-cb.card title="Invoices" icon="ri-list-check" :flush="true">
        <form method="GET" class="cb-toolbar"><input type="hidden" name="status" value="{{ $status }}">
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ request('q') }}" placeholder="Invoice number or customer…"></div>
            <button class="action-btn btn-primary-cb ms-auto"><i class="ri-search-line"></i>Search</button></form>
        @if($invoices->isEmpty())
            <div class="empty-state"><i class="ri-bill-line"></i><h6>Nothing here</h6></div>
        @else
        <div class="table-responsive"><table class="cb-table stack mb-0">
            <thead><tr><th>Invoice</th><th>Customer</th><th>Order</th><th>Due</th><th class="text-end">Total</th><th class="text-end">Balance</th><th>Status</th></tr></thead>
            <tbody>
            @foreach($invoices as $i)
                <tr onclick="location.href='{{ route('invoices.show', $i) }}'" style="cursor:pointer">
                    <td data-label="Invoice"><a href="{{ route('invoices.show', $i) }}" class="fw-semibold">{{ $i->number ?? 'Draft' }}</a><div class="small text-muted">{{ Invoice::KINDS[$i->kind] }}</div></td>
                    <td data-label="Customer">{{ $i->customer->name }}</td>
                    <td data-label="Order">{{ $i->order?->number ?? '—' }}</td>
                    <td data-label="Due">{{ $i->due_date?->format('d M Y') ?? '—' }}</td>
                    <td data-label="Total" class="text-end">{{ Money::format($i->total) }}</td>
                    <td data-label="Balance" class="text-end fw-semibold">{{ $i->status === 'issued' ? Money::format($i->balance()) : '—' }}</td>
                    <td data-label="Status"><span class="status-pill {{ $i->status_pill }}">{{ $i->status_label }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @endif
    </x-cb.card>
    <div class="mt-3">{{ $invoices->links() }}</div>

</div>
</div>
</div>
@endsection
