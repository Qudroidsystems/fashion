@php use App\Models\ProductionStage; @endphp
<div class="stage-row p-3 border-bottom">
    <input type="hidden" name="stages[{{ $i }}][id]" value="{{ $r['id'] ?? '' }}">
    <div class="row g-2 align-items-end">
        <div class="col-auto"><span class="stage-no fw-bold text-muted"></span></div>
        <div class="col-md-3"><label class="form-label small">Stage</label><input name="stages[{{ $i }}][name]" class="form-control form-control-sm" maxlength="80" value="{{ $r['name'] ?? '' }}" placeholder="e.g. Beading"></div>
        <div class="col-md-2"><label class="form-label small">Kind</label><select name="stages[{{ $i }}][type]" class="form-select form-select-sm st-type">
            @foreach(ProductionStage::TYPES as $k => $l)<option value="{{ $k }}" @selected(($r['type'] ?? 'work') === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="col-md-1"><label class="form-label small">Hours</label><input type="number" min="1" max="2000" name="stages[{{ $i }}][duration_hours]" class="form-control form-control-sm" value="{{ $r['duration_hours'] ?? 8 }}" title="Working hours; 8 = one day"></div>
        <div class="col-md-2"><label class="form-label small">Usually done by</label><select name="stages[{{ $i }}][role_id]" class="form-select form-select-sm"><option value="">Anyone</option>
            @foreach($roles as $role)<option value="{{ $role->id }}" @selected(($r['role_id'] ?? null) == $role->id)>{{ $role->name }}</option>@endforeach</select></div>
        <div class="col-md-2 st-checklist {{ ($r['type'] ?? 'work') === 'qc' ? '' : 'invisible' }}"><label class="form-label small">Checklist</label><select name="stages[{{ $i }}][checklist_id]" class="form-select form-select-sm"><option value="">Choose…</option>
            @foreach($checklists as $c)<option value="{{ $c->id }}" @selected(($r['checklist_id'] ?? null) == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
        <div class="col-auto ms-auto d-flex gap-1">
            <button type="button" class="btn btn-sm btn-light st-up" title="Move up"><i class="ri-arrow-up-line"></i></button>
            <button type="button" class="btn btn-sm btn-light st-down" title="Move down"><i class="ri-arrow-down-line"></i></button>
            <button type="button" class="btn btn-sm btn-light text-danger st-remove" title="Remove"><i class="ri-delete-bin-line"></i></button>
        </div>
    </div>
    <div class="row g-2 mt-1 align-items-center">
        <div class="col-md-7"><input name="stages[{{ $i }}][instructions]" class="form-control form-control-sm" maxlength="1000" value="{{ $r['instructions'] ?? '' }}" placeholder="Standing instructions (optional)"></div>
        <div class="col-md-5 d-flex gap-3 small">
            <label class="d-flex gap-1"><input type="hidden" name="stages[{{ $i }}][requires_previous]" value="0"><input type="checkbox" name="stages[{{ $i }}][requires_previous]" value="1" @checked($r['requires_previous'] ?? true)> Waits for earlier stages</label>
            <label class="d-flex gap-1"><input type="hidden" name="stages[{{ $i }}][customer_visible]" value="0"><input type="checkbox" name="stages[{{ $i }}][customer_visible]" value="1" @checked($r['customer_visible'] ?? false)> Customer sees it</label>
        </div>
    </div>
</div>
