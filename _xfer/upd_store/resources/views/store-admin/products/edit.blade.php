@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @php use App\Support\Money; $num = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'); @endphp
    <x-cb.hero :title="$product->name" icon="ri-image-edit-line" :subtitle="$product->code . ' · how it looks in your shop'" :back="route('store.products.index')" back-label="Products">
        <x-slot:pills>
            <span class="status-pill {{ $product->show_in_catalogue && $product->status === 'active' ? 'st-success' : 'st-muted' }}">{{ $product->show_in_catalogue && $product->status === 'active' ? 'In the shop' : 'Hidden' }}</span>
            @if($product->rating_count)<span class="cb-meta-pill"><i class="ri-star-fill me-1"></i>{{ $product->rating_avg }} ({{ $product->rating_count }})</span>@endif
            <span class="cb-meta-pill">{{ $product->sold_count }} sold</span>
        </x-slot:pills>
        <x-slot:actions>
            <a href="{{ $shopUrl }}" target="_blank" rel="noopener" class="cb-hero-btn"><i class="ri-external-link-line me-1"></i>View in shop</a>
            @can('Manage product')<a href="{{ route('products.edit', $product) }}" class="cb-hero-btn"><i class="ri-list-settings-line me-1"></i>Sizes, SKUs & barcodes</a>@endcan
        </x-slot:actions>
    </x-cb.hero>
    @include('store-admin.nav')
    @include('inventory.partials.flash')

    <div class="row g-3">
        <div class="col-xl-5">
            <x-cb.card title="Pictures" icon="ri-image-line" :count="$product->images->count() . ' / ' . $max">
                <p class="small text-muted">The first picture is the cover; the second shows when a shopper hovers. Drag to reorder. Tag a picture with a colour to show it when that colour is picked.</p>
                <div class="row g-2" id="gallery">
                    @foreach($product->images as $img)
                        <div class="col-4" draggable="true" data-id="{{ $img->id }}">
                            <div class="position-relative border rounded-2 overflow-hidden" style="cursor:grab">
                                <img src="{{ $img->url }}" alt="" style="width:100%;aspect-ratio:3/4;object-fit:cover">
                                @if($loop->first)<span class="badge bg-dark position-absolute top-0 start-0 m-1">Cover</span>@endif
                                @if($img->colour)<span class="badge bg-light text-dark position-absolute bottom-0 start-0 m-1">{{ $img->colour }}</span>@endif
                            </div>
                            <div class="d-flex gap-1 mt-1">
                                <form method="POST" action="{{ route('store.products.images.update', [$product, $img]) }}" class="d-flex gap-1 flex-grow-1">@csrf @method('PATCH')
                                    <input name="colour" value="{{ $img->colour }}" placeholder="Colour" class="form-control form-control-sm" list="colourList" maxlength="40" onchange="this.form.submit()">
                                </form>
                                @unless($loop->first)<form method="POST" action="{{ route('store.products.images.update', [$product, $img]) }}">@csrf @method('PATCH')<input type="hidden" name="cover" value="1"><input type="hidden" name="colour" value="{{ $img->colour }}"><button class="btn btn-sm btn-light" title="Make cover"><i class="ri-star-line"></i></button></form>@endunless
                                <form method="POST" action="{{ route('store.products.images.destroy', [$product, $img]) }}" onsubmit="return confirm('Remove this picture?')">@csrf @method('DELETE')<button class="btn btn-sm btn-light text-danger" title="Remove"><i class="ri-delete-bin-line"></i></button></form>
                            </div>
                        </div>
                    @endforeach
                    @if($product->images->isEmpty() && $product->image_url)
                        <div class="col-12"><p class="small text-muted mb-1">The shop uses the product’s single picture until you upload a gallery:</p><img src="{{ $product->image_url }}" class="rounded-2" style="width:120px" alt=""></div>
                    @endif
                </div>
                <datalist id="colourList">@foreach($product->variants->pluck('colour')->filter()->unique() as $c)<option value="{{ $c }}">@endforeach</datalist>
                @if($product->images->count() < $max)
                <form method="POST" action="{{ route('store.products.images.store', $product) }}" enctype="multipart/form-data" class="mt-3 p-3 border rounded-3" style="border-style:dashed!important">@csrf
                    <label class="form-label fw-semibold">Upload pictures</label>
                    <input type="file" name="images[]" multiple accept="image/jpeg,image/png,image/webp" class="form-control mb-2" required>
                    <div class="d-flex gap-2"><input name="colour" class="form-control form-control-sm" placeholder="Colour these show (optional)" list="colourList" maxlength="40">
                        <button class="action-btn btn-primary-cb text-nowrap"><i class="ri-upload-2-line"></i>Upload</button></div>
                    <p class="small text-muted mb-0 mt-2">JPG, PNG or WebP, up to 5 MB each. Portrait (3:4) looks best.</p>
                </form>
                @endif
            </x-cb.card>
        </div>

        <div class="col-xl-7">
            <form method="POST" action="{{ route('store.products.update', $product) }}">
                @csrf @method('PUT')
                <x-cb.card title="Prices" icon="ri-price-tag-3-line" :flush="true">
                    <div class="table-responsive"><table class="table align-middle mb-0">
                        <thead class="table-light"><tr><th>Size / colour</th><th>Price *</th><th title="Shown crossed out when higher than the price">Was</th>@if($canCost)<th>Cost</th><th>Margin</th>@endif<th>Swatch</th><th>Stock</th><th title="Can be sold">On</th></tr></thead>
                        <tbody>
                        @foreach($product->variants as $v)
                            @php $left = $stock[$v->id] ?? null; @endphp
                            <tr>
                                <td style="min-width:150px"><div class="fw-semibold text-nowrap">{{ $v->label }}</div><div class="small text-muted font-monospace text-nowrap">{{ $v->sku }}</div></td>
                                <td><input type="number" step="0.01" min="0" name="variants[{{ $v->id }}][price]" value="{{ $num(old("variants.$v->id.price", $v->price)) }}" class="form-control form-control-sm js-price" style="min-width:100px" required></td>
                                <td><input type="number" step="0.01" min="0" name="variants[{{ $v->id }}][compare_at_price]" value="{{ $num(old("variants.$v->id.compare_at_price", $v->compare_at_price)) }}" class="form-control form-control-sm" style="min-width:100px" placeholder="—"></td>
                                @if($canCost)
                                    <td><input type="number" step="0.01" min="0" name="variants[{{ $v->id }}][cost_price]" value="{{ $num(old("variants.$v->id.cost_price", $v->cost_price)) }}" class="form-control form-control-sm js-cost" style="min-width:90px"></td>
                                    <td class="small js-margin">{{ $v->cost_price && $v->price > 0 ? round(((float) $v->price - (float) $v->cost_price) / (float) $v->price * 100) . '%' : '—' }}</td>
                                @endif
                                <td>@if($v->colour)<input type="color" name="variants[{{ $v->id }}][colour_hex]" value="{{ $v->colour_hex ?: \App\Services\Store\StoreCatalog::guessHex($v->colour) }}" class="form-control form-control-color form-control-sm" title="{{ $v->colour }}">@else<span class="text-muted">—</span>@endif</td>
                                <td><span class="status-pill {{ $left === null ? 'st-muted' : ($left <= 0 ? 'st-danger' : 'st-success') }}">{{ $left === null ? 'not tracked' : $left }}</span></td>
                                <td><input type="hidden" name="variants[{{ $v->id }}][is_active]" value="0"><input type="checkbox" name="variants[{{ $v->id }}][is_active]" value="1" @checked($v->is_active)></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                    <p class="small text-muted p-3 mb-0">Stock is counted at the shop’s branch (Online store › Settings). Receive stock in Inventory.</p>
                </x-cb.card>

                <x-cb.card title="In the shop" icon="ri-store-3-line" class="mt-3">
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label">Name *</label><input name="name" class="form-control" required maxlength="150" value="{{ old('name', $product->name) }}"></div>
                        <div class="col-md-4"><label class="form-label">Category</label><select name="category_id" class="form-select"><option value="">—</option>@foreach($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id', $product->category_id) == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
                        <div class="col-md-8"><label class="form-label">Web address</label><div class="input-group"><span class="input-group-text small">/product/</span><input name="slug" class="form-control font-monospace" required maxlength="170" value="{{ old('slug', $product->slug) }}"></div></div>
                        <div class="col-md-4"><label class="form-label">Badge</label><input name="badge" class="form-control" maxlength="30" value="{{ old('badge', $product->badge) }}" placeholder="New, Limited…"></div>
                        <div class="col-12"><label class="form-label">Description</label><textarea name="description" rows="4" class="form-control" maxlength="5000">{{ old('description', $product->description) }}</textarea></div>
                        <div class="col-12"><label class="form-label">Fabric &amp; care</label><textarea name="details" rows="3" class="form-control" maxlength="5000" placeholder="100% cotton ankara. Hand wash cold…">{{ old('details', $product->details) }}</textarea></div>
                        <div class="col-12"><label class="form-label">Search words <span class="small text-muted">(comma separated)</span></label><input name="tags" class="form-control" maxlength="300" value="{{ old('tags', implode(', ', $product->tags ?? [])) }}" placeholder="ankara, owambe, gown"></div>
                        <div class="col-md-6"><label class="form-label">Search engine title</label><input name="seo_title" class="form-control" maxlength="160" value="{{ old('seo_title', $product->seo_title) }}"></div>
                        <div class="col-md-6"><label class="form-label">Search engine description</label><input name="seo_description" class="form-control" maxlength="300" value="{{ old('seo_description', $product->seo_description) }}"></div>
                        <div class="col-12 d-flex gap-4 flex-wrap">
                            <label class="d-flex gap-2"><input type="hidden" name="show_in_catalogue" value="0"><input type="checkbox" name="show_in_catalogue" value="1" @checked(old('show_in_catalogue', $product->show_in_catalogue))> Show in the shop</label>
                            <label class="d-flex gap-2"><input type="hidden" name="is_featured" value="0"><input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $product->is_featured))> Feature on the home page</label>
                        </div>
                    </div>
                    <button class="action-btn btn-primary-cb w-100 justify-content-center py-2 mt-3"><i class="ri-save-line"></i>Save</button>
                </x-cb.card>
            </form>
        </div>
    </div>
