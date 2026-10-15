@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Transfer stock" icon="ri-arrow-left-right-line" subtitle="The stock leaves one location and arrives at the other in one step."
               :back="route('stock-transfers.index')" back-label="Transfers" />
    @include('inventory.partials.flash')

    @if($branches->count() < 2)
        <div class="cb-banner warning"><i class="ri-error-warning-line"></i><div>You need at least two locations to transfer stock. @can('Manage branch')<a href="{{ route('settings.branches.index') }}">Add a location</a>@endcan</div></div>
    @endif

    <form method="POST" action="{{ route('stock-transfers.store') }}">
        @csrf
        <div class="row g-3">
            <div class="col-xl-9">
                <x-cb.card title="Items" icon="ri-list-check" :flush="true">
                    @include('inventory.partials.lines', ['items' => $pickerItems, 'rows' => old('lines', []), 'branch' => '#from_branch_id', 'lot' => 'select', 'qtyLabel' => 'Quantity'])
                </x-cb.card>
            </div>
            <div class="col-xl-3">
                <x-cb.card title="Route" icon="ri-route-line">
                    <label class="form-label">From *</label>
                    <select name="from_branch_id" id="from_branch_id" class="form-select mb-3" required>
                        @foreach($branches as $b)<option value="{{ $b->id }}" @selected(old('from_branch_id') == $b->id)>{{ $b->name }}</option>@endforeach
                    </select>
                    <label class="form-label">To *</label>
                    <select name="to_branch_id" class="form-select mb-3" required>
                        @foreach($branches as $i => $b)<option value="{{ $b->id }}" @selected(old('to_branch_id', $branches[1]->id ?? null) == $b->id)>{{ $b->name }}</option>@endforeach
                    </select>
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control mb-3" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
                    <button class="action-btn btn-primary-cb w-100 justify-content-center py-2"><i class="ri-arrow-left-right-line"></i>Move stock</button>
                </x-cb.card>
            </div>
        </div>
    </form>

</div>
</div>
</div>
@endsection
