@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php
        use App\Support\Money;
        $isNew = ! $product->exists;
        $canCost = auth()->user()->can('View costing');
        $rows = old('variants', $product->variants->map(fn ($v) => $v->only(['id', 'size', 'colour', 'sku', 'barcode', 'price', 'cost_price', 'reorder_level', 'is_active']))->all());
        $rows = array_values($rows) ?: [['size' => '', 'colour' => '', 'price' => '', 'is_active' => true]];
        $num = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    @endphp

    <x-cb.hero :title="$isNew ? 'New product' : $product->name" icon="ri-shopping-bag-3-line"
               :subtitle="$isNew ? 'A ready-made item sold from stock.' : $product->code"
               :back="route('products.index')" back-label="Products">
        @unless($isNew)
        <x-slot:actions>
            <form method="POST" action="{{ route('products.archive', $product) }}" class="d-inline">@csrf
                <button class="cb-hero-btn">{!! $product->status === 'archived' ? '<i class="ri-inbox-unarchive-line me-1"></i>Restore' : '<i class="ri-archive-line me-1"></i>Archive' !!}</button>
            </form>
        </x-slot:actions>
        @endunless
    </x-cb.hero>

    @if(session('success'))<div class="cb-banner success"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>@endif
    @if($errors->any())<div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>@endif

    <form method="POST" action="{{ $isNew ? route('products.store') : route('products.update', $product) }}" enctype="multipart/form-data">
        @csrf
        @unless($isNew) @method('PUT') @endunless

        <div class="row g-3">
            <div class="col-xl-8">
                <x-cb.card title="Product" icon="ri-information-line">
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label">Name *</label><input name="name" class="form-control" required maxlength="150" value="{{ old('name', $product->name) }}" placeholder="e.g. Ankara shirt — short sleeve"></div>
                        <div class="col-md-4"><label class="form-label">Code</label>
                            @if($isNew)<input name="code" class="form-control font-monospace" maxlength="30" value="{{ old('code') }}" placeholder="Automatic">
                            @else<input class="form-control font-monospace" value="{{ $product->code }}" disabled>@endif
                        </div>
                        <div class="col-md-6"><label class="form-label">Category</label>
                            <select name="category_id" class="form-select"><option value="">—</option>
                                @foreach($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id', $product->category_id) == $c->id)>{{ $c->name }}</option>@endforeach
                            </select></div>
                        <div class="col-md-6"><label class="form-label">Based on style <span class="small text-muted">(optional)</span></label>
                            <select name="style_id" class="form-select"><option value="">—</option>
                                @foreach($styles as $s)<option value="{{ $s->id }}" @selected(old('style_id', $product->style_id) == $s->id)>{{ $s->name }} ({{ $s->code }})</option>@endforeach
                            </select></div>
                        <div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3" maxlength="5000">{{ old('description', $product->description) }}</textarea></div>
                    </div>
                </x-cb.card>

            </div>

            <div class="col-xl-4">
                <x-cb.card title="Picture" icon="ri-image-line">
                    @if($product->image_url)
                        <img src="{{ $product->image_url }}" alt="" class="rounded-3 mb-2 w-100" style="aspect-ratio:4/5;object-fit:cover">
                        <label class="small d-flex gap-2 mb-2"><input type="checkbox" name="remove_image" value="1"> Remove picture</label>
                    @endif
                    <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp">
                </x-cb.card>

                <x-cb.card title="Settings" icon="ri-settings-3-line" class="mt-3">
                    <label class="d-flex gap-2 mb-2"><input type="hidden" name="show_in_catalogue" value="0"><input type="checkbox" name="show_in_catalogue" value="1" @checked(old('show_in_catalogue', $product->show_in_catalogue))> Show in the customer catalogue</label>
                    <label class="d-flex gap-2 mb-3"><input type="hidden" name="track_stock" value="0"><input type="checkbox" name="track_stock" value="1" @checked(old('track_stock', $product->track_stock))> Track stock quantities</label>
                    <button class="action-btn btn-primary-cb w-100 justify-content-center py-2"><i class="ri-save-line"></i>{{ $isNew ? 'Create product' : 'Save' }}</button>
                </x-cb.card>
            </div>

            <div class="col-12">
                <x-cb.card title="Sizes, colours and prices" icon="ri-price-tag-3-line"  :flush="true">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0" id="variantTable">
                            <thead class="table-light"><tr>
                                <th>Size</th><th>Colour</th><th>Price *</th>@if($canCost)<th>Cost</th>@endif<th>SKU</th><th>Barcode</th><th>Reorder at</th><th title="Can be sold">On</th><th></th>
                            </tr></thead>
                            <tbody>
                            @foreach($rows as $i => $r)
                                <tr class="variant-row">
                                    <td><input type="hidden" name="variants[{{ $i }}][id]" value="{{ $r['id'] ?? '' }}">
                                        <input name="variants[{{ $i }}][size]" class="form-control form-control-sm" style="min-width:70px" maxlength="30" value="{{ $r['size'] ?? '' }}" placeholder="M"></td>
                                    <td><input name="variants[{{ $i }}][colour]" class="form-control form-control-sm" style="min-width:90px" maxlength="40" value="{{ $r['colour'] ?? '' }}" placeholder="Blue"></td>
                                    <td><input type="number" step="0.01" min="0" name="variants[{{ $i }}][price]" class="form-control form-control-sm" style="min-width:100px" required value="{{ $num($r['price'] ?? null) }}"></td>
                                    @if($canCost)<td><input type="number" step="0.01" min="0" name="variants[{{ $i }}][cost_price]" class="form-control form-control-sm" style="min-width:90px" value="{{ $num($r['cost_price'] ?? null) }}"></td>@endif
                                    <td><input name="variants[{{ $i }}][sku]" class="form-control form-control-sm font-monospace" style="min-width:140px" maxlength="60" value="{{ $r['sku'] ?? '' }}" placeholder="Automatic"></td>
                                    <td>
                                        <input name="variants[{{ $i }}][barcode]" class="form-control form-control-sm font-monospace" style="min-width:130px" maxlength="40" value="{{ $r['barcode'] ?? '' }}" placeholder="Scan or leave">
                                        @if(empty($r['barcode']))<label class="small text-muted d-flex gap-1 mt-1"><input type="checkbox" name="variants[{{ $i }}][generate_barcode]" value="1"> generate</label>@endif
                                    </td>
                                    <td><input type="number" step="1" min="0" name="variants[{{ $i }}][reorder_level]" class="form-control form-control-sm" style="min-width:70px" value="{{ isset($r['reorder_level']) && $r['reorder_level'] !== null && $r['reorder_level'] !== '' ? (float) $r['reorder_level'] : '' }}"></td>
                                    <td><input type="hidden" name="variants[{{ $i }}][is_active]" value="0"><input type="checkbox" name="variants[{{ $i }}][is_active]" value="1" @checked($r['is_active'] ?? true)></td>
                                    <td><button type="button" class="btn btn-sm btn-light text-danger remove-variant" title="Remove"><i class="ri-close-line"></i></button></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3 d-flex flex-wrap gap-2 align-items-center">
                        <button type="button" class="action-btn" id="addVariant"><i class="ri-add-line"></i>Add row</button>
                        <span class="small text-muted ms-2">Quick sizes:</span>
                        <input id="quickSizes" class="form-control form-control-sm" style="max-width:220px" placeholder="S, M, L, XL">
                        <button type="button" class="action-btn" id="addSizes"><i class="ri-magic-line"></i>Add</button>
                    </div>
                </x-cb.card>
            </div>
        </div>
    </form>

</div>
</div>
</div>

<script>
(function () {
    const body = document.querySelector('#variantTable tbody');
    let n = body.querySelectorAll('.variant-row').length;
    function addRow(size) {
        const row = body.querySelector('.variant-row').cloneNode(true);
        const price = row.querySelector('input[name$="[price]"]');
        const keepPrice = price.value;
        row.querySelectorAll('input').forEach(i => {
            i.name = i.name.replace(/variants\[\d+\]/, 'variants[' + n + ']');
            if (i.type === 'checkbox') i.checked = i.name.endsWith('[is_active]');
            else if (i.type !== 'hidden' || i.name.endsWith('[id]')) i.value = '';
        });
        if (!row.querySelector('input[name$="[generate_barcode]"]')) {
            const bc = row.querySelector('input[name$="[barcode]"]');
            bc.insertAdjacentHTML('afterend', '<label class="small text-muted d-flex gap-1 mt-1"><input type="checkbox" name="variants[' + n + '][generate_barcode]" value="1"> generate</label>');
        }
        row.querySelector('input[name$="[price]"]').value = keepPrice;
        if (size) row.querySelector('input[name$="[size]"]').value = size;
        body.appendChild(row); n++;
        return row;
    }
    document.getElementById('addVariant').addEventListener('click', () => addRow());
    document.getElementById('addSizes').addEventListener('click', () => {
        const sizes = document.getElementById('quickSizes').value.split(',').map(s => s.trim()).filter(Boolean);
        const first = body.querySelector('.variant-row');
        sizes.forEach((s, i) => {
            if (i === 0 && !first.querySelector('input[name$="[size]"]').value && !first.querySelector('input[name$="[id]"]').value) first.querySelector('input[name$="[size]"]').value = s;
            else addRow(s);
        });
        document.getElementById('quickSizes').value = '';
    });
    body.addEventListener('click', e => {
        const b = e.target.closest('.remove-variant');
        if (!b) return;
        if (body.querySelectorAll('.variant-row').length > 1) b.closest('tr').remove();
    });
})();
</script>
@endsection
