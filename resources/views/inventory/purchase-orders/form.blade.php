@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php $isNew = ! $po->exists; @endphp
    <x-cb.hero :title="$isNew ? 'New purchase order' : 'Edit ' . $po->number" icon="ri-file-list-2-line"
               subtitle="Saved as a draft until you submit it for approval."
               :back="$isNew ? route('purchase-orders.index') : route('purchase-orders.show', $po)" :back-label="$isNew ? 'Purchase orders' : 'Back to order'" />
    @include('inventory.partials.flash')

    @if($suppliers->isEmpty())
        <div class="cb-banner warning"><i class="ri-error-warning-line"></i><div>Add a supplier first. <a href="{{ route('suppliers.create') }}">New supplier</a></div></div>
    @endif

    <form method="POST" action="{{ $isNew ? route('purchase-orders.store') : route('purchase-orders.update', $po) }}">
        @csrf @unless($isNew) @method('PUT') @endunless
        <div class="row g-3">
            <div class="col-xl-9">
                <x-cb.card title="Order" icon="ri-information-line">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label">Supplier *</label>
                            <select name="supplier_id" class="form-select" required><option value="">Choose…</option>
                                @foreach($suppliers as $s)<option value="{{ $s->id }}" @selected(old('supplier_id', $po->supplier_id) == $s->id)>{{ $s->name }}</option>@endforeach
                            </select></div>
                        <div class="col-md-4"><label class="form-label">Deliver to *</label>
                            <select name="branch_id" id="branch_id" class="form-select" required>
                                @foreach($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id', $po->branch_id) == $b->id)>{{ $b->name }}</option>@endforeach
                            </select></div>
                        <div class="col-md-2"><label class="form-label">Order date *</label><input type="date" name="order_date" class="form-control" required value="{{ old('order_date', $po->order_date?->toDateString()) }}"></div>
                        <div class="col-md-2"><label class="form-label">Expected</label><input type="date" name="expected_date" class="form-control" value="{{ old('expected_date', $po->expected_date?->toDateString()) }}"></div>
                    </div>
                </x-cb.card>

                <x-cb.card title="Items" icon="ri-list-check" class="mt-3" :flush="true">
                    @include('inventory.partials.lines', ['items' => $pickerItems, 'rows' => old('lines', $rows), 'branch' => '#branch_id', 'lot' => null, 'cost' => true, 'qtyLabel' => 'Quantity'])
                </x-cb.card>
            </div>
            <div class="col-xl-3">
                <x-cb.card title="Save" icon="ri-save-line">
                    <label class="form-label">Notes for the supplier</label>
                    <textarea name="notes" class="form-control mb-3" rows="3" maxlength="2000">{{ old('notes', $po->notes) }}</textarea>
                    <div class="d-grid gap-2">
                        <button name="action" value="save" class="action-btn justify-content-center py-2"><i class="ri-save-line"></i>Save draft</button>
                        <button name="action" value="submit" class="action-btn btn-primary-cb justify-content-center py-2"><i class="ri-send-plane-line"></i>Submit for approval</button>
                    </div>
                    <p class="small text-muted mt-2 mb-0">If you can approve orders and this one is within the house limit, submitting approves it straight away.</p>
                </x-cb.card>
            </div>
        </div>
    </form>

</div>
</div>
</div>
@endsection
