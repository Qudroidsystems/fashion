@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; use App\Models\StockAdjustment; $canCost = auth()->user()->can('View costing'); @endphp
    <x-cb.hero title="Adjustments & counts" icon="ri-scales-3-line"
               :subtitle="'Corrections, wastage, stock counts, opening balances and customer material. Changes worth more than ' . Money::format($limit) . ' need a second person to approve.'">
        <x-slot:actions>
            @can('Adjust stock')
                <div class="dropdown">
                    <button class="cb-hero-btn dropdown-toggle" data-bs-toggle="dropdown" type="button"><i class="ri-add-line me-1"></i>New</button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        @foreach(StockAdjustment::KINDS as $k => $l)<li><a class="dropdown-item" href="{{ route('stock-adjustments.create', ['kind' => $k]) }}">{{ $l }}</a></li>@endforeach
                    </ul>
                </div>
            @endcan
        </x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.nav')
    @include('inventory.partials.flash')

    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach(['open' => 'Open', 'pending_approval' => 'Awaiting approval', 'approved' => 'Posted', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $l)
            <a href="{{ route('stock-adjustments.index', ['status' => $k]) }}" class="action-btn {{ $status === $k ? 'btn-primary-cb' : '' }}">{{ $l }}
                @if(isset($counts[$k]))<span class="badge bg-light text-body ms-1">{{ $counts[$k] }}</span>@endif</a>
        @endforeach
    </div>

    <x-cb.card title="Documents" icon="ri-list-check" :count="$adjustments->total()" :flush="true">
        @if($adjustments->isEmpty())
            <div class="empty-state"><i class="ri-scales-3-line"></i><h6>Nothing here</h6><p>Count your stock, record wastage or enter what you already have as opening stock.</p></div>
        @else
        <div class="table-responsive">
            <table class="cb-table stack mb-0">
                <thead><tr><th>Document</th><th>Type</th><th>Location</th><th>Reason</th><th class="text-end">Lines</th>@if($canCost)<th class="text-end">Value change</th>@endif<th>Status</th></tr></thead>
                <tbody>
                @foreach($adjustments as $a)
                    <tr onclick="location.href='{{ route('stock-adjustments.show', $a) }}'" style="cursor:pointer">
                        <td data-label="Document"><a href="{{ route('stock-adjustments.show', $a) }}" class="fw-semibold">{{ $a->number }}</a><div class="small text-muted">{{ $a->created_at->timezone($tz)->format('d M Y') }} · {{ $a->creator?->name }}</div></td>
                        <td data-label="Type">{{ $a->kind_label }}@if($a->customer)<div class="small text-muted">{{ $a->customer->name }}</div>@endif</td>
                        <td data-label="Location">{{ $a->branch->name }}</td>
                        <td data-label="Reason" class="small">{{ $a->reason ?? '—' }}</td>
                        <td data-label="Lines" class="text-end">{{ $a->lines_count }}</td>
                        @if($canCost)<td data-label="Value" class="text-end {{ $a->value_change < 0 ? 'text-danger' : '' }}">{{ Money::format($a->value_change) }}</td>@endif
                        <td data-label="Status"><span class="status-pill {{ $a->status_pill }}">{{ $a->status_label }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </x-cb.card>
    <div class="mt-3">{{ $adjustments->links() }}</div>

</div>
</div>
</div>
@endsection
