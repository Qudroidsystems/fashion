@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; use App\Models\Payment; @endphp
    <x-cb.hero title="Payments" icon="ri-bank-card-line" subtitle="Money received, what it paid for, refunds and reversals. Nothing here is ever edited or deleted.">
        <x-slot:actions>@can('Record payment')<a href="{{ route('payments.create') }}" class="cb-hero-btn"><i class="ri-add-line me-1"></i>Record payment</a>@endcan</x-slot:actions>
    </x-cb.hero>
    @include('orders.partials.nav')
    @include('inventory.partials.flash')

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><x-cb.stat label="Received today" :value="Money::format($today->sum('total'))" icon="ri-money-dollar-circle-line" accent="teal" :hint="$today->sum('n') . ' payment(s)'" /></div>
        @foreach($today->take(3) as $t)
            <div class="col-6 col-lg-3"><x-cb.stat :label="Payment::METHODS[$t->method] . ' today'" :value="Money::format($t->total)" icon="ri-wallet-3-line" accent="violet" /></div>
        @endforeach
    </div>

    @if($pending->isNotEmpty())
        <x-cb.card title="Refunds waiting for approval" icon="ri-refund-2-line" class="mb-3" :count="$pending->count()">
            @foreach($pending as $r)
                <div class="d-flex justify-content-between align-items-center gap-2 py-2 border-bottom flex-wrap">
                    <div><span class="fw-semibold">{{ Money::format($r->amount) }}</span> to {{ $r->payment->customer->name }} · {{ $r->reason }}
                        <div class="small text-muted">from {{ $r->payment->number }} · asked by {{ $r->requester?->name }} {{ $r->created_at->diffForHumans() }}</div></div>
                    @can('Approve refund')
                        <div class="d-flex gap-1">
                            <form method="POST" action="{{ route('refunds.approve', $r) }}">@csrf<button class="action-btn btn-primary-cb"><i class="ri-check-line"></i>Approve & pay</button></form>
                            <form method="POST" action="{{ route('refunds.reject', $r) }}" class="d-flex gap-1">@csrf<input name="reason" class="form-control form-control-sm" required placeholder="Reason to reject" style="max-width:180px"><button class="action-btn"><i class="ri-close-line"></i></button></form>
                        </div>
                    @endcan
                </div>
            @endforeach
        </x-cb.card>
    @endif

    <x-cb.card title="Payments" icon="ri-list-check" :count="$payments->total()" :flush="true">
        <form method="GET" class="cb-toolbar">
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ request('q') }}" placeholder="Receipt, bank reference or customer…"></div>
            <select name="method" class="cb-select" onchange="this.form.submit()"><option value="">All methods</option>
                @foreach(Payment::METHODS as $k => $l)<option value="{{ $k }}" @selected(request('method') === $k)>{{ $l }}</option>@endforeach</select>
            <button class="action-btn btn-primary-cb ms-auto"><i class="ri-search-line"></i>Search</button>
        </form>
        @if($payments->isEmpty())
            <div class="empty-state"><i class="ri-bank-card-line"></i><h6>No payments yet</h6></div>
        @else
        <div class="table-responsive"><table class="cb-table stack mb-0">
            <thead><tr><th>Receipt</th><th>Customer</th><th>Method</th><th class="text-end">Amount</th><th class="text-end">Unapplied</th><th>By</th></tr></thead>
            <tbody>
            @foreach($payments as $p)
                @php $free = $p->unallocated(); @endphp
                <tr onclick="location.href='{{ route('payments.show', $p) }}'" style="cursor:pointer" class="{{ $p->status === 'reversed' ? 'opacity-50' : '' }}">
                    <td data-label="Receipt"><a href="{{ route('payments.show', $p) }}" class="fw-semibold">{{ $p->number }}</a><div class="small text-muted">{{ $p->received_at->timezone($tz)->format('d M Y, H:i') }}</div></td>
                    <td data-label="Customer">{{ $p->customer->name }}</td>
                    <td data-label="Method">{{ $p->method_label }}@if($p->reference)<div class="small text-muted">{{ $p->reference }}</div>@endif</td>
                    <td data-label="Amount" class="text-end fw-semibold">{{ Money::format($p->amount) }}@if($p->status === 'reversed')<div><span class="status-pill st-danger">Reversed</span></div>@endif</td>
                    <td data-label="Unapplied" class="text-end {{ $free > 0 ? 'text-warning fw-semibold' : 'text-muted' }}">{{ $free > 0 ? Money::format($free) : '—' }}</td>
                    <td data-label="By" class="small">{{ $p->receiver?->name }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @endif
    </x-cb.card>
    <div class="mt-3">{{ $payments->links() }}</div>

</div>
</div>
</div>
@endsection
