@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; @endphp
    <x-cb.hero title="Quotations" icon="ri-draft-line" subtitle="Price offers. When the customer agrees, one click turns it into a confirmed order.">
        <x-slot:actions><a href="{{ route('quotations.create') }}" class="cb-hero-btn"><i class="ri-add-line me-1"></i>New quotation</a></x-slot:actions>
    </x-cb.hero>
    @include('orders.partials.nav')
    @include('inventory.partials.flash')

    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach(['open' => 'Open', 'draft' => 'Draft', 'sent' => 'Sent', 'converted' => 'Became orders', 'declined' => 'Declined', 'all' => 'All'] as $k => $l)
            <a href="{{ route('quotations.index', ['status' => $k]) }}" class="action-btn {{ $status === $k ? 'btn-primary-cb' : '' }}">{{ $l }}</a>
        @endforeach
    </div>

    <x-cb.card title="Quotations" icon="ri-list-check" :count="$quotations->total()" :flush="true">
        <form method="GET" class="cb-toolbar"><input type="hidden" name="status" value="{{ $status }}">
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ request('q') }}" placeholder="Number, title or customer…"></div>
            <button class="action-btn btn-primary-cb ms-auto"><i class="ri-search-line"></i>Search</button></form>
        @if($quotations->isEmpty())
            <div class="empty-state"><i class="ri-draft-line"></i><h6>No quotations here</h6></div>
        @else
        <div class="table-responsive"><table class="cb-table stack mb-0">
            <thead><tr><th>Quotation</th><th>Customer</th><th>Valid until</th><th class="text-end">Total</th><th>Status</th></tr></thead>
            <tbody>
            @foreach($quotations as $q)
                <tr onclick="location.href='{{ route('quotations.show', $q) }}'" style="cursor:pointer">
                    <td data-label="Quotation"><a href="{{ route('quotations.show', $q) }}" class="fw-semibold">{{ $q->number }}</a><div class="small text-muted">{{ $q->title ?? '—' }}</div></td>
                    <td data-label="Customer">{{ $q->customer->name }}</td>
                    <td data-label="Valid until">{{ $q->valid_until?->format('d M Y') ?? '—' }}</td>
                    <td data-label="Total" class="text-end">{{ Money::format($q->total) }}</td>
                    <td data-label="Status"><span class="status-pill {{ $q->status_pill }}">{{ $q->status_label }}</span>@if($q->order)<div class="small"><a href="{{ route('orders.show', $q->order) }}">{{ $q->order->number }}</a></div>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @endif
    </x-cb.card>
    <div class="mt-3">{{ $quotations->links() }}</div>

</div>
</div>
</div>
@endsection
