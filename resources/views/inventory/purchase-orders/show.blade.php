@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; $u = auth()->user(); $canCost = $u->can('View costing'); @endphp
    <x-cb.hero :title="$po->number" icon="ri-file-list-2-line" :subtitle="$po->supplier->name . ' → ' . $po->branch->name"
               :back="route('purchase-orders.index')" back-label="Purchase orders">
        <x-slot:pills>
            <span class="status-pill {{ $po->status_pill }}">{{ $po->status_label }}</span>
            <span class="cb-meta-pill">Ordered {{ $po->order_date->format('d M Y') }}</span>
            @if($po->expected_date)<span class="cb-meta-pill">Expected {{ $po->expected_date->format('d M Y') }}</span>@endif
            @if($canCost)<span class="cb-meta-pill">{{ Money::format($po->total) }}</span>@endif
        </x-slot:pills>
        <x-slot:actions>
            @if($po->isReceivable() && $u->can('Manage inventory'))
                <a href="{{ route('purchase-orders.receive', $po) }}" class="cb-hero-btn"><i class="ri-inbox-archive-line me-1"></i>Receive delivery</a>
            @endif
            @if($po->isEditable() && $u->can('Manage purchase order'))
                <a href="{{ route('purchase-orders.edit', $po) }}" class="cb-hero-btn"><i class="ri-pencil-line me-1"></i>Edit</a>
            @endif
            <button type="button" class="cb-hero-btn" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print</button>
        </x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.flash')

    @if($po->status === 'pending_approval')
        <div class="cb-banner warning"><i class="ri-shield-check-line"></i><div>
            Waiting for approval{{ $po->submitted_at ? ' since ' . $po->submitted_at->timezone($tz)->format('d M, H:i') : '' }}.
            @if($po->total > $limit) This order is above the {{ Money::format($limit) }} limit, so someone other than the requester must approve it (unless nobody else here can approve). @endif
        </div></div>
    @endif
    @if($po->cancel_reason)<div class="cb-banner info"><i class="ri-information-line"></i><div>{{ ucfirst($po->status) }}: {{ $po->cancel_reason }}</div></div>@endif

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card title="Items" icon="ri-list-check" :flush="true">
                <div class="table-responsive">
                    <table class="cb-table stack mb-0">
                        <thead><tr><th>Item</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Outstanding</th>@if($canCost)<th class="text-end">Unit cost</th><th class="text-end">Line</th>@endif</tr></thead>
                        <tbody>
                        @foreach($po->lines as $l)
                            <tr>
                                <td data-label="Item"><a href="{{ route('inventory.items.show', $l->item) }}" class="fw-semibold">{{ $l->item->label }}</a><div class="small text-muted">{{ $l->item->code }}</div></td>
                                <td data-label="Ordered" class="text-end">{{ $l->item->qty($l->quantity) }}</td>
                                <td data-label="Received" class="text-end">{{ $l->received_quantity > 0 ? $l->item->qty($l->received_quantity) : '—' }}</td>
                                <td data-label="Outstanding" class="text-end fw-semibold">{{ $l->outstanding > 0 ? $l->item->qty($l->outstanding) : '✓' }}</td>
                                @if($canCost)<td data-label="Unit cost" class="text-end">{{ Money::format($l->unit_cost) }}</td><td data-label="Line" class="text-end">{{ Money::format($l->line_total) }}</td>@endif
                            </tr>
                        @endforeach
                        </tbody>
                        @if($canCost)<tfoot><tr><td colspan="5" class="text-end fw-semibold">Total</td><td class="text-end fw-bold">{{ Money::format($po->total) }}</td></tr></tfoot>@endif
                    </table>
                </div>
            </x-cb.card>

            @if($po->receipts->isNotEmpty())
                <x-cb.card title="Deliveries" icon="ri-inbox-archive-line" class="mt-3" :flush="true">
                    @foreach($po->receipts as $g)
                        <a href="{{ route('goods-receipts.show', $g) }}" class="d-flex justify-content-between px-3 py-2 border-bottom text-body">
                            <span><span class="fw-semibold">{{ $g->number }}</span>@if($g->supplier_reference)<span class="small text-muted"> · delivery note {{ $g->supplier_reference }}</span>@endif</span>
                            <span class="small text-muted">{{ $g->received_at->timezone($tz)->format('d M Y, H:i') }} · {{ $g->receiver?->name }}</span>
                        </a>
                    @endforeach
                </x-cb.card>
            @endif
        </div>

        <div class="col-xl-4">
            <x-cb.card title="Actions" icon="ri-flashlight-line">
                <div class="d-grid gap-2">
                    @if($po->status === 'draft' && $u->can('Manage purchase order'))
                        <form method="POST" action="{{ route('purchase-orders.submit', $po) }}" class="d-grid">@csrf
                            <button class="action-btn btn-primary-cb justify-content-center py-2"><i class="ri-send-plane-line"></i>Submit for approval</button></form>
                    @endif
                    @if($po->status === 'pending_approval' && $u->can('Approve purchase order'))
                        <form method="POST" action="{{ route('purchase-orders.approve', $po) }}" class="d-grid">@csrf
                            <button class="action-btn btn-primary-cb justify-content-center py-2"><i class="ri-checkbox-circle-line"></i>Approve</button></form>
                    @endif
                    @if(in_array($po->status, ['pending_approval', 'approved']) && $po->receipts->isEmpty() && $u->can('Manage purchase order'))
                        <form method="POST" action="{{ route('purchase-orders.reopen', $po) }}" class="d-grid">@csrf
                            <button class="action-btn justify-content-center py-2"><i class="ri-arrow-go-back-line"></i>Back to draft</button></form>
                    @endif
                    @if(! in_array($po->status, ['received', 'cancelled', 'closed']) && $u->can('Manage purchase order'))
                        <button type="button" class="action-btn justify-content-center py-2 text-danger" data-bs-toggle="collapse" data-bs-target="#cancelBox">
                            <i class="ri-close-circle-line"></i>{{ $po->status === 'partially_received' ? 'Close (nothing more coming)' : 'Cancel order' }}</button>
                        <div class="collapse" id="cancelBox">
                            <form method="POST" action="{{ route('purchase-orders.cancel', $po) }}">@csrf
                                <input name="reason" class="form-control form-control-sm mb-2" required maxlength="255" placeholder="Reason">
                                <button class="btn btn-sm btn-danger w-100">Confirm</button></form>
                        </div>
                    @endif
                </div>
            </x-cb.card>
            <x-cb.card title="History" icon="ri-history-line" class="mt-3">
                <dl class="row small mb-0">
                    <dt class="col-5 text-muted fw-normal">Created</dt><dd class="col-7">{{ $po->created_at->timezone($tz)->format('d M Y, H:i') }}<br>{{ $po->creator?->name }}</dd>
                    @if($po->approved_at)<dt class="col-5 text-muted fw-normal">Approved</dt><dd class="col-7">{{ $po->approved_at->timezone($tz)->format('d M Y, H:i') }}<br>{{ $po->approver?->name }}</dd>@endif
                    @if($po->notes)<dt class="col-5 text-muted fw-normal">Notes</dt><dd class="col-7">{{ $po->notes }}</dd>@endif
                    <dt class="col-5 text-muted fw-normal">Supplier</dt><dd class="col-7"><a href="{{ route('suppliers.edit', $po->supplier) }}">{{ $po->supplier->name }}</a>@if($po->supplier->phone)<br>{{ $po->supplier->phone }}@endif</dd>
                </dl>
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>
@endsection
