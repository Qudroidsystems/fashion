@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Issue material" icon="ri-scissors-cut-line" subtitle="Hand fabric and trims to the workroom for a job. Stock reserved for the customer is used first."
               :back="route('material-issues.index')" back-label="Material issues" />
    @include('inventory.partials.flash')

    <form method="GET" class="mb-3 d-flex gap-2 align-items-center flex-wrap">
        <label class="small text-muted">For customer</label>
        <select name="customer" class="form-select form-select-sm" style="max-width:320px" onchange="this.form.submit()">
            <option value="">— No customer (house job) —</option>
            @foreach($customers as $c)<option value="{{ $c->public_id }}" @selected($customer?->id === $c->id)>{{ $c->name }} ({{ $c->customer_number }})</option>@endforeach
        </select>
        <span class="small text-muted">Choosing a customer loads what is reserved for them and their own material.</span>
    </form>

    <form method="POST" action="{{ route('material-issues.store') }}">
        @csrf
        <input type="hidden" name="customer_id" value="{{ $customer?->id }}">
        <div class="row g-3">
            <div class="col-xl-9">
                <x-cb.card title="Material" icon="ri-list-check" :flush="true">
                    @include('inventory.partials.lines', ['items' => $pickerItems, 'rows' => old('lines', $rows), 'branch' => '#branch_id', 'lot' => 'select', 'qtyLabel' => 'Quantity'])
                </x-cb.card>
            </div>
            <div class="col-xl-3">
                <x-cb.card title="Job" icon="ri-information-line">
                    <label class="form-label">What is it for? *</label>
                    <input name="job_reference" class="form-control mb-3" required maxlength="120" value="{{ old('job_reference', $customer ? $customer->first_name . '’s ' : '') }}" placeholder="e.g. Wedding gown — bodice">
                    <label class="form-label">From *</label>
                    <select name="branch_id" id="branch_id" class="form-select mb-3" required>
                        @foreach($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id', $branchId) == $b->id)>{{ $b->name }}</option>@endforeach
                    </select>
                    <label class="form-label">Given to</label>
                    <select name="issued_to" class="form-select mb-3"><option value="">—</option>
                        @foreach($staff as $s)<option value="{{ $s->id }}" @selected(old('issued_to') == $s->id)>{{ $s->name }}</option>@endforeach
                    </select>
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control mb-3" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
                    <label class="d-flex gap-2 small mb-3"><input type="checkbox" name="allow_reserved" value="1" @checked(old('allow_reserved'))> Use stock reserved for other jobs if needed</label>
                    <button class="action-btn btn-primary-cb w-100 justify-content-center py-2"><i class="ri-scissors-cut-line"></i>Issue</button>
                </x-cb.card>
            </div>
        </div>
    </form>

</div>
</div>
</div>
@endsection
