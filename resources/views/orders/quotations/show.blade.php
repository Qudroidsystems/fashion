@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; $st = $q->effectiveStatus(); @endphp
    <x-cb.hero :title="$q->number . ($q->title ? ' · ' . $q->title : '')" icon="ri-draft-line" :subtitle="$q->customer->name . ' · ' . $q->customer->customer_number"
               :back="route('quotations.index')" back-label="Quotations">
        <x-slot:pills>
            <span class="status-pill {{ $q->status_pill }}">{{ $q->status_label }}</span>
            @if($q->valid_until)<span class="cb-meta-pill">Valid until {{ $q->valid_until->format('d M Y') }}</span>@endif
            @if($q->event_date)<span class="cb-meta-pill">Event {{ $q->event_date->format('d M Y') }}</span>@endif
        </x-slot:pills>
        <x-slot:actions>
            @if($q->isEditable())<a href="{{ route('quotations.edit', $q) }}" class="cb-hero-btn"><i class="ri-pencil-line me-1"></i>Edit</a>@endif
            <button type="button" class="cb-hero-btn" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print</button>
        </x-slot:actions>
    </x-cb.hero>
    @include('orders.partials.nav')
    @include('inventory.partials.flash')
    @if($st === 'expired')<div class="cb-banner warning"><i class="ri-time-line"></i><div>This quotation has expired. You can still accept it if the customer agrees to these prices.</div></div>@endif
    @if($q->status === 'declined')<div class="cb-banner danger"><i class="ri-close-circle-line"></i><div>Declined{{ $q->decline_reason ? ': ' . $q->decline_reason : '' }}</div></div>@endif

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card :flush="true">
                <div class="p-4">
                    <div class="d-flex justify-content-between flex-wrap gap-3 mb-4">
                        <div><div class="fs-4 fw-bold">{{ $house->name }}</div><div class="small text-muted">{{ $house->address }}<br>{{ $house->phone }} {{ $house->email }}</div></div>
                        <div class="text-end"><div class="fs-5 fw-semibold">Quotation {{ $q->number }}</div>
                            <div class="small text-muted">{{ $q->created_at->timezone($tz)->format('d M Y') }}@if($q->valid_until) · valid until {{ $q->valid_until->format('d M Y') }}@endif</div></div>
                    </div>
                    <div class="mb-3"><div class="small text-muted">For</div><div class="fw-semibold">{{ $q->customer->name }}</div><div class="small">{{ $q->customer->phone }}</div></div>
                    <table class="table mb-0">
                        <thead class="table-light"><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Total</th></tr></thead>
                        <tbody>
                        @foreach($q->lines as $l)
                            <tr>
                                <td><div class="fw-semibold">{{ $l->description }}</div>
                                    @if($l->options)<div class="small text-muted">incl. {{ collect($l->options)->pluck('label')->implode(', ') }}</div>@endif
                                    @if($l->instructions)<div class="small text-muted">{{ $l->instructions }}</div>@endif</td>
                                <td class="text-end">{{ $l->qty }}</td>
                                <td class="text-end">{{ Money::format($l->unit_price) }}</td>
                                <td class="text-end">{{ Money::format($l->line_total) }}@if($l->discount > 0)<div class="small text-muted">−{{ Money::format($l->discount) }}</div>@endif</td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot>
                            @if($q->discount_total > 0)
                                <tr><td colspan="3" class="text-end text-muted">Subtotal</td><td class="text-end">{{ Money::format($q->subtotal) }}</td></tr>
                                <tr><td colspan="3" class="text-end text-muted">Discount</td><td class="text-end">−{{ Money::format($q->discount_total) }}</td></tr>
                            @endif
                            <tr><td colspan="3" class="text-end fw-bold">Total</td><td class="text-end fw-bold fs-5">{{ Money::format($q->total) }}</td></tr>
                                @php $pct = $q->deposit_percent ?? (float) \App\Support\Tenancy\CurrentTenant::get()->setting('deposit_percent', 50); @endphp
                                <tr><td colspan="3" class="text-end text-muted">Deposit to start ({{ rtrim(rtrim(number_format($pct, 2), '0'), '.') }}%)</td><td class="text-end">{{ Money::format(round($q->total * $pct / 100, 2)) }}</td></tr>
                        </tfoot>
                    </table>
                    @if($q->customer_notes)<p class="small mt-3 mb-0">{{ $q->customer_notes }}</p>@endif
                </div>
            </x-cb.card>
        </div>
        <div class="col-xl-4">
            <x-cb.card title="Next step" icon="ri-flashlight-line">
                @if($q->order)
                    <a href="{{ route('orders.show', $q->order) }}" class="action-btn btn-primary-cb w-100 justify-content-center py-2"><i class="ri-file-list-3-line"></i>Open order {{ $q->order->number }}</a>
                @elseif(in_array($q->status, ['draft', 'sent']))
                    <div class="d-grid gap-2">
                        @if($q->status === 'draft')
                            <form method="POST" action="{{ route('quotations.send', $q) }}" class="d-grid">@csrf<button class="action-btn justify-content-center py-2"><i class="ri-send-plane-line"></i>Mark as sent</button></form>
                        @endif
                        @can('Create order')
                            <button type="button" class="action-btn btn-primary-cb justify-content-center py-2" data-bs-toggle="collapse" data-bs-target="#acceptBox"><i class="ri-checkbox-circle-line"></i>Customer accepted</button>
                            <div class="collapse" id="acceptBox"><form method="POST" action="{{ route('quotations.accept', $q) }}">@csrf
                                <label class="form-label small">Promise delivery for</label>
                                <input type="date" name="promised_date" class="form-control form-control-sm mb-2" value="{{ $q->event_date?->copy()->subDays(3)->toDateString() }}">
                                <button class="btn btn-sm btn-primary w-100">Create the order</button></form></div>
                        @endcan
                        <button type="button" class="action-btn justify-content-center py-2" data-bs-toggle="collapse" data-bs-target="#declineBox"><i class="ri-close-circle-line"></i>Declined</button>
                        <div class="collapse" id="declineBox"><form method="POST" action="{{ route('quotations.decline', $q) }}">@csrf
                            <input name="reason" class="form-control form-control-sm mb-2" maxlength="255" placeholder="Why? (helps you price better)">
                            <button class="btn btn-sm btn-danger w-100">Mark declined</button></form></div>
                    </div>
                @else
                    <p class="small text-muted mb-0">Nothing more to do.</p>
                @endif
            </x-cb.card>
            @if($q->internal_notes)<x-cb.card title="Internal notes" icon="ri-sticky-note-line" class="mt-3"><p class="small mb-0">{{ $q->internal_notes }}</p></x-cb.card>@endif
        </div>
    </div>

</div>
</div>
</div>
@endsection
