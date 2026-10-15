@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; $u = auth()->user(); $canCost = $u->can('View costing'); $isCount = $adj->kind === 'count'; @endphp
    <x-cb.hero :title="$adj->number" icon="ri-scales-3-line" :subtitle="$adj->kind_label . ' · ' . $adj->branch->name"
               :back="route('stock-adjustments.index')" back-label="Adjustments & counts">
        <x-slot:pills>
            <span class="status-pill {{ $adj->status_pill }}">{{ $adj->status_label }}</span>
            @if($adj->customer)<span class="cb-meta-pill">{{ $adj->customer->name }}</span>@endif
            @if($adj->reason)<span class="cb-meta-pill">{{ $adj->reason }}</span>@endif
            @if($canCost)<span class="cb-meta-pill">Value change {{ Money::format($adj->value_change) }}</span>@endif
        </x-slot:pills>
        <x-slot:actions>
            @if($adj->isEditable() && $u->can('Adjust stock'))<a href="{{ route('stock-adjustments.edit', $adj) }}" class="cb-hero-btn"><i class="ri-pencil-line me-1"></i>Edit</a>@endif
        </x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.flash')

    @if($adj->status === 'pending_approval')
        <div class="cb-banner warning"><i class="ri-shield-check-line"></i><div>
            Submitted by {{ $adj->submitter?->name }} {{ $adj->submitted_at?->timezone($tz)->diffForHumans() }}.
            @if(abs($adj->value_change) > $limit) Worth more than {{ Money::format($limit) }}, so someone other than {{ $adj->submitter?->name }} must approve it (unless nobody else here can approve). @endif
            Nothing changes in stock until it is approved.
        </div></div>
    @elseif($adj->status === 'rejected')
        <div class="cb-banner danger"><i class="ri-close-circle-line"></i><div>Rejected by {{ $adj->approver?->name }}: {{ $adj->rejection_reason }}</div></div>
    @elseif($adj->status === 'draft')
        <div class="cb-banner info"><i class="ri-draft-line"></i><div>Draft — not posted to stock yet.</div></div>
    @endif

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card title="Lines" icon="ri-list-check" :flush="true">
                <div class="table-responsive">
                    <table class="cb-table stack mb-0">
                        <thead><tr><th>Item</th><th>Roll / lot</th>@if($isCount)<th class="text-end">System</th><th class="text-end">Counted</th>@endif<th class="text-end">Change</th>@if($canCost)<th class="text-end">Value</th>@endif</tr></thead>
                        <tbody>
                        @foreach($adj->lines as $l)
                            @php $cost = $l->unit_cost ?? ($l->item->isCustomerOwned() ? 0 : $l->item->average_cost); @endphp
                            <tr>
                                <td data-label="Item"><a href="{{ route('inventory.items.show', $l->item) }}" class="fw-semibold">{{ $l->item->label }}</a>@if($l->note)<div class="small text-muted">{{ $l->note }}</div>@endif</td>
                                <td data-label="Roll / lot">{{ $l->lot?->label ?? '—' }}</td>
                                @if($isCount)<td data-label="System" class="text-end text-muted">{{ $l->item->qty($l->expected_quantity) }}</td><td data-label="Counted" class="text-end">{{ $l->item->qty($l->counted_quantity) }}</td>@endif
                                <td data-label="Change" class="text-end fw-semibold {{ $l->quantity_change < 0 ? 'text-danger' : ($l->quantity_change > 0 ? 'text-success' : 'text-muted') }}">
                                    {{ $l->quantity_change == 0 ? 'no difference' : ($l->quantity_change > 0 ? '+' : '−') . $l->item->qty(abs($l->quantity_change)) }}</td>
                                @if($canCost)<td data-label="Value" class="text-end">{{ Money::format($l->quantity_change * $cost) }}</td>@endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-cb.card>
        </div>
        <div class="col-xl-4">
            <x-cb.card title="Actions" icon="ri-flashlight-line">
                <div class="d-grid gap-2">
                    @if($adj->status === 'draft' && $u->can('Adjust stock'))
                        <form method="POST" action="{{ route('stock-adjustments.submit', $adj) }}" class="d-grid">@csrf
                            <button class="action-btn btn-primary-cb justify-content-center py-2"><i class="ri-send-plane-line"></i>Submit</button></form>
                        <form method="POST" action="{{ route('stock-adjustments.destroy', $adj) }}" class="d-grid" onsubmit="return confirm('Delete this draft?')">@csrf @method('DELETE')
                            <button class="action-btn justify-content-center py-2 text-danger"><i class="ri-delete-bin-line"></i>Delete draft</button></form>
                    @endif
                    @if($adj->status === 'pending_approval' && $u->can('Approve stock adjustment'))
                        <form method="POST" action="{{ route('stock-adjustments.approve', $adj) }}" class="d-grid">@csrf
                            <button class="action-btn btn-primary-cb justify-content-center py-2"><i class="ri-checkbox-circle-line"></i>Approve & post</button></form>
                        <button type="button" class="action-btn justify-content-center py-2" data-bs-toggle="collapse" data-bs-target="#rejectBox"><i class="ri-close-circle-line"></i>Reject</button>
                        <div class="collapse" id="rejectBox"><form method="POST" action="{{ route('stock-adjustments.reject', $adj) }}">@csrf
                            <input name="reason" class="form-control form-control-sm mb-2" required maxlength="255" placeholder="Why?">
                            <button class="btn btn-sm btn-danger w-100">Reject</button></form></div>
                    @endif
                    @if($adj->status === 'approved')<a href="{{ route('inventory.movements', ['type' => $adj->kind === 'opening' ? 'opening' : null]) }}" class="action-btn justify-content-center py-2"><i class="ri-git-commit-line"></i>Stock ledger</a>@endif
                </div>
            </x-cb.card>
            <x-cb.card title="History" icon="ri-history-line" class="mt-3">
                <dl class="row small mb-0">
                    <dt class="col-5 text-muted fw-normal">Created</dt><dd class="col-7">{{ $adj->created_at->timezone($tz)->format('d M Y, H:i') }}<br>{{ $adj->creator?->name }}</dd>
                    @if($adj->submitted_at)<dt class="col-5 text-muted fw-normal">Submitted</dt><dd class="col-7">{{ $adj->submitted_at->timezone($tz)->format('d M Y, H:i') }}<br>{{ $adj->submitter?->name }}</dd>@endif
                    @if($adj->approved_at)<dt class="col-5 text-muted fw-normal">{{ $adj->status === 'rejected' ? 'Rejected' : 'Approved' }}</dt><dd class="col-7">{{ $adj->approved_at->timezone($tz)->format('d M Y, H:i') }}<br>{{ $adj->approver?->name }}</dd>@endif
                </dl>
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>
@endsection
