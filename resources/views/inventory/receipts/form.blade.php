@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Receive stock" icon="ri-inbox-archive-line" subtitle="For purchases without a purchase order — e.g. bought at the market today. To receive an order, open it and choose “Receive delivery”."
               :back="route('goods-receipts.index')" back-label="Goods received" />
    @include('inventory.partials.flash')

    <form method="POST" action="{{ route('goods-receipts.store') }}">
        @csrf
        <div class="row g-3">
            <div class="col-xl-9">
                <x-cb.card title="Items received" icon="ri-list-check" :flush="true">
                    @include('inventory.partials.lines', ['items' => $pickerItems, 'rows' => old('lines', []), 'branch' => '#branch_id', 'lot' => 'text', 'cost' => auth()->user()->can('View costing'), 'qtyLabel' => 'Quantity'])
                </x-cb.card>
            </div>
            <div class="col-xl-3">
                <x-cb.card title="Delivery" icon="ri-truck-line">
                    <label class="form-label">Into *</label>
                    <select name="branch_id" id="branch_id" class="form-select mb-3" required>
                        @foreach($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id', $branchId) == $b->id)>{{ $b->name }}</option>@endforeach
                    </select>
                    <label class="form-label">Supplier</label>
                    <select name="supplier_id" class="form-select mb-3"><option value="">— Not recorded —</option>
                        @foreach($suppliers as $s)<option value="{{ $s->id }}" @selected(old('supplier_id') == $s->id)>{{ $s->name }}</option>@endforeach
                    </select>
                    <label class="form-label">Receipt / delivery note no.</label>
                    <input name="supplier_reference" class="form-control mb-3" maxlength="60" value="{{ old('supplier_reference') }}">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control mb-3" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
                    <button class="action-btn btn-primary-cb w-100 justify-content-center py-2"><i class="ri-inbox-archive-line"></i>Add to stock</button>
                </x-cb.card>
            </div>
        </div>
    </form>

</div>
</div>
</div>
@endsection
