{{-- Ledger rows. Expects $movements, $showItem (bool), $tz optional. --}}
@php $tz ??= \App\Support\Tenancy\CurrentTenant::get()->timezone; $canCost = auth()->user()->can('View costing'); @endphp
@if($movements->isEmpty())
    <p class="text-muted small p-3 mb-0">No movements yet.</p>
@else
<div class="table-responsive">
    <table class="cb-table stack mb-0">
        <thead><tr><th>When</th>@if($showItem)<th>Item</th>@endif<th>What</th><th>Location</th><th class="text-end">Change</th><th class="text-end">Balance</th>@if($canCost)<th class="text-end">Unit cost</th>@endif<th>By</th></tr></thead>
        <tbody>
        @foreach($movements as $m)
            @php $it = $m->item; $url = $m->sourceUrl(); @endphp
            <tr>
                <td data-label="When" class="text-nowrap">{{ $m->occurred_at->timezone($tz)->format('d M Y') }}<div class="small text-muted">{{ $m->occurred_at->timezone($tz)->format('H:i') }}</div></td>
                @if($showItem)<td data-label="Item"><a href="{{ route('inventory.items.show', $it) }}" class="fw-semibold">{{ $it->name }}</a><div class="small text-muted">{{ $it->code }}</div></td>@endif
                <td data-label="What">
                    {{ $m->type_label }}
                    @if($m->reference)· @if($url)<a href="{{ $url }}">{{ $m->reference }}</a>@else{{ $m->reference }}@endif @endif
                    @if($m->reason || $m->customer)<div class="small text-muted">{{ $m->reason }}@if($m->customer){{ $m->reason ? ' · ' : '' }}{{ $m->customer->name }}@endif</div>@endif
                </td>
                <td data-label="Location">{{ $m->branch?->name }}@if($m->lot)<div class="small text-muted">{{ $m->lot->label }}</div>@endif</td>
                <td data-label="Change" class="text-end fw-semibold {{ $m->quantity < 0 ? 'text-danger' : 'text-success' }}">{{ $m->quantity > 0 ? '+' : '−' }}{{ $it->qty(abs($m->quantity)) }}</td>
                <td data-label="Balance" class="text-end text-muted">{{ $it->qty($m->balance_after, false) }}</td>
                @if($canCost)<td data-label="Unit cost" class="text-end text-muted">{{ $m->unit_cost ? \App\Support\Money::format($m->unit_cost) : '—' }}</td>@endif
                <td data-label="By" class="small">{{ $m->user?->name ?? 'System' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif
