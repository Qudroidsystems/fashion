@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    @php use App\Support\Money; @endphp
    <x-cb.hero title="Cash shifts" icon="ri-safe-2-line" subtitle="Each cashier’s till session: float, takings, cash in and out, and whether the drawer balanced." />
    @include('pos.partials-nav')
    @include('inventory.partials.flash')
    <x-cb.card title="Shifts" icon="ri-list-check" :count="$shifts->total()" :flush="true">
        @if($shifts->isEmpty())
            <div class="empty-state"><i class="ri-safe-2-line"></i><h6>No shifts yet</h6></div>
        @else
        <div class="table-responsive"><table class="cb-table stack mb-0">
            <thead><tr><th>Shift</th><th>Cashier</th><th class="text-end">Sales</th><th class="text-end">Takings</th><th class="text-end">Difference</th><th>Status</th></tr></thead>
            <tbody>
            @foreach($shifts as $s)
                <tr onclick="location.href='{{ route('pos.shifts.show', $s) }}'" style="cursor:pointer">
                    <td data-label="Shift"><a href="{{ route('pos.shifts.show', $s) }}" class="fw-semibold">{{ $s->number }}</a><div class="small text-muted">{{ $s->opened_at->timezone($tz)->format('d M, H:i') }}{{ $s->closed_at ? ' – ' . $s->closed_at->timezone($tz)->format('H:i') : '' }} · {{ $s->branch->name }}</div></td>
                    <td data-label="Cashier">{{ $s->cashier->name }}</td>
                    <td data-label="Sales" class="text-end">{{ $s->sales_count }}</td>
                    <td data-label="Takings" class="text-end">{{ Money::format($s->sales_sum_total ?? 0) }}</td>
                    <td data-label="Difference" class="text-end {{ $s->variance ? ($s->variance < 0 ? 'text-danger' : 'text-warning') : 'text-muted' }}">{{ $s->variance === null ? '—' : ($s->variance == 0 ? 'Balanced' : Money::format($s->variance)) }}</td>
                    <td data-label="Status"><span class="status-pill {{ $s->status === 'open' ? 'st-warning' : 'st-muted' }}">{{ ucfirst($s->status) }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @endif
    </x-cb.card>
    <div class="mt-3">{{ $shifts->links() }}</div>
</div>
</div>
</div>
@endsection
