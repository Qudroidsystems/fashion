@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    @php use App\Support\Money; use App\Models\SaleTender; $u = auth()->user(); $returnable = $sale->lines->where('kind', 'product')->filter(fn ($l) => $l->returnable > 0); @endphp
    <x-cb.hero :title="$sale->number" icon="ri-receipt-line" :subtitle="($sale->customer?->name ?? 'Walk-in customer') . ' · ' . $sale->branch->name"
               :back="route('pos.sales.index')" back-label="Sales">
        <x-slot:pills>
            <span class="status-pill {{ $sale->status === 'voided' ? 'st-danger' : 'st-success' }}">{{ $sale->status === 'voided' ? 'Voided' : 'Paid' }}</span>
            <span class="cb-meta-pill">{{ $sale->created_at->timezone($tz)->format('d M Y, H:i') }}</span>
            <span class="cb-meta-pill">Cashier {{ $sale->cashier?->name }}</span>
            <a href="{{ route('pos.shifts.show', $sale->shift) }}" class="cb-meta-pill text-white">{{ $sale->shift->number }}</a>
        </x-slot:pills>
        <x-slot:actions><a href="{{ route('pos.sales.receipt', $sale) }}" target="_blank" class="cb-hero-btn"><i class="ri-printer-line me-1"></i>Receipt</a></x-slot:actions>
    </x-cb.hero>
    @include('pos.partials-nav')
    @include('inventory.partials.flash')
    @if($sale->status === 'voided')<div class="cb-banner danger"><i class="ri-close-circle-line"></i><div>Voided by {{ $sale->voider?->name }} {{ $sale->voided_at->timezone($tz)->format('d M, H:i') }}: {{ $sale->void_reason }}</div></div>@endif

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card title="Items" icon="ri-list-check" :flush="true">
                <form method="POST" action="{{ route('pos.sales.return', $sale) }}" id="returnForm">@csrf
                <div class="table-responsive"><table class="cb-table stack mb-0">
                    <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Total</th>@if($sale->returns->isNotEmpty())<th class="text-end">Returned</th>@endif
                        @if($returnable->isNotEmpty() && $sale->status === 'completed' && $u->can('Void sale'))<th style="width:110px">Return</th>@endif</tr></thead>
                    <tbody>
                    @foreach($sale->lines as $l)
                        <tr>
                            <td data-label="Item"><span class="fw-semibold">{{ $l->description }}</span>@if($l->order)<div class="small"><a href="{{ route('orders.show', $l->order) }}">{{ $l->order->number }}</a></div>@endif</td>
                            <td data-label="Qty" class="text-end">{{ rtrim(rtrim(number_format($l->quantity, 2), '0'), '.') }}</td>
                            <td data-label="Price" class="text-end">{{ Money::format($l->unit_price) }}@if($l->discount > 0)<div class="small text-muted">−{{ Money::format($l->discount) }}</div>@endif</td>
                            <td data-label="Total" class="text-end fw-semibold">{{ Money::format($l->line_total) }}</td>
                            @if($sale->returns->isNotEmpty())<td data-label="Returned" class="text-end text-muted">{{ $l->returned_quantity > 0 ? rtrim(rtrim(number_format($l->returned_quantity, 2), '0'), '.') : '—' }}</td>@endif
                            @if($returnable->isNotEmpty() && $sale->status === 'completed' && $u->can('Void sale'))
                                <td>@if($l->returnable > 0)<input type="number" min="0" max="{{ $l->returnable }}" step="1" name="quantities[{{ $l->id }}]" class="form-control form-control-sm" placeholder="0">@endif</td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot>
                        @if($sale->discount_total > 0)<tr><td colspan="3" class="text-end text-muted">Discounts</td><td class="text-end">−{{ Money::format($sale->discount_total) }}</td></tr>@endif
                        <tr><td colspan="3" class="text-end fw-bold">Total</td><td class="text-end fw-bold fs-5">{{ Money::format($sale->total) }}</td></tr>
                    </tfoot>
                </table></div>
                @if($returnable->isNotEmpty() && $sale->status === 'completed' && $u->can('Void sale'))
                    <div class="row g-2 p-3 border-top align-items-end">
                        <div class="col-md-3"><label class="form-label small">Refund by</label><select name="refund_method" class="form-select form-select-sm">
                            <option value="cash">Cash (from my drawer)</option><option value="card">Card</option><option value="bank_transfer">Bank transfer</option><option value="mobile_money">Mobile money</option>
                            @if($sale->customer_id)<option value="store_credit">Store credit</option>@endif</select></div>
                        <div class="col-md-4"><label class="form-label small">Reason</label><input name="reason" class="form-control form-control-sm" required maxlength="255" placeholder="e.g. Wrong size"></div>
                        <div class="col-md-3"><label class="d-flex gap-1 small"><input type="hidden" name="restock" value="0"><input type="checkbox" name="restock" value="1" checked> Put back in stock</label></div>
                        <div class="col-md-2 d-grid"><button class="action-btn justify-content-center"><i class="ri-arrow-go-back-line"></i>Return</button></div>
                    </div>
                @endif
                </form>
            </x-cb.card>
            @if($sale->returns->isNotEmpty())
                <x-cb.card title="Returns" icon="ri-arrow-go-back-line" class="mt-3">
                    @foreach($sale->returns as $r)
                        <div class="d-flex justify-content-between small py-2 border-bottom">
                            <span><span class="fw-semibold">{{ $r->number }}</span> · {{ $r->reason }}{{ $r->restock ? '' : ' · not restocked' }}<span class="d-block text-muted">{{ $r->created_at->timezone($tz)->format('d M, H:i') }} · {{ $r->user?->name }}</span></span>
                            <span>{{ Money::format($r->amount) }} · {{ $r->refund_method === 'store_credit' ? 'store credit' : (SaleTender::METHODS[$r->refund_method] ?? $r->refund_method) }}</span>
                        </div>
                    @endforeach
                </x-cb.card>
            @endif
        </div>
        <div class="col-xl-4">
            <x-cb.card title="Paid with" icon="ri-wallet-3-line">
                @foreach($sale->tenders as $t)
                    <div class="d-flex justify-content-between py-1"><span>{{ $t->method_label }}@if($t->reference)<span class="d-block small text-muted">{{ $t->reference }}</span>@endif</span>
                        <span class="fw-semibold">{{ Money::format($t->received ?? $t->amount) }}</span></div>
                @endforeach
                @if($sale->change_given > 0)<div class="d-flex justify-content-between py-1 border-top text-muted"><span>Change given</span><span>{{ Money::format($sale->change_given) }}</span></div>@endif
            </x-cb.card>
            @if($sale->status === 'completed' && $u->can('Void sale') && $sale->shift->isOpen() && $sale->returns->isEmpty())
                <x-cb.card title="Void" icon="ri-close-circle-line" class="mt-3">
                    <form method="POST" action="{{ route('pos.sales.void', $sale) }}" onsubmit="return confirm('Void this whole sale?')">@csrf
                        <input name="reason" class="form-control form-control-sm mb-2" required maxlength="255" placeholder="Why? e.g. rang up twice">
                        <button class="action-btn w-100 justify-content-center text-danger"><i class="ri-close-circle-line"></i>Void sale</button>
                        <p class="small text-muted mt-2 mb-0">Only while its shift is open. Stock goes back; any order payment is reversed.</p></form>
                </x-cb.card>
            @endif
        </div>
    </div>
</div>
</div>
</div>
@endsection
