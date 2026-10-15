@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php $canCost = auth()->user()->can('View costing'); @endphp
    <x-cb.hero :title="'Receive ' . $po->number" icon="ri-inbox-archive-line" :subtitle="$po->supplier->name . ' → ' . $po->branch->name"
               :back="route('purchase-orders.show', $po)" back-label="Back to order" />
    @include('inventory.partials.flash')

    <form method="POST" action="{{ route('purchase-orders.receive.store', $po) }}">
        @csrf
        <div class="row g-3">
            <div class="col-xl-9">
                <x-cb.card title="What arrived?" icon="ri-list-check" :flush="true">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light"><tr><th>Item</th><th class="text-end">Outstanding</th><th>Received now</th><th>Roll / lot no.</th>@if($canCost)<th>Unit cost</th>@endif</tr></thead>
                            <tbody>
                            @foreach($po->lines as $i => $l)
                                @continue($l->outstanding <= 0)
                                <tr>
                                    <td><span class="fw-semibold">{{ $l->item->label }}</span><div class="small text-muted">{{ $l->item->code }}</div>
                                        <input type="hidden" name="lines[{{ $i }}][purchase_order_line_id]" value="{{ $l->id }}"></td>
                                    <td class="text-end">{{ $l->item->qty($l->outstanding) }}</td>
                                    <td style="min-width:140px"><div class="input-group input-group-sm">
                                        <input type="number" step="any" min="0" name="lines[{{ $i }}][quantity]" class="form-control" value="{{ old("lines.$i.quantity", $l->outstanding) }}">
                                        <span class="input-group-text">{{ $l->item->unit }}</span></div></td>
                                    <td style="min-width:130px"><input name="lines[{{ $i }}][lot_no]" class="form-control form-control-sm" maxlength="60" value="{{ old("lines.$i.lot_no") }}" @if($l->item->track_lots) required placeholder="Required" @else placeholder="Optional" @endif></td>
                                    @if($canCost)<td style="min-width:120px"><input type="number" step="any" min="0" name="lines[{{ $i }}][unit_cost]" class="form-control form-control-sm" value="{{ old("lines.$i.unit_cost", $l->unit_cost) }}"></td>@endif
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="small text-muted p-3 mb-0">Set a quantity to 0 for anything that didn’t come. The order stays open for the rest.</p>
                </x-cb.card>
            </div>
            <div class="col-xl-3">
                <x-cb.card title="Delivery" icon="ri-truck-line">
                    <label class="form-label">Delivery note / waybill no.</label>
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
