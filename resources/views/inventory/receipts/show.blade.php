@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; $canCost = auth()->user()->can('View costing'); @endphp
    <x-cb.hero :title="$grn->number" icon="ri-inbox-archive-line" :subtitle="($grn->supplier?->name ?? 'Direct purchase') . ' → ' . $grn->branch->name"
               :back="route('goods-receipts.index')" back-label="Goods received">
        <x-slot:pills>
            <span class="cb-meta-pill">{{ $grn->received_at->timezone($tz)->format('d M Y, H:i') }}</span>
            <span class="cb-meta-pill">Received by {{ $grn->receiver?->name }}</span>
            @if($grn->purchaseOrder)<a href="{{ route('purchase-orders.show', $grn->purchaseOrder) }}" class="cb-meta-pill text-white">{{ $grn->purchaseOrder->number }}</a>@endif
            @if($grn->supplier_reference)<span class="cb-meta-pill">Delivery note {{ $grn->supplier_reference }}</span>@endif
        </x-slot:pills>
        <x-slot:actions><button type="button" class="cb-hero-btn" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print</button></x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.flash')

    <x-cb.card title="Items" icon="ri-list-check" :flush="true">
        <div class="table-responsive">
            <table class="cb-table stack mb-0">
                <thead><tr><th>Item</th><th>Roll / lot</th><th class="text-end">Quantity</th>@if($canCost)<th class="text-end">Unit cost</th><th class="text-end">Value</th>@endif</tr></thead>
                <tbody>
                @foreach($grn->lines as $l)
                    <tr>
                        <td data-label="Item"><a href="{{ route('inventory.items.show', $l->item) }}" class="fw-semibold">{{ $l->item->label }}</a><div class="small text-muted">{{ $l->item->code }}</div></td>
                        <td data-label="Roll / lot">{{ $l->lot?->lot_no ?? '—' }}</td>
                        <td data-label="Quantity" class="text-end">{{ $l->item->qty($l->quantity) }}</td>
                        @if($canCost)<td data-label="Unit cost" class="text-end">{{ Money::format($l->unit_cost) }}</td><td data-label="Value" class="text-end">{{ Money::format($l->quantity * $l->unit_cost) }}</td>@endif
                    </tr>
                @endforeach
                </tbody>
                @if($canCost)<tfoot><tr><td colspan="4" class="text-end fw-semibold">Total</td><td class="text-end fw-bold">{{ Money::format($grn->total) }}</td></tr></tfoot>@endif
            </table>
        </div>
        @if($grn->notes)<p class="small text-muted p-3 mb-0">{{ $grn->notes }}</p>@endif
    </x-cb.card>

</div>
</div>
</div>
@endsection