</div></div></div>

<script>
(function () {
    const g = document.getElementById('gallery'); let drag;
    g?.addEventListener('dragstart', e => { drag = e.target.closest('[data-id]'); });
    g?.addEventListener('dragover', e => { e.preventDefault(); const t = e.target.closest('[data-id]'); if (t && drag && t !== drag) { const r = t.getBoundingClientRect(); g.insertBefore(drag, (e.clientX - r.left) > r.width / 2 ? t.nextSibling : t); } });
    g?.addEventListener('drop', e => {
        e.preventDefault();
        const order = [...g.querySelectorAll('[data-id]')].map(x => x.dataset.id);
        fetch(@json(route('store.products.images.sort', $product)), { method: 'POST', headers: { 'X-CSRF-TOKEN': @json(csrf_token()), 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify({ order }) })
            .then(() => location.reload());
    });
    document.querySelectorAll('.js-price, .js-cost').forEach(i => i.addEventListener('input', () => {
        const tr = i.closest('tr'), p = +tr.querySelector('.js-price').value, c = +(tr.querySelector('.js-cost')?.value || 0), m = tr.querySelector('.js-margin');
        if (m) m.textContent = p > 0 && c > 0 ? Math.round((p - c) / p * 100) + '%' : '—';
    }));
})();
</script>
@endsection
