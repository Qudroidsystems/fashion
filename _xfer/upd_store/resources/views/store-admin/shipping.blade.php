@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @php use App\Support\Money; $edit = request('edit') ? $zones->firstWhere('id', (int) request('edit')) : null; @endphp
    <x-cb.hero title="Delivery zones" icon="ri-truck-line" subtitle="Delivery fees by state. A zone with no states covers everywhere else. No zones = the default fee in Settings." />
    @include('store-admin.nav')
    @include('inventory.partials.flash')
    <div class="row g-3">
        <div class="col-xl-7">
            <x-cb.card title="Zones" icon="ri-map-2-line" :count="$zones->count()" :flush="true">
                @if($zones->isEmpty())<div class="empty-state"><i class="ri-truck-line"></i><h6>No delivery zones</h6><p>Add one, e.g. “Lagos” ₦3,000 and “Rest of Nigeria” ₦6,500.</p></div>
                @else
                <div class="table-responsive"><table class="cb-table stack mb-0">
                    <thead><tr><th>Zone</th><th>States</th><th>Fee</th><th>Free from</th><th></th></tr></thead>
                    <tbody>@foreach($zones as $z)
                        <tr class="{{ $z->is_active ? '' : 'opacity-50' }}">
                            <td data-label="Zone" class="fw-semibold">{{ $z->name }}<div class="small text-muted">{{ $z->eta }}</div></td>
                            <td data-label="States" class="small">{{ $z->states ? implode(', ', $z->states) : 'Everywhere else' }}</td>
                            <td data-label="Fee">{{ Money::format($z->fee) }}</td>
                            <td data-label="Free from">{{ $z->free_over ? Money::format($z->free_over) : '—' }}</td>
                            <td class="text-end text-nowrap"><a href="?edit={{ $z->id }}" class="action-btn"><i class="ri-pencil-line"></i></a>
                                <form method="POST" action="{{ route('store.shipping.destroy', $z) }}" class="d-inline" onsubmit="return confirm('Remove {{ $z->name }}?')">@csrf @method('DELETE')<button class="action-btn text-danger"><i class="ri-delete-bin-line"></i></button></form></td>
                        </tr>
                    @endforeach</tbody>
                </table></div>
                @endif
            </x-cb.card>
        </div>
        <div class="col-xl-5">
            <x-cb.card :title="$edit ? 'Edit ' . $edit->name : 'New zone'" icon="ri-add-line">
                <form method="POST" action="{{ $edit ? route('store.shipping.update', $edit) : route('store.shipping.store') }}" class="row g-2">
                    @csrf @if($edit) @method('PUT') @endif
                    <div class="col-8"><label class="form-label">Name *</label><input name="name" class="form-control" required maxlength="80" value="{{ old('name', $edit?->name) }}"></div>
                    <div class="col-4"><label class="form-label">Order</label><input type="number" min="0" name="sort_order" class="form-control" value="{{ old('sort_order', $edit?->sort_order ?? 0) }}"></div>
                    <div class="col-12"><label class="form-label">States</label>
                        @if($states)
                            <select name="states[]" class="form-select" multiple size="8">@foreach($states as $s)<option @selected(in_array($s, old('states', $edit?->states ?? []), true))>{{ $s }}</option>@endforeach</select>
                            <div class="small text-muted">Hold Ctrl / ⌘ to pick several. None = everywhere else.</div>
                        @else
                            <textarea name="states_text" class="form-control" rows="3" placeholder="One per line or comma separated. Empty = everywhere else.">{{ old('states_text', implode(', ', $edit?->states ?? [])) }}</textarea>
                        @endif
                    </div>
                    <div class="col-6"><label class="form-label">Fee *</label><input type="number" step="0.01" min="0" name="fee" class="form-control" required value="{{ old('fee', $edit?->fee) }}"></div>
                    <div class="col-6"><label class="form-label">Free from</label><input type="number" step="0.01" min="0" name="free_over" class="form-control" value="{{ old('free_over', $edit?->free_over) }}"></div>
                    <div class="col-12"><label class="form-label">Delivery time</label><input name="eta" class="form-control" maxlength="60" value="{{ old('eta', $edit?->eta) }}" placeholder="1–2 working days"></div>
                    <div class="col-12"><label class="d-flex gap-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $edit?->is_active ?? true))> Active</label></div>
                    <div class="col-12 d-flex gap-2"><button class="action-btn btn-primary-cb flex-grow-1 justify-content-center"><i class="ri-save-line"></i>Save</button>@if($edit)<a href="{{ route('store.shipping.index') }}" class="action-btn">Cancel</a>@endif</div>
                </form>
            </x-cb.card>
        </div>
    </div>
</div></div></div>
@endsection
