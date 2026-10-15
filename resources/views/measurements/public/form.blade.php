@extends('measurements.public.layout')
@section('title', 'Send your measurements')
@section('body')
@php
    $version = $req->templateVersion;
    $other = $unit === 'in' ? 'cm' : 'in';
@endphp

<div class="panel">
    <h4 class="mb-1">Hi {{ $req->customer?->first_name }},</h4>
    <p class="mb-2">{{ $house->name }} would like your measurements for: <strong>{{ $version->template->name ?? 'your outfit' }}</strong>.</p>
    @if($req->message)<div class="p-3 rounded-3 mb-2" style="background:#f6f4ff">{{ $req->message }}</div>@endif
    <div class="small text-muted">
        @if($req->deadline)Please send by {{ $req->deadline->format('j M Y') }}. @endif
        This link works until {{ $req->expires_at->timezone($house->timezone)->format('j M Y, g:i a') }}.
    </div>
</div>

<div class="panel">
    <div class="fw-semibold mb-1"><i class="ri-lightbulb-line me-1"></i>Tips</div>
    <ul class="small text-muted mb-0 ps-3">
        <li>Use a soft tape measure and ask someone to help if you can.</li>
        <li>Wear light, fitted clothing and stand naturally.</li>
        <li>Keep the tape snug, not tight. You can type halves like <strong>38 1/2</strong> or <strong>38.5</strong>.</li>
    </ul>
</div>

@if($errors->any())
    <div class="alert alert-danger">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
@endif

<form method="POST" action="{{ route('measure.submit', $token) }}" class="panel">
    @csrf
    <input type="hidden" name="unit" value="{{ $unit }}">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="fw-semibold">Measurements in {{ $unit === 'in' ? 'inches' : 'centimetres' }}</div>
        <div class="unit-switch small">Use <a href="{{ route('measure.form', [$token, 'unit' => $other]) }}">{{ $other === 'in' ? 'inches' : 'cm' }}</a> instead</div>
    </div>

    @foreach($version->parameters as $tp)
        <div class="field">
            <label>{{ $tp->label }} @if($tp->is_required)<span class="text-danger">*</span>@else<span class="small text-muted fw-normal">(optional)</span>@endif</label>
            @if($tp->instructions)<div class="how mb-2">{{ $tp->instructions }}</div>@endif
            @if($tp->guidance)<div class="how mb-2">{{ $tp->guidance }}</div>@endif
            @foreach($tp->sides() as $side)
                @php $old = old("values.{$tp->id}.{$side}"); @endphp
                <div class="input-group mb-2">
                    @if($side !== 'both')<span class="input-group-text" style="width:70px">{{ ucfirst($side) }}</span>@endif
                    @if($tp->data_type === 'select')
                        <select class="form-select form-select-lg" name="values[{{ $tp->id }}][{{ $side }}]" @required($tp->is_required)>
                            <option value="">Choose…</option>
                            @foreach($tp->options ?? [] as $opt)<option @selected($old === $opt)>{{ $opt }}</option>@endforeach
                        </select>
                    @elseif($tp->data_type === 'boolean')
                        <select class="form-select form-select-lg" name="values[{{ $tp->id }}][{{ $side }}]" @required($tp->is_required)>
                            <option value="">Choose…</option><option value="yes" @selected($old === 'yes')>Yes</option><option value="no" @selected($old === 'no')>No</option>
                        </select>
                    @elseif($tp->data_type === 'text')
                        <input type="text" class="form-control form-control-lg" name="values[{{ $tp->id }}][{{ $side }}]" value="{{ $old }}" maxlength="100" @required($tp->is_required)>
                    @else
                        <input type="text" inputmode="decimal" autocomplete="off" class="form-control form-control-lg" name="values[{{ $tp->id }}][{{ $side }}]" value="{{ $old }}" @required($tp->is_required)>
                        @if($tp->isLength())<span class="input-group-text">{{ $unit }}</span>@endif
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach

    <div class="field">
        <label for="note">Anything else we should know? <span class="small text-muted fw-normal">(optional)</span></label>
        <textarea name="note" id="note" class="form-control" rows="3" maxlength="1000">{{ old('note') }}</textarea>
    </div>

    <div class="form-check my-3">
        <input class="form-check-input" type="checkbox" name="confirm" value="1" id="confirm" required>
        <label class="form-check-label" for="confirm">I confirm these measurements are correct to the best of my knowledge.</label>
    </div>
    <button class="btn btn-accent btn-lg w-100">Send my measurements</button>
    <div class="small text-muted text-center mt-2">The fashion house will review them before use.</div>
</form>
@endsection
