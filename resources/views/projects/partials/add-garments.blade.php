{{-- The same garment for several people at once (e.g. every bridesmaid). --}}
@php $byGroup = $active->groupBy(fn ($p) => $p->group_name ?: 'Everyone else'); @endphp
<form method="POST" action="{{ route('projects.garments.store', $project) }}" id="garmentForm">@csrf
    <div class="row g-2">
        <div class="col-lg-5">
            <label class="form-label small mb-1">For</label>
            <div class="border rounded p-2 bg-body" style="max-height:220px;overflow:auto">
                @foreach($byGroup as $gname => $members)
                    <div class="d-flex justify-content-between small fw-semibold mt-1"><span>{{ $gname }}</span>
                        <a href="#" class="small" data-pick-group="{{ $loop->index }}">all</a></div>
                    @foreach($members as $p)
                        <label class="d-flex gap-2 small"><input type="checkbox" name="participant_ids[]" value="{{ $p->id }}" data-group="{{ $loop->parent->index }}">
                            {{ $p->name }}@if($p->pays === 'self')<span class="text-warning">(own order)</span>@endif</label>
                    @endforeach
                @endforeach
            </div>
        </div>
        <div class="col-lg-7">
            <div class="row g-2">
                <div class="col-md-4"><label class="form-label small mb-1">Kind</label>
                    <select name="kind" class="form-select form-select-sm" id="gKind">
                        <option value="bespoke">Made to measure</option><option value="ready_made">Ready-made</option><option value="service">Service / other</option></select></div>
                <div class="col-md-8 g-k g-bespoke"><label class="form-label small mb-1">Style</label>
                    <select name="style_id" class="form-select form-select-sm" id="gStyle"><option value="">Choose…</option>
                        @foreach($styles as $st)<option value="{{ $st->id }}" data-price="{{ $st->currentVersion?->base_price }}">{{ $st->name }} · {{ \App\Support\Money::format($st->currentVersion?->base_price ?? 0) }}</option>@endforeach</select></div>
                <div class="col-md-8 g-k g-ready_made d-none"><label class="form-label small mb-1">Product</label>
                    <select name="product_variant_id" class="form-select form-select-sm"><option value="">Choose…</option>
                        @foreach($variants as $v)<option value="{{ $v->id }}">{{ $v->product->name }}{{ $v->label ? ' — ' . $v->label : '' }} · {{ \App\Support\Money::format($v->price) }}</option>@endforeach</select></div>
                <div class="col-md-8 g-k g-service d-none"><label class="form-label small mb-1">Description</label>
                    <input name="description" class="form-control form-control-sm" maxlength="255" placeholder="e.g. Gele tying on the day"></div>
                <div class="col-12 g-k g-bespoke" id="gOptions">
                    @foreach($styles as $st)
                        @if(! empty($st->currentVersion?->price_options))
                            <div class="d-none small d-flex-wrap gap-3" data-style-options="{{ $st->id }}">
                                @foreach($st->currentVersion->price_options as $o)<label class="me-3"><input type="checkbox" name="options[]" value="{{ $o['label'] }}" disabled> {{ $o['label'] }} (+{{ \App\Support\Money::format($o['amount']) }})</label>@endforeach
                            </div>
                        @endif
                    @endforeach
                </div>
                <div class="col-md-3"><label class="form-label small mb-1">Qty each</label><input type="number" name="quantity" min="1" max="999" value="1" class="form-control form-control-sm"></div>
                <div class="col-md-4"><label class="form-label small mb-1">Price each</label><input type="number" name="unit_price" min="0" step="0.01" class="form-control form-control-sm" placeholder="Catalogue price"
                    @cannot('Override price') title="Only staff allowed to override prices can go below the catalogue" @endcannot></div>
                <div class="col-md-5"><label class="form-label small mb-1">Instructions</label><input name="instructions" class="form-control form-control-sm" maxlength="2000" placeholder="Optional"></div>
                <div class="col-12 d-grid"><button class="action-btn btn-primary-cb justify-content-center"><i class="ri-add-line"></i>Add to the ticked people</button></div>
                <div class="col-12 small text-muted">Each garment goes on the client’s order, or on the person’s own order if they pay for themselves.{{ $project->status === 'active' ? ' The order is already confirmed, so this is kept as a revision.' : '' }}</div>
            </div>
        </div>
    </div>
</form>
<script>
(() => {
    const f = document.getElementById('garmentForm'); if (!f) return;
    const kind = f.querySelector('#gKind'), style = f.querySelector('#gStyle');
    const showKind = () => f.querySelectorAll('.g-k').forEach(el => el.classList.toggle('d-none', !el.classList.contains('g-' + kind.value)));
    const showOpts = () => f.querySelectorAll('[data-style-options]').forEach(el => {
        const on = el.dataset.styleOptions === style.value && kind.value === 'bespoke';
        el.classList.toggle('d-none', !on); el.querySelectorAll('input').forEach(i => i.disabled = !on);
    });
    kind.addEventListener('change', () => { showKind(); showOpts(); });
    style.addEventListener('change', showOpts);
    f.querySelectorAll('[data-pick-group]').forEach(a => a.addEventListener('click', e => {
        e.preventDefault(); const boxes = f.querySelectorAll('input[data-group="' + a.dataset.pickGroup + '"]');
        const all = [...boxes].every(b => b.checked); boxes.forEach(b => b.checked = !all);
    }));
    showKind();
})();
</script>
