@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php
        use App\Support\Money;
        $isNew = ! $style->exists;
        $canPublish = auth()->user()->can('Publish style');
        $canCost = auth()->user()->can('View costing');
        $options = old('price_options', $draft->price_options ?: []);
        $options = array_values($options) ?: [['label' => '', 'amount' => '']];
        $linkedIds = array_map('intval', old('templates', $linked));
    @endphp

    <x-cb.hero :title="$isNew ? 'New style' : $style->name" icon="ri-t-shirt-air-line"
               :subtitle="$isNew ? 'Saved as a draft. Publish it when it is ready to be ordered.' : $style->code . ' · editing draft v' . $draft->version_no"
               :back="$isNew ? route('styles.index') : route('styles.show', $style)" :back-label="$isNew ? 'Styles' : 'Back to style'">
        @unless($isNew)
        <x-slot:pills>
            @if($style->current_version_id)<span class="cb-meta-pill">Live: v{{ $style->currentVersion?->version_no }} — customers and orders keep using it until you publish</span>
            @else<span class="cb-meta-pill">Not published yet</span>@endif
        </x-slot:pills>
        @endunless
    </x-cb.hero>

    @if(session('success'))<div class="cb-banner success"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>@endif
    @if($errors->any())<div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>@endif

    <form method="POST" action="{{ $isNew ? route('styles.store') : route('styles.update', $style) }}" enctype="multipart/form-data">
        @csrf
        @unless($isNew) @method('PUT') @endunless

        <div class="row g-3">
            <div class="col-xl-8">
                <x-cb.card title="About the style" icon="ri-information-line">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Name *</label>
                            <input name="name" class="form-control" required maxlength="120" value="{{ old('name', $style->name) }}" placeholder="e.g. Mermaid lace gown">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Code</label>
                            @if($isNew)
                                <input name="code" class="form-control font-monospace" maxlength="30" value="{{ old('code') }}" placeholder="Automatic">
                            @else
                                <input class="form-control font-monospace" value="{{ $style->code }}" disabled>
                            @endif
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Category</label>
                            <select name="category_id" class="form-select">
                                <option value="">—</option>
                                @foreach($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id', $style->category_id) == $c->id)>{{ $c->name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">For</label>
                            <select name="audience" class="form-select">
                                @foreach(\App\Models\FashionStyle::AUDIENCES as $k => $l)<option value="{{ $k }}" @selected(old('audience', $style->audience) === $k)>{{ $l }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description <span class="small text-muted">(customers see this)</span></label>
                            <textarea name="description" class="form-control" rows="3" maxlength="5000">{{ old('description', $draft->description) }}</textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Technical notes <span class="small text-muted">(workroom only)</span></label>
                            <textarea name="technical_notes" class="form-control" rows="4" maxlength="5000" placeholder="Construction, lining, finishing, special techniques…">{{ old('technical_notes', $draft->technical_notes) }}</textarea>
                        </div>
                    </div>
                </x-cb.card>

                <x-cb.card title="Pictures" icon="ri-image-line" class="mt-3" :count="$draft->images->count()">
                    @if($draft->images->isNotEmpty())
                        <div class="row g-2 mb-3">
                            @foreach($draft->images as $i => $img)
                                <div class="col-4 col-md-3 col-xl-2">
                                    <div class="position-relative rounded-3 overflow-hidden" style="aspect-ratio:4/5;background:#f3f5f9">
                                        <img src="{{ $img->url }}" alt="" style="width:100%;height:100%;object-fit:cover">
                                        @if($i === 0)<span class="status-pill st-success position-absolute" style="top:6px;left:6px">Cover</span>@endif
                                        <div class="position-absolute d-flex gap-1" style="bottom:6px;right:6px">
                                            @if($i > 0)<button type="submit" form="cover-{{ $img->id }}" class="btn btn-sm btn-light py-0 px-1" title="Make cover"><i class="ri-star-line"></i></button>@endif
                                            <button type="submit" form="del-{{ $img->id }}" class="btn btn-sm btn-light py-0 px-1 text-danger" title="Remove"><i class="ri-delete-bin-line"></i></button>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <input type="file" name="images[]" class="form-control" accept="image/jpeg,image/png,image/webp" multiple>
                    <div class="form-text">JPG, PNG or WebP, up to 4 MB each, {{ \App\Services\Catalogue\StyleService::MAX_IMAGES }} pictures at most. The first picture is the cover.</div>
                </x-cb.card>

                <x-cb.card title="Price and add-ons" icon="ri-money-dollar-circle-line" class="mt-3">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Starting price</label>
                            <div class="input-group"><span class="input-group-text">{{ Money::symbol() }}</span>
                                <input type="number" step="0.01" min="0" name="base_price" class="form-control" value="{{ old('base_price', $draft->base_price !== null ? (float) $draft->base_price : '') }}" placeholder="Leave empty for 'on request'"></div>
                        </div>
                        @if($canCost)
                        <div class="col-md-4">
                            <label class="form-label">Estimated cost <span class="small text-muted">(private)</span></label>
                            <div class="input-group"><span class="input-group-text">{{ Money::symbol() }}</span>
                                <input type="number" step="0.01" min="0" name="estimated_cost" class="form-control" value="{{ old('estimated_cost', $draft->estimated_cost !== null ? (float) $draft->estimated_cost : '') }}"></div>
                        </div>
                        @endif
                        <div class="col-md-4">
                            <label class="form-label">Lead time</label>
                            <div class="input-group"><input type="number" min="0" max="365" name="lead_time_days" class="form-control" value="{{ old('lead_time_days', $draft->lead_time_days) }}"><span class="input-group-text">days</span></div>
                        </div>
                    </div>

                    <label class="form-label mt-3">Optional add-ons <span class="small text-muted">(e.g. lining, hand beading, extra length)</span></label>
                    <div id="optionRows">
                        @foreach($options as $i => $o)
                            <div class="row g-2 mb-2 option-row">
                                <div class="col-7"><input name="price_options[{{ $i }}][label]" class="form-control" maxlength="80" value="{{ $o['label'] ?? '' }}" placeholder="Add-on"></div>
                                <div class="col-4"><div class="input-group"><span class="input-group-text">+{{ Money::symbol() }}</span><input type="number" step="0.01" min="0" name="price_options[{{ $i }}][amount]" class="form-control" value="{{ $o['amount'] ?? '' }}"></div></div>
                                <div class="col-1 d-grid"><button type="button" class="btn btn-light text-danger remove-option" title="Remove"><i class="ri-close-line"></i></button></div>
                            </div>
                        @endforeach
                    </div>
                    <button type="button" class="action-btn" id="addOption"><i class="ri-add-line"></i>Add-on</button>
                </x-cb.card>
            </div>

            <div class="col-xl-4">
                <x-cb.card title="Measurements needed" icon="ri-ruler-2-line">
                    @forelse($templates as $t)
                        <label class="d-flex align-items-center gap-2 py-1">
                            <input type="checkbox" name="templates[]" value="{{ $t->id }}" @checked(in_array($t->id, $linkedIds, true))>
                            <span>{{ $t->name }}</span>
                        </label>
                    @empty
                        <p class="small text-muted mb-0">No published measurement templates yet.</p>
                    @endforelse
                    <div class="form-text">When published, the style keeps the template version that was live at that moment.</div>
                </x-cb.card>

                <x-cb.card title="Fabric" icon="ri-scissors-cut-line" class="mt-3">
                    <div class="row g-2">
                        <div class="col-7"><label class="form-label small">Typical quantity</label><input type="number" step="0.25" min="0" name="fabric_quantity" class="form-control" value="{{ old('fabric_quantity', $draft->fabric_quantity !== null ? (float) $draft->fabric_quantity : '') }}"></div>
                        <div class="col-5"><label class="form-label small">Unit</label>
                            <select name="fabric_unit" class="form-select"><option value="yd" @selected(old('fabric_unit', $draft->fabric_unit ?? 'yd') === 'yd')>yards</option><option value="m" @selected(old('fabric_unit', $draft->fabric_unit) === 'm')>metres</option></select></div>
                        <div class="col-12"><label class="form-label small">Notes</label><input name="fabric_notes" class="form-control" maxlength="255" value="{{ old('fabric_notes', $draft->fabric_notes) }}" placeholder="e.g. 5 yd lace + 2 yd lining"></div>
                    </div>
                </x-cb.card>

                <x-cb.card title="Save" icon="ri-save-line" class="mt-3">
                    <label class="d-flex align-items-center gap-2 mb-3">
                        <input type="hidden" name="show_in_catalogue" value="0">
                        <input type="checkbox" name="show_in_catalogue" value="1" @checked(old('show_in_catalogue', $style->show_in_catalogue))>
                        <span>Show in the customer catalogue once published</span>
                    </label>
                    @if($canPublish)
                        <input name="change_notes" class="form-control mb-2" maxlength="255" placeholder="What changed? (optional, for the version history)" value="{{ old('change_notes') }}">
                    @endif
                    <div class="d-grid gap-2">
                        <button name="action" value="save" class="action-btn justify-content-center py-2"><i class="ri-save-line"></i>Save draft</button>
                        @if($canPublish)
                            <button name="action" value="publish" class="action-btn btn-primary-cb justify-content-center py-2"><i class="ri-rocket-line"></i>Save &amp; publish v{{ $draft->version_no }}</button>
                        @else
                            <div class="small text-muted text-center">A manager will publish it.</div>
                        @endif
                    </div>
                </x-cb.card>
            </div>
        </div>
    </form>

    @foreach($draft->images as $img)
        <form id="del-{{ $img->id }}" method="POST" action="{{ route('style-images.destroy', $img) }}" class="d-none">@csrf @method('DELETE')</form>
        <form id="cover-{{ $img->id }}" method="POST" action="{{ route('style-images.cover', $img) }}" class="d-none">@csrf</form>
    @endforeach

</div>
</div>
</div>

<script>
(function () {
    const rows = document.getElementById('optionRows');
    let n = rows.querySelectorAll('.option-row').length;
    document.getElementById('addOption').addEventListener('click', () => {
        const tpl = rows.querySelector('.option-row').cloneNode(true);
        tpl.querySelectorAll('input').forEach(i => { i.value = ''; i.name = i.name.replace(/\[\d+\]/, '[' + n + ']'); });
        rows.appendChild(tpl); n++;
    });
    rows.addEventListener('click', e => {
        const btn = e.target.closest('.remove-option');
        if (!btn) return;
        const row = btn.closest('.option-row');
        if (rows.querySelectorAll('.option-row').length > 1) row.remove(); else row.querySelectorAll('input').forEach(i => i.value = '');
    });
})();
</script>
@endsection
