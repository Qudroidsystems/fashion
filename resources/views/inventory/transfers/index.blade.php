@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Stock transfers" icon="ri-arrow-left-right-line" subtitle="Moving stock between your shop, workshop and warehouse.">
        <x-slot:actions>@can('Manage inventory')<a href="{{ route('stock-transfers.create') }}" class="cb-hero-btn"><i class="ri-add-line me-1"></i>New transfer</a>@endcan</x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.nav')
    @include('inventory.partials.flash')

    <x-cb.card title="Transfers" icon="ri-list-check" :count="$transfers->total()" :flush="true">
        @if($transfers->isEmpty())
            <div class="empty-state"><i class="ri-arrow-left-right-line"></i><h6>No transfers yet</h6></div>
        @else
        <div class="table-responsive">
            <table class="cb-table stack mb-0">
                <thead><tr><th>Transfer</th><th>From</th><th>To</th><th class="text-end">Items</th><th>By</th></tr></thead>
                <tbody>
                @foreach($transfers as $t)
                    <tr onclick="location.href='{{ route('stock-transfers.show', $t) }}'" style="cursor:pointer">
                        <td data-label="Transfer"><a href="{{ route('stock-transfers.show', $t) }}" class="fw-semibold">{{ $t->number }}</a><div class="small text-muted">{{ $t->transferred_at->timezone($tz)->format('d M Y, H:i') }}</div></td>
                        <td data-label="From">{{ $t->fromBranch->name }}</td>
                        <td data-label="To">{{ $t->toBranch->name }}</td>
                        <td data-label="Items" class="text-end">{{ $t->lines_count }}</td>
                        <td data-label="By" class="small">{{ $t->creator?->name }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </x-cb.card>
    <div class="mt-3">{{ $transfers->links() }}</div>

</div>
</div>
</div>
@endsection
