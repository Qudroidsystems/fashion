@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; use App\Models\Payment; $p = $payment; $u = auth()->user(); $free = $p->unallocated(); @endphp
    <x-cb.hero :title="'Receipt ' . $p->number" icon="ri-bank-card-line" :subtitle="$p->customer->name . ' · ' . $p->method_label"
               :back="route('payments.index')" back-label="Payments">
        <x-slot:pills>
            <span class="cb-meta-pill">{{ Money::format($p->amount) }}</span>
            <span class="cb-meta-pill">{{ $p->received_at->timezone($tz)->format('d M Y, H:i') }}</span>
            @if($p->status === 'reversed')<span class="status-pill st-danger">Reversed</span>@endif
        </x-slot:pills>
        <x-slot:actions><button type="button" class="cb-hero-btn" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print receipt</button></x-slot:actions>
    </x-cb.hero>
    @include('orders.partials.nav')
    @include('inventory.partials.flash')
    @if($p->status === 'reversed')<div class="cb-banner danger"><i class="ri-arrow-go-back-line"></i><div>Reversed {{ $p->reversed_at->timezone($tz)->format('d M Y') }}: {{ $p->reversal_reason }}</div></div>@endif

    <div class="row g-3">
        <div class="col-xl-7">
            <x-cb.card :flush="true"><div class="p-4">
                <div class="d-flex justify-content-between flex-wrap gap-3 mb-3">
                    <div><div class="fs-4 fw-bold">{{ $house->name }}</div><div class="small text-muted">{{ $house->address }}<br>{{ $house->phone }}</div></div>
                    <div class="text-end"><div class="fs-5 fw-semibold">Receipt {{ $p->number }}</div><div class="small text-muted">{{ $p->received_at->timezone($tz)->format('d M Y, H:i') }}</div></div>
                </div>
                <p class="mb-1">Received from <strong>{{ $p->customer->name }}</strong></p>
                <p class="fs-3 fw-bold mb-1">{{ Money::format($p->amount, null, true) }}</p>
                <p class="small text-muted">{{ $p->method_label }}{{ $p->reference ? ' · ref ' . $p->reference : '' }} · received by {{ $p->receiver?->name }}</p>
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Applied to</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                    @forelse($p->allocations as $a)
                        <tr><td>@if($a->invoice)Invoice {{ $a->invoice->number }}@elseif($a->order)Order {{ $a->order->number }}{{ $a->order->title ? ' — ' . $a->order->title : '' }}@endif
                            @if($a->note)<div class="small text-muted">{{ $a->note }}</div>@endif</td>
                            <td class="text-end {{ $a->amount < 0 ? 'text-danger' : '' }}">{{ Money::format($a->amount) }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="text-muted small">Not applied yet — held as credit.</td></tr>
                    @endforelse
                    </tbody>
                    @if($free > 0)<tfoot><tr><td class="fw-semibold">Customer credit</td><td class="text-end fw-semibold">{{ Money::format($free) }}</td></tr></tfoot>@endif
                </table>
                @if($p->notes)<p class="small mt-3 mb-0">{{ $p->notes }}</p>@endif
            </div></x-cb.card>
        </div>
        <div class="col-xl-5">
            @if($p->status === 'confirmed')
                @if($free > 0 && $openOrders->isNotEmpty() && $u->can('Record payment'))
                    <x-cb.card title="Apply credit" icon="ri-coins-line">
                        @foreach($openOrders as $o)
                            <form method="POST" action="{{ route('payments.allocate', $p) }}" class="d-flex justify-content-between align-items-center py-2 border-bottom">@csrf
                                <input type="hidden" name="order_id" value="{{ $o->id }}">
                                <span>{{ $o->number }}<span class="d-block small text-muted">owes {{ Money::format($o->balance()) }}</span></span>
                                <button class="action-btn btn-primary-cb">Apply {{ Money::format(min($free, $o->balance())) }}</button></form>
                        @endforeach
                    </x-cb.card>
                @endif
                @can('Record payment')
                    <x-cb.card title="Refund" icon="ri-refund-2-line" class="mt-3">
                        <form method="POST" action="{{ route('payments.refund', $p) }}">@csrf
                            <label class="form-label small">Take it from</label>
                            <select name="order_id" class="form-select form-select-sm mb-2">
                                @if($refundableCredit > 0)<option value="">Unapplied credit ({{ Money::format($refundableCredit) }})</option>@endif
                                @foreach($paidOrders as $o)<option value="{{ $o->id }}">Order {{ $o->number }} ({{ $o->status_label }})</option>@endforeach
                            </select>
                            <div class="row g-2 mb-2">
                                <div class="col-6"><input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm" required placeholder="Amount"></div>
                                <div class="col-6"><select name="method" class="form-select form-select-sm">@foreach(Payment::METHODS as $k => $l)<option value="{{ $k }}" @selected($p->method === $k)>{{ $l }}</option>@endforeach</select></div>
                            </div>
                            <input name="reason" class="form-control form-control-sm mb-2" required maxlength="255" placeholder="Reason, e.g. order cancelled">
                            <button class="action-btn w-100 justify-content-center"><i class="ri-refund-2-line"></i>Request refund</button>
                            <p class="small text-muted mt-2 mb-0">Refunds are paid only after approval and can’t exceed what this payment covered.</p>
                        </form>
                    </x-cb.card>
                @endcan
                @can('Approve refund')
                    <x-cb.card title="Reverse" icon="ri-arrow-go-back-line" class="mt-3">
                        <form method="POST" action="{{ route('payments.reverse', $p) }}" onsubmit="return confirm('Reverse this payment? Everything it paid becomes owed again.')">@csrf
                            <input name="reason" class="form-control form-control-sm mb-2" required maxlength="255" placeholder="e.g. Transfer never arrived / cheque bounced">
                            <button class="action-btn w-100 justify-content-center text-danger"><i class="ri-arrow-go-back-line"></i>Reverse payment</button></form>
                    </x-cb.card>
                @endcan
            @endif
            @if($p->refunds->isNotEmpty())
                <x-cb.card title="Refunds" icon="ri-refund-2-line" class="mt-3">
                    @foreach($p->refunds as $r)
                        <div class="d-flex justify-content-between py-2 border-bottom small">
                            <span>{{ Money::format($r->amount) }} · {{ $r->reason }}<span class="d-block text-muted">asked by {{ $r->requester?->name }}@if($r->approver) · {{ $r->status === 'rejected' ? 'rejected' : 'approved' }} by {{ $r->approver->name }}@endif</span></span>
                            <span class="status-pill {{ ['paid' => 'st-success', 'rejected' => 'st-danger'][$r->status] ?? 'st-warning' }}">{{ ['paid' => 'Paid', 'rejected' => 'Rejected'][$r->status] ?? 'Awaiting approval' }}</span>
                        </div>
                    @endforeach
                </x-cb.card>
            @endif
        </div>
    </div>

</div>
</div>
</div>
@endsection
