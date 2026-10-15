@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="New invoice" icon="ri-bill-line" subtitle="For charges that are not part of an order — e.g. a quick alteration. Order invoices are created from the order page."
               :back="route('invoices.index')" back-label="Invoices" />
    @include('inventory.partials.flash')

    <form method="POST" action="{{ route('invoices.store') }}">
        @csrf
        <div class="row g-3">
            <div class="col-xl-9">
                <x-cb.card title="Lines" icon="ri-list-check" :flush="true">
                    <div class="table-responsive"><table class="table mb-0" id="invLines">
                        <thead class="table-light"><tr><th>Description</th><th style="width:100px">Qty</th><th style="width:160px">Price</th></tr></thead>
                        <tbody>
                        @foreach(old('lines', [[], [], []]) as $i => $l)
                            <tr><td><input name="lines[{{ $i }}][description]" class="form-control form-control-sm" maxlength="255" value="{{ $l['description'] ?? '' }}"></td>
                                <td><input type="number" step="any" min="0" name="lines[{{ $i }}][quantity]" class="form-control form-control-sm" value="{{ $l['quantity'] ?? 1 }}"></td>
                                <td><input type="number" step="0.01" min="0" name="lines[{{ $i }}][unit_price]" class="form-control form-control-sm" value="{{ $l['unit_price'] ?? '' }}"></td></tr>
                        @endforeach
                        </tbody>
                    </table></div>
                    <p class="small text-muted p-3 mb-0">Empty lines are ignored.</p>
                </x-cb.card>
            </div>
            <div class="col-xl-3">
                <x-cb.card title="Bill to" icon="ri-user-line">
                    <select name="customer_id" class="form-select mb-3" required><option value="">Customer…</option>
                        @foreach($customers as $c)<option value="{{ $c->id }}" @selected(old('customer_id', $customer?->id) == $c->id)>{{ $c->name }} · {{ $c->customer_number }}</option>@endforeach</select>
                    <label class="form-label">Due</label><input type="date" name="due_date" class="form-control mb-3" value="{{ old('due_date', now()->addDays(7)->toDateString()) }}">
                    <label class="form-label">Notes</label><textarea name="notes" class="form-control mb-3" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
                    <button class="action-btn btn-primary-cb w-100 justify-content-center py-2"><i class="ri-save-line"></i>Create draft</button>
                </x-cb.card>
            </div>
        </div>
    </form>

</div>
</div>
</div>
@endsection
