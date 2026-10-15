@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; use App\Models\Invoice; $i = $invoice; $u = auth()->user(); $bal = $i->balance(); @endphp
    <x-cb.hero :title="$i->number ?? 'Draft invoice'" icon="ri-bill-line" :subtitle="Invoice::KINDS[$i->kind] . ' · ' . $i->customer->name"
               :back="$i->order ? route('orders.show', $i->order) : route('invoices.index')" :back-label="$i->order ? 'Order ' . $i->order->number : 'Invoices'">
        <x-slot:pills>
            <span class="status-pill {{ $i->status_pill }}">{{ $i->status_label }}</span>
            @if($i->due_date)<span class="cb-meta-pill">Due {{ $i->due_date->format('d M Y') }}</span>@endif
        </x-slot:pills>
        <x-slot:actions><button type="button" class="cb-hero-btn" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print</button></x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.flash')
    @if($i->status === 'void')<div class="cb-banner danger"><i class="ri-close-circle-line"></i><div>Void: {{ $i->void_reason }}</div></div>@endif
    @if($i->status === 'draft')<div class="cb-banner info"><i class="ri-draft-line"></i><div>Draft — it gets a number and can be paid once issued.</div></div>@endif

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card :flush="true"><div class="p-4">
                <div class="d-flex justify-content-between flex-wrap gap-3 mb-4">
                    <div><div class="fs-4 fw-bold">{{ $house->name }}</div><div class="small text-muted">{{ $house->address }}<br>{{ $house->phone }} {{ $house->email }}</div></div>
                    <div class="text-end"><div class="fs-5 fw-semibold">Invoice {{ $i->number ?? '(draft)' }}</div>
                        <div class="small text-muted">@if($i->issue_date){{ $i->issue_date->format('d M Y') }}@endif @if($i->due_date) · due {{ $i->due_date->format('d M Y') }}@endif</div>
                        @if($i->order)<div class="small text-muted">Order {{ $i->order->number }}</div>@endif</div>
                </div>
                <div class="mb-3"><div class="small text-muted">Bill to</div><div class="fw-semibold">{{ $i->customer->name }}</div><div class="small">{{ $i->customer->phone }}</div></div>
                <table class="table mb-0">
                    <thead class="table-light"><tr><th>Description</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>@foreach($i->lines as $l)<tr><td>{{ $l->description }}</td><td class="text-end">{{ rtrim(rtrim(number_format($l->quantity, 2), '0'), '.') }}</td><td class="text-end">{{ Money::format($l->unit_price) }}</td><td class="text-end">{{ Money::format($l->line_total) }}</td></tr>@endforeach</tbody>
                    <tfoot>
                        <tr><td colspan="3" class="text-end fw-bold">Total</td><td class="text-end fw-bold fs-5">{{ Money::format($i->total) }}</td></tr>
                        @if($i->status === 'issued')
                            <tr><td colspan="3" class="text-end text-muted">Paid</td><td class="text-end">{{ Money::format($i->paid()) }}</td></tr>
                            <tr><td colspan="3" class="text-end fw-semibold">Balance due</td><td class="text-end fw-semibold">{{ Money::format(max(0, $bal)) }}</td></tr>
                        @endif
                    </tfoot>
                </table>
                @if($i->notes)<p class="small mt-3 mb-0">{{ $i->notes }}</p>@endif
                @if($house->setting('bank_details'))<p class="small mt-3 mb-0"><strong>Pay to:</strong> {{ $house->setting('bank_details') }}</p>@endif
            </div></x-cb.card>
        </div>
        <div class="col-xl-4">
            <x-cb.card title="Actions" icon="ri-flashlight-line">
                <div class="d-grid gap-2">
                    @if($i->status === 'draft' && $u->can('Manage invoice'))
                        <form method="POST" action="{{ route('invoices.issue', $i) }}" class="d-grid">@csrf<button class="action-btn btn-primary-cb justify-content-center py-2"><i class="ri-send-plane-line"></i>Issue invoice</button></form>
                        <form method="POST" action="{{ route('invoices.destroy', $i) }}" class="d-grid" onsubmit="return confirm('Delete this draft?')">@csrf @method('DELETE')<button class="action-btn justify-content-center text-danger"><i class="ri-delete-bin-line"></i>Delete draft</button></form>
                    @endif
                    @if($i->status === 'issued' && $bal > 0)
                        @can('Record payment')<a href="{{ route('payments.create', ['invoice' => $i->public_id]) }}" class="action-btn btn-primary-cb justify-content-center py-2"><i class="ri-bank-card-line"></i>Record payment</a>@endcan
                    @endif
                    @if($i->status === 'issued' && $u->can('Manage invoice'))
                        <button type="button" class="action-btn justify-content-center text-danger" data-bs-toggle="collapse" data-bs-target="#voidBox"><i class="ri-close-circle-line"></i>Void</button>
                        <div class="collapse" id="voidBox"><form method="POST" action="{{ route('invoices.void', $i) }}">@csrf
                            <input name="reason" class="form-control form-control-sm mb-2" required maxlength="255" placeholder="Why?">
                            <p class="small text-muted mb-2">Payments on it move to its order (or back to the customer’s credit).</p>
                            <button class="btn btn-sm btn-danger w-100">Void invoice</button></form></div>
                    @endif
                </div>
            </x-cb.card>
            @if($i->allocations->isNotEmpty())
                <x-cb.card title="Payments" icon="ri-bank-card-line" class="mt-3">
                    @foreach($i->allocations as $a)
                        <a href="{{ route('payments.show', $a->payment) }}" class="d-flex justify-content-between small py-1 text-body">
                            <span>{{ $a->payment->number }} · {{ $a->payment->method_label }}@if($a->note)<span class="d-block text-muted">{{ $a->note }}</span>@endif</span>
                            <span class="{{ $a->amount < 0 ? 'text-danger' : '' }}">{{ Money::format($a->amount) }}</span></a>
                    @endforeach
                </x-cb.card>
            @endif
        </div>
    </div>

</div>
</div>
</div>
@endsection
