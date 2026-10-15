@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$t->number" icon="ri-arrow-left-right-line" :subtitle="$t->fromBranch->name . ' → ' . $t->toBranch->name"
               :back="route('stock-transfers.index')" back-label="Transfers">
        <x-slot:pills>
            <span class="cb-meta-pill">{{ $t->transferred_at->timezone($tz)->format('d M Y, H:i') }}</span>
            <span class="cb-meta-pill">By {{ $t->creator?->name }}</span>
        </x-slot:pills>
        <x-slot:actions><button type="button" class="cb-hero-btn" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print</button></x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.flash')

    <x-cb.card title="Items moved" icon="ri-list-check" :flush="true">
        <div class="table-responsive">
            <table class="cb-table stack mb-0">
                <thead><tr><th>Item</th><th>Roll / lot</th><th class="text-end">Quantity</th></tr></thead>
                <tbody>
                @foreach($t->lines as $l)
                    <tr>
                        <td data-label="Item"><a href="{{ route('inventory.items.show', $l->item) }}" class="fw-semibold">{{ $l->item->label }}</a><div class="small text-muted">{{ $l->item->code }}</div></td>
                        <td data-label="Roll / lot">{{ $l->lot?->label ?? '—' }}</td>
                        <td data-label="Quantity" class="text-end">{{ $l->item->qty($l->quantity) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if($t->notes)<p class="small text-muted p-3 mb-0">{{ $t->notes }}</p>@endif
    </x-cb.card>

</div>
</div>
</div>
@endsection
