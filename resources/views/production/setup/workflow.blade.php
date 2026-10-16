@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    @php use App\Models\ProductionStage; use App\Models\ProductionWorkflow; $w = $workflow; @endphp
    <x-cb.hero :title="$w->exists ? 'Edit ' . $w->name : 'New workflow'" icon="ri-route-line" subtitle="Stages run in order. Garments already planned keep the stages they were planned with."
               :back="route('production.setup')" back-label="Setup" />
    @include('inventory.partials.flash')
    <form method="POST" action="{{ $w->exists ? route('production.workflows.update', $w) : route('production.workflows.store') }}">@csrf @if($w->exists) @method('PUT') @endif
    <div class="row g-3">
        <div class="col-xl-4">
            <x-cb.card title="Workflow" icon="ri-information-line">
                <label class="form-label">Name</label><input name="name" class="form-control mb-3" required maxlength="120" value="{{ old('name', $w->name) }}" placeholder="e.g. Agbada set">
                <label class="form-label">Used for</label>
                @foreach(ProductionWorkflow::LINE_KINDS as $k => $l)
                    <label class="d-flex gap-2 small"><input type="checkbox" name="line_kinds[]" value="{{ $k }}" @checked(in_array($k, old('line_kinds', $w->line_kinds ?? [])))> {{ $l }}</label>
                @endforeach
                <label class="form-label mt-3">Only for this style category <span class="text-muted small">(optional)</span></label>
                <select name="category_id" class="form-select mb-3"><option value="">Any category</option>
                    @foreach($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id', $w->category_id) == $c->id)>{{ $c->name }}</option>@endforeach</select>
                <label class="form-label">Description</label><input name="description" class="form-control mb-3" maxlength="255" value="{{ old('description', $w->description) }}">
                <label class="d-flex gap-2 small"><input type="hidden" name="is_default" value="0"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $w->is_default))> Default for these kinds of item</label>
                <label class="d-flex gap-2 small"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $w->is_active ?? true))> In use</label>
                <p class="small text-muted mt-2 mb-0">When planning, a workflow for the style's category wins, then the default.</p>
            </x-cb.card>
        </div>
        <div class="col-xl-8">
            <x-cb.card title="Stages" icon="ri-list-ordered" :flush="true">
                <div id="stageRows">
                    @foreach($rows as $i => $r)
                        @include('production.setup.stage-row', ['i' => $i, 'r' => $r])
                    @endforeach
                </div>
                <div class="p-3 d-flex gap-2"><button type="button" class="action-btn" id="stageAdd"><i class="ri-add-line"></i>Add stage</button>
                    <button class="action-btn btn-primary-cb ms-auto"><i class="ri-save-line"></i>Save workflow</button></div>
            </x-cb.card>
        </div>
    </div>
    </form>
    <template id="stageTpl">@include('production.setup.stage-row', ['i' => '__', 'r' => ['type' => 'work', 'duration_hours' => 8, 'requires_previous' => true]])</template>
</div>
</div>
</div>
@push('scripts')
<script>
(function () {
    const box = document.getElementById('stageRows'), tpl = document.getElementById('stageTpl').innerHTML;
    let n = 1000;
    const sync = () => box.querySelectorAll('.stage-row').forEach((row, i) => {
        row.querySelector('.stage-no').textContent = i + 1;
        const qc = row.querySelector('.st-type').value === 'qc';
        row.querySelector('.st-checklist').classList.toggle('invisible', !qc);
    });
    document.getElementById('stageAdd').addEventListener('click', () => { box.insertAdjacentHTML('beforeend', tpl.replaceAll('__', n++)); sync(); });
    box.addEventListener('click', e => {
        const row = e.target.closest('.stage-row'); if (!row) return;
        if (e.target.closest('.st-up') && row.previousElementSibling) box.insertBefore(row, row.previousElementSibling);
        if (e.target.closest('.st-down') && row.nextElementSibling) box.insertBefore(row.nextElementSibling, row);
        if (e.target.closest('.st-remove') && box.children.length > 1) row.remove();
        sync();
    });
    box.addEventListener('change', e => { if (e.target.classList.contains('st-type')) sync(); });
    sync();
})();
</script>
@endpush
@endsection
