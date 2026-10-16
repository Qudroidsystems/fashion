{{-- Money across the project's orders (FR-PRJ-004, FR-PRJ-007). Never stored; summed from the orders. --}}
@php use App\Support\Money; $main = $s['orders']->where('customer_id', $project->customer_id)->where('status', '!=', 'cancelled')->first(); @endphp
<x-cb.card title="Money" icon="ri-wallet-3-line" class="mt-3">
    <div class="d-flex justify-content-between small"><span class="text-muted">Agreed (contract)</span><span>{{ $m['contract'] > 0 ? Money::format($m['contract']) : '—' }}</span></div>
    <div class="d-flex justify-content-between small"><span class="text-muted">{{ $m['value'] > 0 ? 'Garments (confirmed)' : 'Garments (planned)' }}</span><span>{{ Money::format($m['value'] ?: $m['planned']) }}</span></div>
    @if($m['difference'] !== null && abs($m['difference']) >= 0.01)
        <div class="small {{ $m['difference'] < 0 ? 'text-warning' : 'text-info' }} mt-1"><i class="ri-information-line"></i>
            Garments are {{ Money::format(abs($m['difference'])) }} {{ $m['difference'] < 0 ? 'below' : 'above' }} the contract. Add a charge or discount on the client’s order if the contract stands.</div>
    @endif
    <div class="d-flex justify-content-between small mt-2"><span class="text-muted">Collected</span><span class="text-success">{{ Money::format($m['collected']) }}</span></div>
    <div class="d-flex justify-content-between fw-semibold border-top pt-2 mt-1"><span>Still owed</span><span>{{ Money::format($m['outstanding']) }}</span></div>
    @if($m['deposit'] > 0)<div class="small mt-1 {{ $m['deposit_paid'] ? 'text-success' : 'text-warning' }}"><i class="{{ $m['deposit_paid'] ? 'ri-checkbox-circle-line' : 'ri-time-line' }}"></i> Deposit {{ Money::format($m['deposit']) }} {{ $m['deposit_paid'] ? 'paid' : 'not yet paid' }}</div>@endif

    <div class="small fw-semibold mt-3 mb-1">Orders</div>
    @forelse($s['orders'] as $o)
        <a href="{{ route('orders.show', $o) }}" class="d-flex justify-content-between small py-1 border-bottom text-body {{ $o->status === 'cancelled' ? 'opacity-50' : '' }}">
            <span>{{ $o->number }} <span class="text-muted">· {{ $o->customer_id === $project->customer_id ? 'client' : $o->customer?->name }} · {{ $o->status_label }}</span></span>
            <span>{{ $o->status === 'cancelled' ? '—' : Money::format(max(0, $o->balance())) . ' owed' }}</span></a>
    @empty
        <p class="small text-muted mb-0">Orders appear when garments are added.</p>
    @endforelse
    @if($main && $main->status !== 'draft' && auth()->user()->can('Record payment'))
        <a href="{{ route('payments.create', ['order' => $main->public_id]) }}" class="action-btn w-100 justify-content-center mt-2"><i class="ri-money-dollar-circle-line"></i>Record client payment</a>
    @endif

    <div class="small fw-semibold mt-3 mb-1">Payment schedule</div>
    @forelse($s['schedule'] as $row)
        @php $st = ['paid' => ['Paid', 'st-success'], 'part' => ['Part paid', 'st-warning'], 'overdue' => ['Overdue', 'st-danger'], 'due' => ['Due', 'st-info']][$row['state']]; @endphp
        <div class="d-flex justify-content-between align-items-center small py-1 border-bottom">
            <span>{{ $row['row']->label }} <span class="text-muted">· {{ $row['row']->due_date->format('d M Y') }}</span></span>
            <span class="d-flex gap-2 align-items-center">{{ Money::format($row['row']->amount) }}<span class="status-pill {{ $st[1] }}">{{ $st[0] }}</span>
                @if($canManage && $open)<form method="POST" action="{{ route('projects.schedule.destroy', [$project, $row['row']]) }}">@csrf @method('DELETE')<button class="btn btn-link btn-sm text-muted p-0"><i class="ri-close-line"></i></button></form>@endif</span>
        </div>
    @empty
        <p class="small text-muted mb-1">No schedule. Add when each payment is due.</p>
    @endforelse
    @if($s['schedule']->isNotEmpty())<div class="small text-muted mt-1">Worked out from what the client has paid ({{ Money::format($m['client_paid']) }}), earliest first.</div>@endif
    @if($canManage && $open)
        <form method="POST" action="{{ route('projects.schedule.store', $project) }}" class="row g-1 mt-2">@csrf
            <div class="col-5"><input name="label" class="form-control form-control-sm" required maxlength="80" placeholder="e.g. 2nd payment"></div>
            <div class="col-4"><input type="date" name="due_date" class="form-control form-control-sm" required></div>
            <div class="col-3"><input type="number" name="amount" min="1" step="0.01" class="form-control form-control-sm" required placeholder="{{ Money::symbol() }}"></div>
            <div class="col-12 d-grid"><button class="btn btn-sm btn-light">Add to schedule</button></div>
        </form>
    @endif
</x-cb.card>
