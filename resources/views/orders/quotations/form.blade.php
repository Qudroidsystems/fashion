@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php $isNew = ! $q->exists; @endphp
    <x-cb.hero :title="$isNew ? 'New quotation' : 'Edit ' . $q->number" icon="ri-draft-line" subtitle="Prices are checked and totals calculated when you save."
               :back="$isNew ? route('quotations.index') : route('quotations.show', $q)" :back-label="$isNew ? 'Quotations' : 'Back'" />
    @include('inventory.partials.flash')

    <form method="POST" action="{{ $isNew ? route('quotations.store') : route('quotations.update', $q) }}">
        @csrf @unless($isNew) @method('PUT') @endunless
        <div class="row g-3">
            <div class="col-xl-9">
                <x-cb.card title="Quotation" icon="ri-information-line">
                    <div class="row g-3">
                        <div class="col-md-5"><label class="form-label">Customer *</label>
                            @if($isNew)
                                <select name="customer_id" class="form-select" required><option value="">Choose…</option>
                                    @foreach($customers as $c)<option value="{{ $c->id }}" @selected(old('customer_id', $q->customer_id) == $c->id)>{{ $c->name }} · {{ $c->customer_number }}{{ $c->phone ? ' · ' . $c->phone : '' }}</option>@endforeach
                                </select>
                            @else<div class="form-control bg-light">{{ $q->customer->name }}</div>@endif
                        </div>
                        <div class="col-md-7"><label class="form-label">Title / occasion</label><input name="title" class="form-control" maxlength="150" value="{{ old('title', $q->title) }}" placeholder="e.g. Wedding — bride and 6 bridesmaids"></div>
                        <div class="col-md-4"><label class="form-label">Event date</label><input type="date" name="event_date" class="form-control" value="{{ old('event_date', $q->event_date?->toDateString()) }}"></div>
                        <div class="col-md-4"><label class="form-label">Valid until</label><input type="date" name="valid_until" class="form-control" value="{{ old('valid_until', $q->valid_until?->toDateString()) }}" placeholder="Automatic"></div>
                        <div class="col-md-4"><label class="form-label">Deposit %</label><input type="number" min="0" max="100" name="deposit_percent" class="form-control" value="{{ old('deposit_percent', $q->deposit_percent) }}" placeholder="{{ rtrim(rtrim(number_format($depositPercent, 2), '0'), '.') }}"></div>
                    </div>
                </x-cb.card>
                <x-cb.card title="Garments & items" icon="ri-t-shirt-air-line" class="mt-3" :flush="true">
                    @include('orders.partials.lines', ['rows' => old('lines', $rows), 'catalog' => $catalog])
                </x-cb.card>
                <x-cb.card title="Notes" icon="ri-sticky-note-line" class="mt-3">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">On the quotation</label><textarea name="customer_notes" class="form-control" rows="3" maxlength="2000" placeholder="e.g. Price includes lining and two fittings.">{{ old('customer_notes', $q->customer_notes) }}</textarea></div>
                        <div class="col-md-6"><label class="form-label">Internal</label><textarea name="internal_notes" class="form-control" rows="3" maxlength="2000">{{ old('internal_notes', $q->internal_notes) }}</textarea></div>
                    </div>
                </x-cb.card>
            </div>
            <div class="col-xl-3">
                <x-cb.card title="Totals" icon="ri-calculator-line">
                    <div class="d-flex justify-content-between mb-1"><span class="text-muted">Subtotal</span><span>{{ \App\Support\Money::symbol() }}<span class="ol-subtotal">0</span></span></div>
                    <label class="form-label small mt-2 mb-1">Discount</label>
                    <input type="number" min="0" step="0.01" name="order_discount" class="form-control form-control-sm mb-2" value="{{ old('order_discount', $q->order_discount ?: '') }}" placeholder="0">
                    <div class="d-flex justify-content-between mb-1"><span class="text-muted">All discounts</span><span>−{{ \App\Support\Money::symbol() }}<span class="ol-discounts">0</span></span></div>
                    <div class="d-flex justify-content-between fw-bold fs-5 border-top pt-2 mt-2"><span>Total</span><span>{{ \App\Support\Money::symbol() }}<span class="ol-grand">0</span></span></div>
                    <div class="d-grid gap-2 mt-3">
                        <button name="action" value="save" class="action-btn justify-content-center py-2"><i class="ri-save-line"></i>Save draft</button>
                        <button name="action" value="send" class="action-btn btn-primary-cb justify-content-center py-2"><i class="ri-send-plane-line"></i>Save & mark sent</button>
                    </div>
                </x-cb.card>
            </div>
        </div>
    </form>

</div>
</div>
</div>
@endsection
