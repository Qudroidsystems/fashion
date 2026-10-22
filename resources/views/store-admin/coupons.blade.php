@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @php use App\Support\Money; use App\Models\Coupon; $edit = request('edit') ? $coupons->firstWhere('id', (int) request('edit')) : null; @endphp
    <x-cb.hero title="Coupons" icon="ri-coupon-3-line" subtitle="Discount codes shoppers type in their basket." />
    @include('store-admin.nav')
    @include('inventory.partials.flash')
    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card title="Codes" icon="ri-list-check" :count="$coupons->total()" :flush="true">
                @if($coupons->isEmpty())<div class="empty-state"><i class="ri-coupon-3-line"></i><h6>No coupons yet</h6><p>Create one on the right, then share the code.</p></div>
                @else
                <div class="table-responsive"><table class="cb-table stack mb-0">
                    <thead><tr><th>Code</th><th>Gives</th><th>Rules</th><th>Used</th><th>Status</th><th></th></tr></thead>
                    <tbody>@foreach($coupons as $c)
                        <tr>
                            <td data-label="Code"><span class="font-monospace fw-semibold">{{ $c->code }}</span><div class="small text-muted">{{ $c->description }}</div></td>
                            <td data-label="Gives">{{ $c->summary }}@if($c->max_discount)<div class="small text-muted">up to {{ Money::format($c->max_discount) }}</div>@endif</td>
                            <td data-label="Rules" class="small">@if($c->min_subtotal)Min {{ Money::format($c->min_subtotal) }}<br>@endif
                                @if($c->starts_at || $c->ends_at){{ $c->starts_at?->timezone($tz)->format('d M') ?? '…' }} – {{ $c->ends_at?->timezone($tz)->format('d M Y') ?? '…' }}<br>@endif
                                @if($c->usage_per_customer){{ $c->usage_per_customer }} per customer @endif</td>
                            <td data-label="Used">{{ $c->used_count }}{{ $c->usage_limit ? ' / ' . $c->usage_limit : '' }}</td>
                            <td data-label="Status"><span class="status-pill {{ $c->is_active && (! $c->ends_at || $c->ends_at->isFuture()) ? 'st-success' : 'st-muted' }}">{{ $c->is_active ? ($c->ends_at && $c->ends_at->isPast() ? 'Expired' : 'On') : 'Off' }}</span></td>
                            <td class="text-end text-nowrap"><a href="?edit={{ $c->id }}" class="action-btn"><i class="ri-pencil-line"></i></a>
                                <form method="POST" action="{{ route('store.coupons.destroy', $c) }}" class="d-inline" onsubmit="return confirm('Delete {{ $c->code }}?')">@csrf @method('DELETE')<button class="action-btn text-danger"><i class="ri-delete-bin-line"></i></button></form></td>
                        </tr>
                    @endforeach</tbody>
                </table></div>
                @endif
            </x-cb.card>
            <div class="mt-3">{{ $coupons->links() }}</div>
        </div>
        <div class="col-xl-4">
            <x-cb.card :title="$edit ? 'Edit ' . $edit->code : 'New coupon'" icon="ri-add-line">
                <form method="POST" action="{{ $edit ? route('store.coupons.update', $edit) : route('store.coupons.store') }}" class="row g-2">
                    @csrf @if($edit) @method('PUT') @endif
                    <div class="col-12"><label class="form-label">Code *</label><input name="code" class="form-control font-monospace text-uppercase" required maxlength="40" value="{{ old('code', $edit?->code) }}" placeholder="EASTER10"></div>
                    <div class="col-12"><label class="form-label">Description</label><input name="description" class="form-control" maxlength="190" value="{{ old('description', $edit?->description) }}"></div>
                    <div class="col-6"><label class="form-label">Type</label><select name="type" class="form-select">@foreach(Coupon::TYPES as $k => $l)<option value="{{ $k }}" @selected(old('type', $edit?->type) === $k)>{{ $l }}</option>@endforeach</select></div>
                    <div class="col-6"><label class="form-label">Value</label><input type="number" step="0.01" min="0" name="value" class="form-control" value="{{ old('value', $edit?->value) }}" placeholder="10 = 10% or {{ Money::symbol() }}10"></div>
                    <div class="col-6"><label class="form-label">Min. spend</label><input type="number" step="0.01" min="0" name="min_subtotal" class="form-control" value="{{ old('min_subtotal', $edit?->min_subtotal) }}"></div>
                    <div class="col-6"><label class="form-label">Max. discount</label><input type="number" step="0.01" min="0" name="max_discount" class="form-control" value="{{ old('max_discount', $edit?->max_discount) }}"></div>
                    <div class="col-6"><label class="form-label">Total uses</label><input type="number" min="1" name="usage_limit" class="form-control" value="{{ old('usage_limit', $edit?->usage_limit) }}"></div>
                    <div class="col-6"><label class="form-label">Per customer</label><input type="number" min="1" name="usage_per_customer" class="form-control" value="{{ old('usage_per_customer', $edit?->usage_per_customer) }}"></div>
                    <div class="col-6"><label class="form-label">From</label><input type="date" name="starts_at" class="form-control" value="{{ old('starts_at', $edit?->starts_at?->timezone($tz)->toDateString()) }}"></div>
                    <div class="col-6"><label class="form-label">Until</label><input type="date" name="ends_at" class="form-control" value="{{ old('ends_at', $edit?->ends_at?->timezone($tz)->toDateString()) }}"></div>
                    <div class="col-12"><label class="d-flex gap-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $edit?->is_active ?? true))> Active</label></div>
                    <div class="col-12 d-flex gap-2"><button class="action-btn btn-primary-cb flex-grow-1 justify-content-center"><i class="ri-save-line"></i>Save</button>@if($edit)<a href="{{ route('store.coupons.index') }}" class="action-btn">Cancel</a>@endif</div>
                </form>
            </x-cb.card>
        </div>
    </div>
</div></div></div>
@endsection
