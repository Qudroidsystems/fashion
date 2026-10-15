@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; @endphp
    <x-cb.hero title="Material issues" icon="ri-scissors-cut-line" subtitle="Fabric and trims handed to the workroom, linked to the customer and job they were used for.">
        <x-slot:actions>@can('Issue material')<a href="{{ route('material-issues.create') }}" class="cb-hero-btn"><i class="ri-add-line me-1"></i>Issue material</a>@endcan</x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.nav')
    @include('inventory.partials.flash')

    <x-cb.card title="Issues" icon="ri-list-check" :count="$issues->total()" :flush="true">
        <form method="GET" class="cb-toolbar">
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ request('q') }}" placeholder="Number, job or customer…"></div>
            <button class="action-btn btn-primary-cb ms-auto"><i class="ri-search-line"></i>Search</button>
        </form>
        @if($issues->isEmpty())
            <div class="empty-state"><i class="ri-scissors-cut-line"></i><h6>No material issued yet</h6></div>
        @else
        <div class="table-responsive">
            <table class="cb-table stack mb-0">
                <thead><tr><th>Issue</th><th>Job</th><th>Customer</th><th>Given to</th><th class="text-end">Items</th>@if($canCost)<th class="text-end">Material cost</th>@endif</tr></thead>
                <tbody>
                @foreach($issues as $m)
                    <tr onclick="location.href='{{ route('material-issues.show', $m) }}'" style="cursor:pointer">
                        <td data-label="Issue"><a href="{{ route('material-issues.show', $m) }}" class="fw-semibold">{{ $m->number }}</a><div class="small text-muted">{{ $m->issued_at->timezone($tz)->format('d M Y, H:i') }} · {{ $m->branch->name }}</div></td>
                        <td data-label="Job">{{ $m->job_reference }}</td>
                        <td data-label="Customer">{{ $m->customer?->name ?? '—' }}</td>
                        <td data-label="Given to">{{ $m->issuedTo?->name ?? '—' }}</td>
                        <td data-label="Items" class="text-end">{{ $m->lines_count }}</td>
                        @if($canCost)<td data-label="Cost" class="text-end">{{ Money::format($m->total_cost) }}</td>@endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </x-cb.card>
    <div class="mt-3">{{ $issues->links() }}</div>

</div>
</div>
</div>
@endsection
