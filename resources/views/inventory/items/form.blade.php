@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Models\InventoryItem; $isNew = ! $item->exists; $canCost = auth()->user()->can('View costing'); @endphp

    <x-cb.hero :title="$isNew ? 'New stock item' : $item->name" icon="ri-archive-line"
               :subtitle="$isNew ? 'Something you keep in stock: fabric, trims, consumables, equipment or a customer’s own material.' : $item->code . ' · ' . $item->type_label"
               :back="$isNew ? route('inventory.index') : route('inventory.items.show', $item)" :back-label="$isNew ? 'Stock' : 'Back to item'" />

    @include('inventory.partials.flash')

    <form method="POST" action="{{ $isNew ? route('inventory.items.store') : route('inventory.items.update', $item) }}">
        @csrf @unless($isNew) @method('PUT') @endunless
        <div class="row g-3">
            <div class="col-xl-8">
                <x-cb.card title="Item" icon="ri-information-line">
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label">Name *</label>
                            <input name="name" class="form-control" required maxlength="150" value="{{ old('name', $item->name) }}" placeholder="e.g. French lace — champagne"></div>
                        <div class="col-md-4"><label class="form-label">Code</label>
                            <input name="code" class="form-control font-monospace" maxlength="60" value="{{ old('code', $item->code) }}" placeholder="Automatic"></div>
                        <div class="col-md-4"><label class="form-label">Type *</label>
                            <select name="type" id="itemType" class="form-select" required @disabled($hasStock && $item->type === 'customer_material')>
                                @foreach(InventoryItem::TYPES as $k => $l)
                                    @continue($k === 'product' && $item->type !== 'product')
                                    <option value="{{ $k }}" @selected(old('type', $item->type) === $k)>{{ $l }}</option>
                                @endforeach
                            </select>
                            @if($hasStock && $item->type === 'customer_material')<input type="hidden" name="type" value="customer_material">@endif
                        </div>
                        <div class="col-md-4"><label class="form-label">Category</label>
                            <input name="category" class="form-control" maxlength="80" list="catList" value="{{ old('category', $item->category) }}" placeholder="e.g. Lace, Ankara, Zips">
                            <datalist id="catList">@foreach($categories as $c)<option value="{{ $c }}">@endforeach</datalist></div>
                        <div class="col-md-4"><label class="form-label">Unit *</label>
                            <select name="unit" id="itemUnit" class="form-select" required @disabled($hasStock)>
                                @foreach(InventoryItem::UNITS as $u => $d)<option value="{{ $u }}" data-decimals="{{ $d }}" @selected(old('unit', $item->unit) === $u)>{{ $u }}</option>@endforeach
                            </select>
                            @if($hasStock)<input type="hidden" name="unit" value="{{ $item->unit }}"><div class="form-text">Fixed once stock has moved.</div>@endif
                        </div>
                        <div class="col-md-4 customer-only"><label class="form-label">Belongs to *</label>
                            <select name="customer_id" class="form-select">
                                <option value="">Choose customer…</option>
                                @foreach($customers as $c)<option value="{{ $c->id }}" @selected(old('customer_id', $item->customer_id) == $c->id)>{{ $c->name }} ({{ $c->customer_number }})</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-4"><label class="form-label">Colour</label><input name="colour" class="form-control" maxlength="60" value="{{ old('colour', $item->colour) }}"></div>
                        <div class="col-md-4"><label class="form-label">Width</label><input name="width" class="form-control" maxlength="30" value="{{ old('width', $item->width) }}" placeholder='e.g. 58"'></div>
                        <div class="col-md-4"><label class="form-label">Barcode</label><input name="barcode" class="form-control font-monospace" maxlength="40" value="{{ old('barcode', $item->barcode) }}" placeholder="Scan or type"></div>
                        <div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2" maxlength="2000">{{ old('description', $item->description) }}</textarea></div>
                    </div>
                </x-cb.card>
            </div>

            <div class="col-xl-4">
                <x-cb.card title="Stock rules" icon="ri-settings-3-line">
                    <div class="row g-3">
                        <div class="col-6"><label class="form-label">Reorder at</label>
                            <div class="input-group"><input type="number" step="any" min="0" name="reorder_level" class="form-control" value="{{ old('reorder_level', $item->reorder_level) }}"><span class="input-group-text unit-label">{{ $item->unit }}</span></div></div>
                        <div class="col-6"><label class="form-label">Reorder qty</label>
                            <div class="input-group"><input type="number" step="any" min="0" name="reorder_quantity" class="form-control" value="{{ old('reorder_quantity', $item->reorder_quantity) }}"><span class="input-group-text unit-label">{{ $item->unit }}</span></div></div>
                        <div class="col-6"><label class="form-label">Decimals</label>
                            <select name="decimals" id="itemDecimals" class="form-select" @disabled($hasStock)>
                                @foreach([0 => 'Whole (12)', 1 => '12.5', 2 => '12.25', 3 => '12.125'] as $d => $l)<option value="{{ $d }}" @selected((int) old('decimals', $item->decimals ?? 2) === $d)>{{ $l }}</option>@endforeach
                            </select>
                            @if($hasStock)<input type="hidden" name="decimals" value="{{ $item->decimals }}">@endif
                        </div>
                        <div class="col-6 not-customer"><label class="form-label">Sale price</label><input type="number" step="0.01" min="0" name="sale_price" class="form-control" value="{{ old('sale_price', $item->sale_price) }}" placeholder="If sold"></div>
                        @if($isNew && $canCost)
                            <div class="col-12 not-customer"><label class="form-label">Usual cost per unit</label>
                                <input type="number" step="any" min="0" name="opening_cost" class="form-control" value="{{ old('opening_cost') }}" placeholder="Updated automatically from receipts">
                            </div>
                        @endif
                        <div class="col-12 not-customer"><label class="form-label">Preferred supplier</label>
                            <select name="preferred_supplier_id" class="form-select"><option value="">—</option>
                                @foreach($suppliers as $s)<option value="{{ $s->id }}" @selected(old('preferred_supplier_id', $item->preferred_supplier_id) == $s->id)>{{ $s->name }}</option>@endforeach
                            </select></div>
                        <div class="col-12">
                            <label class="d-flex gap-2"><input type="checkbox" name="track_lots" value="1" @checked(old('track_lots', $item->track_lots))> Track rolls / dye lots</label>
                            <div class="form-text">For fabric where each roll or dye lot differs. You’ll pick the roll when issuing.</div>
                        </div>
                        @unless($isNew)
                            <div class="col-12"><label class="d-flex gap-2"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $item->is_active))> Active</label></div>
                        @endunless
                    </div>
                    <button class="action-btn btn-primary-cb w-100 justify-content-center py-2 mt-3"><i class="ri-save-line"></i>{{ $isNew ? 'Add item' : 'Save' }}</button>
                </x-cb.card>
            </div>
        </div>
    </form>

</div>
</div>
</div>
<script>
(function () {
    const type = document.getElementById('itemType'), unit = document.getElementById('itemUnit'), dec = document.getElementById('itemDecimals');
    function sync() {
        const cust = type.value === 'customer_material';
        document.querySelectorAll('.customer-only').forEach(e => e.style.display = cust ? '' : 'none');
        document.querySelectorAll('.not-customer').forEach(e => e.style.display = cust ? 'none' : '');
        document.querySelectorAll('.unit-label').forEach(e => e.textContent = unit.value);
    }
    type.addEventListener('change', sync);
    unit.addEventListener('change', () => { if (!dec.disabled) dec.value = unit.selectedOptions[0].dataset.decimals; sync(); });
    sync();
})();
</script>
@endsection
