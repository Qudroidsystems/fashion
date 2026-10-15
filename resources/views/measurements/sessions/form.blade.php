@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php
        use App\Support\Measurements\Units;
        $version = $session->templateVersion;
        $values = $session->values->keyBy(fn ($v) => $v->template_parameter_id . ':' . $v->side);
        $canApprove = auth()->user()->can('Approve measurement');
    @endphp

    <x-cb.hero :title="'Measure ' . $customer->full_name" icon="ri-ruler-2-line"
               :subtitle="$version->label . ($session->supersedes_id ? ' · correction' : '')"
               :back="route('customers.show', $customer) . '#measurements'" back-label="Back to customer">
        <x-slot:pills>
            <span class="cb-meta-pill">{{ $customer->customer_number }}</span>
            @if($previousSession)<span class="cb-meta-pill">Last approved: {{ $previousSession->verified_at?->timezone($tz)->format('d M Y') }}</span>@endif
            @if($session->status === 'rejected')<span class="cb-meta-pill">Sent back: {{ $session->rejection_reason }}</span>@endif
        </x-slot:pills>
    </x-cb.hero>

    @if(session('success'))<div class="cb-banner success"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>@endif
    @if($errors->any())<div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>@endif
    @if($session->correction_reason)<div class="cb-banner info"><i class="ri-information-line"></i><div>Correction of an approved session — reason: {{ $session->correction_reason }}</div></div>@endif

    <form method="POST" action="{{ $session->exists ? route('measurements.update', $session) : route('measurements.store', $customer) }}" id="measureForm">
        @csrf
        @if($session->exists) @method('PUT') @else <input type="hidden" name="template_version_id" value="{{ $version->id }}"> @endif
        <input type="hidden" name="display_unit" id="display_unit" value="{{ $unit }}">

        <div class="row g-3">
            <div class="col-xl-8">
                <x-cb.card title="Measurements" icon="ri-ruler-line">
                    <x-slot:tools>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Unit">
                            @foreach(['in' => 'Inches', 'cm' => 'cm'] as $u => $l)
                                <button type="button" class="btn {{ $unit === $u ? 'btn-primary' : 'btn-outline-primary' }} unit-btn" data-unit="{{ $u }}">{{ $l }}</button>
                            @endforeach
                        </div>
                    </x-slot:tools>

                    <div class="row g-3">
                    @foreach($version->parameters as $tp)
                        <div class="col-md-6">
                            <div class="p-3 rounded-3 h-100" style="border:1px solid var(--cb-border)">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <label class="fw-semibold mb-1">{{ $tp->label }} @if($tp->is_required)<span class="text-danger">*</span>@endif</label>
                                    @if($tp->instructions)
                                        <i class="ri-question-line text-muted" data-bs-toggle="tooltip" title="{{ $tp->instructions }}"></i>
                                    @endif
                                </div>
                                @if($tp->guidance)<div class="small text-muted mb-1">{{ $tp->guidance }}</div>@endif

                                @foreach($tp->sides() as $side)
                                    @php
                                        $v = $values->get($tp->id . ':' . $side);
                                        $prev = $previous->get($tp->code . ':' . $side);
                                        $shown = old("values.{$tp->id}.{$side}", $v ? ($tp->isLength() ? Units::format($v->value_mm, $unit) : ($v->text_value ?? rtrim(rtrim((string) $v->number_value, '0'), '.'))) : '');
                                    @endphp
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        @if($side !== 'both')<span class="small text-muted" style="width:38px">{{ ucfirst($side) }}</span>@endif
                                        @if($tp->isLength() || $tp->data_type === 'number')
                                            <input type="text" inputmode="decimal" autocomplete="off"
                                                   class="form-control form-control-lg measure-input {{ $v?->validation_state === 'out_of_range' ? 'is-invalid' : '' }}"
                                                   name="values[{{ $tp->id }}][{{ $side }}]" value="{{ $shown }}"
                                                   @if($tp->isLength()) data-length="1" data-min-mm="{{ $tp->min_mm }}" data-max-mm="{{ $tp->max_mm }}" @endif
                                                   @if($tp->isLength() && $prev?->value_mm) data-prev-mm="{{ $prev->value_mm }}" @endif>
                                            @if($tp->isLength())<span class="unit-label text-muted">{{ $unit }}</span>@endif
                                        @elseif($tp->data_type === 'select')
                                            <select class="form-select" name="values[{{ $tp->id }}][{{ $side }}]">
                                                <option value="">—</option>
                                                @foreach($tp->options ?? [] as $opt)<option @selected($shown === $opt)>{{ $opt }}</option>@endforeach
                                            </select>
                                        @elseif($tp->data_type === 'boolean')
                                            <select class="form-select" name="values[{{ $tp->id }}][{{ $side }}]">
                                                <option value="">—</option><option value="yes" @selected($shown === 'yes')>Yes</option><option value="no" @selected($shown === 'no')>No</option>
                                            </select>
                                        @else
                                            <input type="text" class="form-control" name="values[{{ $tp->id }}][{{ $side }}]" value="{{ $shown }}" maxlength="255">
                                        @endif
                                    </div>
                                    @if($prev)
                                        <div class="small text-muted prev-hint" @if($tp->isLength()) data-prev-mm="{{ $prev->value_mm }}" @endif>
                                            Last approved: <span class="prev-val">{{ $tp->isLength() ? Units::format($prev->value_mm, $unit, true) : ($prev->text_value ?? $prev->number_value) }}</span>
                                        </div>
                                    @endif
                                    <div class="small range-warn text-danger" style="display:none"></div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                    </div>
                </x-cb.card>
            </div>

            <div class="col-xl-4">
                <x-cb.card title="Session" icon="ri-calendar-check-line">
                    <div class="mb-3">
                        <label class="form-label">Measured on</label>
                        <input type="datetime-local" name="measured_at" class="form-control"
                               value="{{ old('measured_at', ($session->measured_at ?? now())->timezone($tz)->format('Y-m-d\TH:i')) }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="e.g. Customer requested a regular fit">{{ old('notes', $session->notes) }}</textarea>
                    </div>
                    <div class="small text-muted mb-3">Values are stored in millimetres; switching units only changes how you type and see them.</div>
                    <div class="d-grid gap-2">
                        <button name="action" value="save" class="action-btn justify-content-center py-2"><i class="ri-save-line"></i>Save draft</button>
                        <button name="action" value="submit" class="action-btn btn-primary-cb justify-content-center py-2"><i class="ri-send-plane-line"></i>Submit for review</button>
                        @if($canApprove)
                            <button name="action" value="submit" class="action-btn btn-primary-cb justify-content-center py-2" onclick="document.getElementById('approve_now').value=1"><i class="ri-checkbox-circle-line"></i>Submit &amp; approve</button>
                            <input type="hidden" name="approve_now" id="approve_now" value="0">
                        @endif
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
    const TO_MM = { in: 25.4, cm: 10 };
    const DEC = { in: 2, cm: 1 };
    const fmt = (mm, unit) => { const v = (mm / TO_MM[unit]).toFixed(DEC[unit]); return v.replace(/\.?0+$/, ''); };
    const parse = s => {
        s = String(s || '').trim().replace(',', '.').replace('½', ' 1/2').replace('¼', ' 1/4').replace('¾', ' 3/4');
        const m = s.match(/^(\d+(?:\.\d+)?)?\s*(?:(\d+)\/(\d+))?$/);
        if (!m || (!m[1] && !m[2])) return null;
        return (m[1] ? parseFloat(m[1]) : 0) + (m[2] ? parseInt(m[2]) / parseInt(m[3]) : 0);
    };
    let unit = document.getElementById('display_unit').value;

    function check(input) {
        const warn = input.closest('.p-3').querySelectorAll('.range-warn');
        const box = [...warn].find(w => w.previousElementSibling === input.parentElement || w.previousElementSibling?.previousElementSibling === input.parentElement) || warn[0];
        if (!input.dataset.length) return;
        const v = parse(input.value);
        let msg = '';
        if (input.value && v === null) msg = 'Enter a number, e.g. 38.5 or 38 1/2';
        else if (v !== null) {
            const mm = v * TO_MM[unit];
            const min = parseFloat(input.dataset.minMm), max = parseFloat(input.dataset.maxMm);
            if ((!isNaN(min) && mm < min) || (!isNaN(max) && mm > max)) msg = 'Unusual value — please double-check (you can still save it).';
            const prev = parseFloat(input.dataset.prevMm);
            if (!msg && !isNaN(prev) && prev > 0 && Math.abs(mm - prev) / prev > 0.05) msg = 'More than 5% different from the last approved value.';
        }
        input.classList.toggle('is-invalid', !!msg && msg.startsWith('Unusual'));
        if (box) { box.textContent = msg; box.style.display = msg ? '' : 'none'; box.classList.toggle('text-warning', msg.startsWith('More')); }
    }

    document.querySelectorAll('.measure-input').forEach(i => { i.addEventListener('input', () => check(i)); check(i); });

    document.querySelectorAll('.unit-btn').forEach(btn => btn.addEventListener('click', () => {
        const to = btn.dataset.unit;
        if (to === unit) return;
        document.querySelectorAll('.measure-input[data-length]').forEach(i => {
            const v = parse(i.value);
            if (v !== null) i.value = fmt(v * TO_MM[unit], to);
        });
        document.querySelectorAll('.prev-hint[data-prev-mm]').forEach(h => h.querySelector('.prev-val').textContent = fmt(parseFloat(h.dataset.prevMm), to) + ' ' + to);
        document.querySelectorAll('.unit-label').forEach(l => l.textContent = to);
        document.querySelectorAll('.unit-btn').forEach(b => { b.classList.toggle('btn-primary', b === btn); b.classList.toggle('btn-outline-primary', b !== btn); });
        unit = to;
        document.getElementById('display_unit').value = to;
        document.querySelectorAll('.measure-input').forEach(check);
    }));

    if (window.bootstrap) document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
})();
</script>
@endsection
