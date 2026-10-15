@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Measurement parameters" icon="ri-ruler-2-line"
               subtitle="Everything your house can measure. Templates pick from this list."
               :back="route('measurement-templates.index')" back-label="Templates">
        <x-slot:pills>
            <span class="cb-meta-pill">Ranges shown in {{ \App\Support\Measurements\Units::LABELS[$unit] }}</span>
            <a class="cb-meta-pill text-decoration-none" href="{{ route('measurement-parameters.index', ['unit' => $unit === 'in' ? 'cm' : 'in']) }}">Switch to {{ $unit === 'in' ? 'cm' : 'inches' }}</a>
        </x-slot:pills>
        <x-slot:actions>
            <button class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#paramModal"><i class="ri-add-line me-1"></i>New parameter</button>
        </x-slot:actions>
    </x-cb.hero>

    @if(session('success'))<div class="cb-banner success"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>@endif
    @if($errors->any())<div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>@endif

    <x-cb.card title="Parameters" icon="ri-list-check-2" :count="$parameters->count()" :flush="true">
        <div class="table-responsive">
            <table class="cb-table stack">
                <thead><tr><th>Measurement</th><th>Code</th><th>Type</th><th>Valid range</th><th>Flag change over</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @foreach($parameters as $p)
                    @php
                        $payload = [
                            'id' => $p->id, 'code' => $p->code, 'label' => $p->label, 'body_area' => $p->body_area, 'data_type' => $p->data_type,
                            'is_bilateral' => $p->is_bilateral, 'options' => implode(', ', $p->options ?? []),
                            'min' => $p->min_mm !== null ? \App\Support\Measurements\Units::format($p->min_mm, $unit) : '',
                            'max' => $p->max_mm !== null ? \App\Support\Measurements\Units::format($p->max_mm, $unit) : '',
                            'threshold' => $p->change_threshold_mm !== null ? \App\Support\Measurements\Units::format($p->change_threshold_mm, $unit) : '',
                            'instructions' => $p->instructions, 'description' => $p->description, 'is_active' => $p->is_active,
                        ];
                    @endphp
                    <tr>
                        <td data-label="Measurement">
                            <span class="fw-semibold">{{ $p->label }}</span>
                            @if($p->is_bilateral)<span class="status-pill st-info ms-1">Left / right</span>@endif
                            @if($p->instructions)<div class="small text-muted">{{ \Illuminate\Support\Str::limit($p->instructions, 90) }}</div>@endif
                        </td>
                        <td data-label="Code"><code>{{ $p->code }}</code></td>
                        <td data-label="Type" class="small">{{ $types[$p->data_type] }}@if($p->data_type === 'select')<div class="text-muted">{{ implode(', ', $p->options ?? []) }}</div>@endif</td>
                        <td data-label="Valid range" class="small">
                            @if($p->data_type === 'length' && ($p->min_mm || $p->max_mm))
                                {{ $payload['min'] ?: '…' }} – {{ $payload['max'] ?: '…' }} {{ $unit }}
                            @else — @endif
                        </td>
                        <td data-label="Flag change over" class="small">{{ $payload['threshold'] ? $payload['threshold'] . ' ' . $unit : ($p->data_type === 'length' ? 'house default %' : '—') }}</td>
                        <td data-label="Status"><span class="status-pill {{ $p->is_active ? 'st-success' : 'st-muted' }}">{{ $p->is_active ? 'Active' : 'Off' }}</span></td>
                        <td class="text-end">
                            <button class="action-btn btn-primary-cb" data-bs-toggle="modal" data-bs-target="#paramModal" data-param="{{ json_encode($payload) }}"><i class="ri-pencil-line"></i>Edit</button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-cb.card>

    <div class="modal fade" id="paramModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form class="modal-content" method="POST" id="paramForm" action="{{ route('measurement-parameters.store') }}">
                @csrf
                <input type="hidden" name="_method" id="paramMethod" value="POST">
                <input type="hidden" name="unit" value="{{ $unit }}">
                <div class="modal-header"><h5 class="modal-title" id="paramTitle">New parameter</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Label *</label><input name="label" id="p-label" class="form-control" required placeholder="e.g. Round sleeve"></div>
                        <div class="col-md-6"><label class="form-label">Code *</label><input name="code" id="p-code" class="form-control font-monospace" required placeholder="round_sleeve">
                            <div class="form-text">Used to compare sessions. Can't change once used in a template.</div></div>
                        <div class="col-md-4"><label class="form-label">Type</label>
                            <select name="data_type" id="p-type" class="form-select">@foreach($types as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                        <div class="col-md-4"><label class="form-label">Body area</label>
                            <select name="body_area" id="p-area" class="form-select"><option value="">—</option>@foreach($areas as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check form-switch mb-2"><input type="hidden" name="is_bilateral" value="0"><input class="form-check-input" type="checkbox" name="is_bilateral" value="1" id="p-bi"><label class="form-check-label" for="p-bi">Separate left / right</label></div>
                        </div>
                        <div class="col-md-4 len-only"><label class="form-label">Min ({{ $unit }})</label><input name="min" id="p-min" class="form-control" inputmode="decimal"></div>
                        <div class="col-md-4 len-only"><label class="form-label">Max ({{ $unit }})</label><input name="max" id="p-max" class="form-control" inputmode="decimal"></div>
                        <div class="col-md-4 len-only"><label class="form-label">Flag a change over ({{ $unit }})</label><input name="threshold" id="p-threshold" class="form-control" inputmode="decimal" placeholder="house default %"></div>
                        <div class="col-12 select-only"><label class="form-label">Options (comma-separated)</label><input name="options" id="p-options" class="form-control" placeholder="Upright, Rounded shoulders"></div>
                        <div class="col-12"><label class="form-label">How to measure</label><textarea name="instructions" id="p-instructions" class="form-control" rows="2"></textarea></div>
                        <div class="col-12"><div class="form-check form-switch"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="p-active" checked><label class="form-check-label" for="p-active">Active</label></div></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
            </form>
        </div>
    </div>

</div>
</div>
</div>

<script>
(function () {
    const modal = document.getElementById('paramModal');
    const type = document.getElementById('p-type');
    const sync = () => {
        document.querySelectorAll('.len-only').forEach(el => el.style.display = type.value === 'length' ? '' : 'none');
        document.querySelectorAll('.select-only').forEach(el => el.style.display = type.value === 'select' ? '' : 'none');
    };
    type.addEventListener('change', sync);
    modal.addEventListener('show.bs.modal', e => {
        const p = e.relatedTarget?.dataset.param ? JSON.parse(e.relatedTarget.dataset.param) : null;
        const form = document.getElementById('paramForm');
        form.reset();
        document.getElementById('paramTitle').textContent = p ? 'Edit ' + p.label : 'New parameter';
        form.action = p ? @json(url('/measurement-parameters')) + '/' + p.id : @json(route('measurement-parameters.store'));
        document.getElementById('paramMethod').value = p ? 'PUT' : 'POST';
        const set = (id, v) => document.getElementById(id).value = v ?? '';
        set('p-label', p?.label); set('p-code', p?.code); set('p-type', p?.data_type || 'length'); set('p-area', p?.body_area);
        set('p-min', p?.min); set('p-max', p?.max); set('p-threshold', p?.threshold); set('p-options', p?.options); set('p-instructions', p?.instructions);
        document.getElementById('p-bi').checked = !!p?.is_bilateral;
        document.getElementById('p-active').checked = p ? !!p.is_active : true;
        sync();
    });
    sync();
})();
</script>
@endsection
