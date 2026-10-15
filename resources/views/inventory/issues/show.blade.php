@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; $out = $issue->lines->filter(fn ($l) => $l->net_quantity > 0); @endphp
    <x-cb.hero :title="$issue->number" icon="ri-scissors-cut-line" :subtitle="$issue->job_reference"
               :back="route('material-issues.index')" back-label="Material issues">
        <x-slot:pills>
            <span class="cb-meta-pill">{{ $issue->issued_at->timezone($tz)->format('d M Y, H:i') }}</span>
            <span class="cb-meta-pill">From {{ $issue->branch->name }}</span>
            @if($issue->customer)<a href="{{ route('customers.show', $issue->customer) }}" class="cb-meta-pill text-white"><i class="ri-user-line me-1"></i>{{ $issue->customer->name }}</a>@endif
            @if($issue->issuedTo)<span class="cb-meta-pill">Given to {{ $issue->issuedTo->name }}</span>@endif
            @if($canCost)<span class="cb-meta-pill">Material cost {{ Money::format($issue->total_cost) }}</span>@endif
        </x-slot:pills>
        <x-slot:actions><button type="button" class="cb-hero-btn" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print</button></x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.flash')

    <form method="POST" action="{{ route('material-issues.return', $issue) }}">
        @csrf
        <div class="row g-3">
            <div class="col-xl-8">
                <x-cb.card title="Material" icon="ri-list-check" :flush="true">
                    <div class="table-responsive">
                        <table class="cb-table stack mb-0">
                            <thead><tr><th>Item</th><th>Roll / lot</th><th class="text-end">Issued</th><th class="text-end">Returned</th><th class="text-end">Used</th>@if($canCost)<th class="text-end">Cost</th>@endif
                                @can('Issue material')@if($out->isNotEmpty())<th style="width:150px">Return now</th>@endif @endcan</tr></thead>
                            <tbody>
                            @foreach($issue->lines as $l)
                                <tr>
                                    <td data-label="Item"><a href="{{ route('inventory.items.show', $l->item) }}" class="fw-semibold">{{ $l->item->label }}</a></td>
                                    <td data-label="Roll / lot">{{ $l->lot?->label ?? '—' }}</td>
                                    <td data-label="Issued" class="text-end">{{ $l->item->qty($l->quantity) }}</td>
                                    <td data-label="Returned" class="text-end text-muted">{{ $l->returned_quantity > 0 ? $l->item->qty($l->returned_quantity) : '—' }}</td>
                                    <td data-label="Used" class="text-end fw-semibold">{{ $l->item->qty($l->net_quantity) }}</td>
                                    @if($canCost)<td data-label="Cost" class="text-end">{{ Money::format($l->net_quantity * $l->unit_cost) }}</td>@endif
                                    @can('Issue material')@if($out->isNotEmpty())
                                        <td>@if($l->net_quantity > 0)<div class="input-group input-group-sm"><input type="number" step="any" min="0" max="{{ $l->net_quantity }}" name="returns[{{ $l->id }}]" class="form-control"><span class="input-group-text">{{ $l->item->unit }}</span></div>@endif</td>
                                    @endif @endcan
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-cb.card>
            </div>
            <div class="col-xl-4">
                @can('Issue material')
                    @if($out->isNotEmpty())
                        <x-cb.card title="Return unused material" icon="ri-arrow-go-back-line">
                            <p class="small text-muted">Enter what came back from the workroom in the “Return now” column.</p>
                            <label class="d-flex gap-2 mb-2"><input type="checkbox" name="as_remnant" value="1"> Store as remnants (offcuts)</label>
                            <input name="note" class="form-control form-control-sm mb-3" maxlength="255" placeholder="Note, e.g. 2 pieces, 0.6 yd each">
                            <button class="action-btn btn-primary-cb w-100 justify-content-center py-2"><i class="ri-arrow-go-back-line"></i>Return to stock</button>
                        </x-cb.card>
                    @endif
                @endcan
                @if($issue->notes)<x-cb.card title="Notes" icon="ri-sticky-note-line" class="mt-3"><p class="mb-0 small">{{ $issue->notes }}</p></x-cb.card>@endif
            </div>
        </div>
    </form>

</div>
</div>
</div>
@endsection
