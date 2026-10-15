@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    <x-cb.hero title="Point of sale" icon="ri-shopping-cart-2-line" subtitle="Open your shift with the cash already in the drawer. Every sale, refund and pay-out is counted against it when you close." />
    @include('inventory.partials.flash')
    <div class="row justify-content-center">
        <div class="col-lg-5">
            @can('Open cash shift')
            <x-cb.card title="Open shift" icon="ri-safe-2-line">
                <form method="POST" action="{{ route('pos.shifts.open') }}">
                    @csrf
                    <label class="form-label">Till location</label>
                    <select name="branch_id" class="form-select mb-3">@foreach($branches as $b)<option value="{{ $b->id }}" @selected($b->id == $branchId)>{{ $b->name }}</option>@endforeach</select>
                    <label class="form-label">Cash in the drawer now (float)</label>
                    <div class="input-group input-group-lg mb-3"><span class="input-group-text">{{ \App\Support\Money::symbol() }}</span>
                        <input type="number" step="0.01" min="0" name="opening_float" class="form-control" required value="{{ old('opening_float', 0) }}" autofocus></div>
                    <input name="notes" class="form-control mb-3" maxlength="500" placeholder="Note (optional)">
                    <button class="action-btn btn-primary-cb w-100 justify-content-center py-2"><i class="ri-play-circle-line"></i>Open shift</button>
                </form>
            </x-cb.card>
            @else
                <div class="cb-banner warning"><i class="ri-lock-line"></i><div>You can’t open a cash shift. Ask a manager.</div></div>
            @endcan
            <p class="text-center mt-3"><a href="{{ route('pos.shifts.index') }}">Past shifts</a> · <a href="{{ route('pos.sales.index') }}">Today’s sales</a></p>
        </div>
    </div>
</div>
</div>
</div>
@endsection
