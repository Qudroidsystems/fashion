@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
@php
    use App\Models\CustomerMaterial;
    $m = $material;
    $isNew = ! $m->exists;
@endphp

    <x-cb.hero :title="$isNew ? 'Receive an item' : 'Edit ' . $m->number" icon="ri-archive-drawer-line"
               :subtitle="$isNew ? 'Write down exactly what the customer left, take photos, and put it on a shelf. You’ll print a tag for it next.' : $m->summary"
               :back="$isNew ? ($customer ? route('customers.show', $customer) . '#materials' : route('materials.index')) : route('materials.show', $m)"
               :back-label="$isNew ? ($customer ? $customer->name : 'Customer materials') : 'Back'" />
    @include('inventory.partials.flash')

    <form method="POST" action="{{ $isNew ? route('materials.store') : route('materials.update', $m) }}" enctype="multipart/form-data">
        @csrf @unless($isNew) @method('PUT') @endunless
        <div class="row g-3">
            <div class="col-xl-8">
                <x-cb.card title="What was left with you" icon="ri-t-shirt-line">
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label">Customer *</label>
                            @if($customer)
                                <div class="form-control bg-light">{{ $customer->name }} · {{ $customer->customer_number }}{{ $customer->phone ? ' · ' . $customer->phone : '' }}</div>
                                @if($isNew)<input type="hidden" name="customer_id" value="{{ $customer->id }}">@endif
                            @else
                                <select name="customer_id" class="form-select" required onchange="location.href='{{ route('materials.create') }}?customer=' + this.selectedOptions[0].dataset.pid"><option value="">Choose…</option>
                                    @foreach($customers as $c)<option value="{{ $c->id }}" data-pid="{{ $c->public_id }}" @selected(old('customer_id') == $c->id)>{{ $c->name }} · {{ $c->customer_number }}{{ $c->phone ? ' · ' . $c->phone : '' }}</option>@endforeach
                                </select>
                                <div class="form-text">New customer? <a href="{{ route('customers.create') }}">Add them first</a>.</div>
                            @endif
                        </div>
                        <div class="col-md-4"><label class="form-label">For order</label>
                            <select name="order_id" class="form-select" @disabled(! $customer)><option value="">Not for an order yet</option>
                                @foreach($orders as $o)<option value="{{ $o->id }}" @selected(old('order_id', $m->order_id) == $o->id)>{{ $o->number }}{{ $o->title ? ' · ' . $o->title : '' }}</option>@endforeach</select></div>

                        <div class="col-md-4"><label class="form-label">Kind *</label>
                            <select name="kind" class="form-select">@foreach(CustomerMaterial::KINDS as $k => $l)<option value="{{ $k }}" @selected(old('kind', $m->kind) === $k)>{{ $l }}</option>@endforeach</select></div>
                        <div class="col-md-4"><label class="form-label">Brought</label>
                            <select name="purpose" class="form-select">@foreach(CustomerMaterial::PURPOSES as $k => $l)<option value="{{ $k }}" @selected(old('purpose', $m->purpose) === $k)>{{ $l }}</option>@endforeach</select></div>
                        <div class="col-md-4"><label class="form-label">Colour</label><input name="colour" class="form-control" maxlength="60" value="{{ old('colour', $m->colour) }}" placeholder="e.g. Wine with gold stones"></div>

                        <div class="col-md-8"><label class="form-label">Description *</label><input name="description" class="form-control" required maxlength="160" value="{{ old('description', $m->description) }}" placeholder="e.g. Swiss voile lace, 2 pieces of head tie"></div>
                        <div class="col-md-4"><label class="form-label">Quantity</label>
                            <div class="input-group"><input type="number" name="quantity" step="0.01" min="0" class="form-control" value="{{ old('quantity', $m->quantity) }}">
                                <select name="unit" class="form-select" style="max-width:110px">@foreach(CustomerMaterial::UNITS as $k => $l)<option value="{{ $k }}" @selected(old('unit', $m->unit) === $k)>{{ $l }}</option>@endforeach</select></div></div>

                        <div class="col-12"><label class="form-label">Condition and what came with it</label><textarea name="condition_notes" class="form-control" rows="2" maxlength="2000" placeholder="e.g. Small stain near the hem. Came in a black nylon bag with 6 buttons.">{{ old('condition_notes', $m->condition_notes) }}</textarea>
                            <div class="form-text">Write anything you’d want to point to if there’s a question when it goes back.</div></div>
                    </div>
                </x-cb.card>

                <x-cb.card title="Photos" icon="ri-camera-line" class="mt-3">
                    @if(! $isNew && $m->photos)
                        <div class="d-flex flex-wrap gap-2 mb-2">@foreach($m->photoUrls() as $u)<img src="{{ $u }}" alt="" width="84" height="84" class="rounded object-fit-cover">@endforeach</div>
                    @endif
                    @include('materials.partials.photo-picker', ['max' => \App\Services\Materials\MaterialService::MAX_PHOTOS - count($m->photos ?? [])])
                </x-cb.card>
            </div>
            <div class="col-xl-4">
                <x-cb.card title="Where it is kept" icon="ri-map-pin-line">
                    <label class="form-label">Shelf / bin / with whom</label>
                    <input name="location" class="form-control mb-3" maxlength="120" value="{{ old('location', $m->location) }}" placeholder="e.g. Shelf B2, bag 14">
                    @if($branches->count() > 1)
                        <label class="form-label">Branch</label>
                        <select name="branch_id" class="form-select mb-3">@foreach($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id', $m->branch_id ?? \App\Support\Tenancy\CurrentTenant::membership()?->branch_id) == $b->id)>{{ $b->name }}</option>@endforeach</select>
                    @endif
                    <label class="form-label">Customer will collect by</label>
                    <input type="date" name="collect_by" class="form-control" value="{{ old('collect_by', $m->collect_by?->toDateString()) }}">
                    <div class="form-text mb-3">Optional. If set, reminders start after this date even before the order is finished.</div>
                    @if($isNew)
                        <label class="form-label">Received</label>
                        <input type="datetime-local" name="received_at" class="form-control" value="{{ old('received_at') }}" max="{{ now(\App\Support\Tenancy\CurrentTenant::get()->timezone)->format('Y-m-d\TH:i') }}">
                        <div class="form-text">Leave empty for now.</div>
                    @endif
                    <button class="action-btn btn-primary-cb w-100 justify-content-center py-2 mt-3"><i class="ri-save-line"></i>{{ $isNew ? 'Receive and tag' : 'Save' }}</button>
                </x-cb.card>
            </div>
        </div>
    </form>
</div>
</div>
</div>
@endsection
