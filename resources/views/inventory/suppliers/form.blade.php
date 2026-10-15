@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; $isNew = ! $supplier->exists; $canEdit = auth()->user()->can('Manage supplier'); $tz = \App\Support\Tenancy\CurrentTenant::get()->timezone; @endphp
    <x-cb.hero :title="$isNew ? 'New supplier' : $supplier->name" icon="ri-truck-line" :subtitle="$isNew ? null : $supplier->code"
               :back="route('suppliers.index')" back-label="Suppliers">
        @unless($isNew)
            <x-slot:actions>
                @if($supplier->phone)<a href="tel:{{ $supplier->phone }}" class="cb-hero-btn"><i class="ri-phone-line me-1"></i>Call</a>@endif
                @can('Manage purchase order')<a href="{{ route('purchase-orders.create', ['supplier' => $supplier->public_id]) }}" class="cb-hero-btn"><i class="ri-file-list-2-line me-1"></i>New order</a>@endcan
            </x-slot:actions>
        @endunless
    </x-cb.hero>
    @include('inventory.partials.flash')

    <div class="row g-3">
        <div class="col-xl-{{ $isNew ? '8' : '6' }}">
            <form method="POST" action="{{ $isNew ? route('suppliers.store') : route('suppliers.update', $supplier) }}">
                @csrf @unless($isNew) @method('PUT') @endunless
                <x-cb.card title="Details" icon="ri-information-line">
                    <fieldset @disabled(! $canEdit)>
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label">Name *</label><input name="name" class="form-control" required maxlength="150" value="{{ old('name', $supplier->name) }}" placeholder="e.g. Balogun Market — Mama Titi Lace"></div>
                        <div class="col-md-4"><label class="form-label">Code</label><input name="code" class="form-control font-monospace" maxlength="30" value="{{ old('code', $supplier->code) }}" placeholder="Automatic" @disabled(! $isNew)></div>
                        <div class="col-md-6"><label class="form-label">Contact person</label><input name="contact_person" class="form-control" maxlength="120" value="{{ old('contact_person', $supplier->contact_person) }}"></div>
                        <div class="col-md-6"><label class="form-label">Phone</label><input name="phone" class="form-control" maxlength="30" value="{{ old('phone', $supplier->phone) }}"></div>
                        <div class="col-md-6"><label class="form-label">E-mail</label><input type="email" name="email" class="form-control" maxlength="150" value="{{ old('email', $supplier->email) }}"></div>
                        <div class="col-md-6"><label class="form-label">Payment terms</label>
                            <div class="input-group"><input type="number" min="0" max="365" name="payment_terms_days" class="form-control" value="{{ old('payment_terms_days', $supplier->payment_terms_days) }}" placeholder="0 = on delivery"><span class="input-group-text">days</span></div></div>
                        <div class="col-12"><label class="form-label">Address</label><input name="address" class="form-control" maxlength="255" value="{{ old('address', $supplier->address) }}"></div>
                        <div class="col-12"><label class="form-label">Bank details</label><input name="bank_details" class="form-control" maxlength="255" value="{{ old('bank_details', $supplier->bank_details) }}" placeholder="Bank, account name and number"></div>
                        <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2" maxlength="2000">{{ old('notes', $supplier->notes) }}</textarea></div>
                        @unless($isNew)<div class="col-12"><label class="d-flex gap-2"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $supplier->is_active))> Active</label></div>@endunless
                    </div>
                    @if($canEdit)<button class="action-btn btn-primary-cb mt-3"><i class="ri-save-line"></i>{{ $isNew ? 'Add supplier' : 'Save' }}</button>@endif
                    </fieldset>
                </x-cb.card>
            </form>
        </div>
        @unless($isNew)
        <div class="col-xl-6">
            <x-cb.card title="Purchase orders" icon="ri-file-list-2-line" :flush="true">
                @forelse($orders as $po)
                    <a href="{{ route('purchase-orders.show', $po) }}" class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom text-body">
                        <span><span class="fw-semibold">{{ $po->number }}</span><span class="d-block small text-muted">{{ $po->order_date->format('d M Y') }}</span></span>
                        <span class="text-end">@can('View costing'){{ Money::format($po->total) }}@endcan <span class="status-pill {{ $po->status_pill }} ms-2">{{ $po->status_label }}</span></span>
                    </a>
                @empty
                    <p class="small text-muted p-3 mb-0">No orders yet.</p>
                @endforelse
            </x-cb.card>
            <x-cb.card title="Deliveries" icon="ri-inbox-archive-line" class="mt-3" :flush="true">
                @forelse($receipts as $g)
                    <a href="{{ route('goods-receipts.show', $g) }}" class="d-flex justify-content-between px-3 py-2 border-bottom text-body">
                        <span class="fw-semibold">{{ $g->number }}</span><span class="small text-muted">{{ $g->received_at->timezone($tz)->format('d M Y') }}</span>
                    </a>
                @empty
                    <p class="small text-muted p-3 mb-0">Nothing received yet.</p>
                @endforelse
            </x-cb.card>
        </div>
        @endunless
    </div>

</div>
</div>
</div>
@endsection
