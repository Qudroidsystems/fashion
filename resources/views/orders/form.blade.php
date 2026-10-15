@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php $isNew = ! $order->exists; $confirmed = ! $isNew && $order->status !== 'draft'; @endphp
    <x-cb.hero :title="$isNew ? 'New order' : 'Edit ' . $order->number" icon="ri-file-list-3-line"
               :subtitle="$confirmed ? 'This order is confirmed: your change is kept as revision ' . ($order->revision + 1) . ' with the reason you give.' : 'Prices are checked and totals calculated when you save.'"
               :back="$isNew ? route('orders.index') : route('orders.show', $order)" :back-label="$isNew ? 'Orders' : 'Back to order'" />
    @include('inventory.partials.flash')

    <form method="POST" action="{{ $isNew ? route('orders.store') : route('orders.update', $order) }}">
        @csrf @unless($isNew) @method('PUT') @endunless
        <div class="row g-3">
            <div class="col-xl-9">
                <x-cb.card title="Order" icon="ri-information-line">
                    <div class="row g-3">
                        <div class="col-md-5"><label class="form-label">Customer *</label>
                            @if($isNew)
                                <select name="customer_id" class="form-select" required><option value="">Choose…</option>
                                    @foreach($customers as $c)<option value="{{ $c->id }}" @selected(old('customer_id', $order->customer_id) == $c->id)>{{ $c->name }} · {{ $c->customer_number }}{{ $c->phone ? ' · ' . $c->phone : '' }}</option>@endforeach
                                </select>
                                <div class="form-text">New customer? <a href="{{ route('customers.create') }}">Add them first</a>.</div>
                            @else
                                <div class="form-control bg-light">{{ $order->customer->name }} · {{ $order->customer->customer_number }}</div>
                            @endif
                        </div>
                        <div class="col-md-7"><label class="form-label">Title / occasion</label><input name="title" class="form-control" maxlength="150" value="{{ old('title', $order->title) }}" placeholder="e.g. Wedding — bride’s reception gown"></div>
                        <div class="col-md-3"><label class="form-label">Event date</label><input type="date" name="event_date" class="form-control" value="{{ old('event_date', $order->event_date?->toDateString()) }}"></div>
                        <div class="col-md-3"><label class="form-label">Promised for</label><input type="date" name="promised_date" class="form-control" value="{{ old('promised_date', $order->promised_date?->toDateString()) }}"></div>
                        <div class="col-md-3"><label class="form-label">Priority</label>
                            <select name="priority" class="form-select"><option value="normal">Normal</option><option value="rush" @selected(old('priority', $order->priority) === 'rush')>Rush</option></select></div>
                        <div class="col-md-3"><label class="form-label">Order owner</label>
                            <select name="owner_id" class="form-select">@foreach($staff as $s)<option value="{{ $s->id }}" @selected(old('owner_id', $order->owner_id) == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
                    </div>
                </x-cb.card>

                <x-cb.card title="Garments & items" icon="ri-t-shirt-air-line" class="mt-3" :flush="true">
                    @include('orders.partials.lines', ['rows' => old('lines', $rows), 'catalog' => $catalog])
                </x-cb.card>

                <x-cb.card title="Notes" icon="ri-sticky-note-line" class="mt-3">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">For the customer</label><textarea name="customer_notes" class="form-control" rows="3" maxlength="2000">{{ old('customer_notes', $order->customer_notes) }}</textarea></div>
                        <div class="col-md-6"><label class="form-label">Internal (staff only)</label><textarea name="internal_notes" class="form-control" rows="3" maxlength="2000">{{ old('internal_notes', $order->internal_notes) }}</textarea></div>
                    </div>
                </x-cb.card>
            </div>
            <div class="col-xl-3">
                <x-cb.card title="Totals" icon="ri-calculator-line" class="position-sticky" style="top:90px">
                    <div class="d-flex justify-content-between mb-1"><span class="text-muted">Subtotal</span><span>{{ \App\Support\Money::symbol() }}<span class="ol-subtotal">0</span></span></div>
                    <label class="form-label small mt-2 mb-1">Order discount</label>
                    <input type="number" min="0" step="0.01" name="order_discount" class="form-control form-control-sm mb-2" value="{{ old('order_discount', $order->order_discount ?: '') }}" placeholder="0">
                    <div class="d-flex justify-content-between mb-1"><span class="text-muted">All discounts</span><span>−{{ \App\Support\Money::symbol() }}<span class="ol-discounts">0</span></span></div>
                    <div class="d-flex justify-content-between fw-bold fs-5 border-top pt-2 mt-2"><span>Total</span><span>{{ \App\Support\Money::symbol() }}<span class="ol-grand">0</span></span></div>
                    <label class="form-label small mt-3 mb-1">Deposit required</label>
                    <input type="number" min="0" step="0.01" name="deposit_required" class="form-control form-control-sm" value="{{ old('deposit_required', $isNew ? '' : $order->deposit_required) }}" placeholder="Automatic">
                    <div class="form-text">Leave empty for {{ rtrim(rtrim(number_format($depositPercent, 2), '0'), '.') }}% (≈ {{ \App\Support\Money::symbol() }}<span class="ol-deposit-hint" data-percent="{{ $depositPercent }}">0</span>).</div>
                    @if($confirmed)
                        <label class="form-label small mt-3 mb-1">Why is it changing? *</label>
                        <input name="revision_reason" class="form-control form-control-sm" required maxlength="255" value="{{ old('revision_reason') }}" placeholder="e.g. Customer added a matching gele">
                    @endif
                    <div class="d-grid gap-2 mt-3">
                        <button name="action" value="save" class="action-btn justify-content-center py-2"><i class="ri-save-line"></i>{{ $confirmed ? 'Save change' : 'Save draft' }}</button>
                        @unless($confirmed)<button name="action" value="confirm" class="action-btn btn-primary-cb justify-content-center py-2"><i class="ri-checkbox-circle-line"></i>Save & confirm</button>@endunless
                    </div>
                </x-cb.card>
            </div>
        </div>
    </form>

</div>
</div>
</div>
@endsection
