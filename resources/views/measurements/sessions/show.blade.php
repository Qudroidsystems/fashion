@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php
        use App\Support\Measurements\Units;
        $user = auth()->user();
        $customer = $session->customer;
        $version = $session->templateVersion;
        $fmtVal = function ($v, $dataType) use ($unit) {
            if (! $v) return null;
            if ($dataType === 'length') return Units::format($v->value_mm, $unit, true);
            return $v->text_value ?? rtrim(rtrim((string) $v->number_value, '0'), '.');
        };
        $flagged = $comparison->where('flagged', true)->count();
        $outOfRange = $session->values->where('validation_state', 'out_of_range')->count();
    @endphp

    <x-cb.hero :title="$customer->name" icon="ri-ruler-2-line"
               :subtitle="$version->label"
               :back="route('customers.show', $customer) . '#measurements'" back-label="Back to customer">
        <x-slot:pills>
            <span class="status-pill {{ $session->status_pill }}">{{ ucfirst($session->status) }}</span>
            <span class="cb-meta-pill">{{ \App\Models\MeasurementSession::METHODS[$session->capture_method] ?? $session->capture_method }}</span>
            <span class="cb-meta-pill">Measured {{ $session->measured_at?->timezone($tz)->format('d M Y, H:i') }}{{ $session->measurer ? ' by ' . $session->measurer->name : '' }}</span>
            @if($session->verifier && in_array($session->status, ['approved', 'superseded']))
                <span class="cb-meta-pill">Approved {{ $session->verified_at?->timezone($tz)->format('d M Y') }} by {{ $session->verifier->name }}</span>
            @endif
        </x-slot:pills>
        <x-slot:actions>
            <div class="btn-group btn-group-sm" role="group" aria-label="Unit">
                @foreach(['in' => 'in', 'cm' => 'cm'] as $u => $l)
                    <a href="{{ route('measurements.show', [$session, 'unit' => $u]) }}" class="btn {{ $unit === $u ? 'btn-light' : 'btn-outline-light' }}">{{ $l }}</a>
                @endforeach
            </div>
        </x-slot:actions>
    </x-cb.hero>

    @if(session('success'))<div class="cb-banner success"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>@endif
    @if($errors->any())<div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>@endif
    @if($session->status === 'rejected')<div class="cb-banner danger"><i class="ri-arrow-go-back-line"></i><div>Sent back: {{ $session->rejection_reason }}</div></div>@endif
    @if($session->status === 'superseded')<div class="cb-banner info"><i class="ri-history-line"></i><div>This session was replaced by a later correction. It is kept for history.</div></div>@endif
    @if($session->correction_reason)<div class="cb-banner info"><i class="ri-information-line"></i><div>Correction of the session from {{ $session->supersedes?->measured_at?->timezone($tz)->format('d M Y') }} — {{ $session->correction_reason }}</div></div>@endif
    @if($missing)<div class="cb-banner warning"><i class="ri-error-warning-line"></i><div>Still missing before it can be submitted: {{ implode(', ', $missing) }}.</div></div>@endif
    @if($session->status === 'submitted' && ($flagged || $outOfRange))
        <div class="cb-banner warning"><i class="ri-flag-line"></i><div>
            @if($flagged){{ $flagged }} value(s) differ noticeably from the last approved measurements. @endif
            @if($outOfRange){{ $outOfRange }} value(s) are outside the usual range. @endif
            A difference is a prompt to double-check, not proof that the customer's body changed.
        </div></div>
    @endif

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card title="Values" icon="ri-ruler-line" :flush="true">
                <x-slot:tools>
                    @if($previous)<span class="small text-muted">Compared with {{ $previous->verified_at?->timezone($tz)->format('d M Y') }}</span>@endif
                </x-slot:tools>
                <div class="table-responsive">
                    <table class="cb-table stack mb-0">
                        <thead><tr><th>Measurement</th><th>This session</th><th>Last approved</th><th>Change</th></tr></thead>
                        <tbody>
                        @foreach($comparison as $row)
                            @php $cur = $row['current']; @endphp
                            <tr @if($row['flagged']) style="background:rgba(247,184,75,.08)" @endif>
                                <td data-label="Measurement" class="fw-semibold">{{ $row['label'] }}
                                    @if($cur?->note)<div class="small text-muted fw-normal">{{ $cur->note }}</div>@endif
                                </td>
                                <td data-label="This session">
                                    @if($cur)
                                        {{ $fmtVal($cur, $row['data_type']) }}
                                        @if($cur->validation_state === 'out_of_range')<span class="status-pill st-danger ms-1">unusual</span>@endif
                                        @if($cur->original_unit && $cur->original_unit !== $unit && $row['data_type'] === 'length')
                                            <div class="small text-muted">entered as {{ rtrim(rtrim((string) $cur->original_value, '0'), '.') }} {{ $cur->original_unit }}</div>
                                        @endif
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td data-label="Last approved">{{ $fmtVal($row['previous'], $row['data_type']) ?? '—' }}</td>
                                <td data-label="Change">
                                    @if($row['delta_mm'] !== null && abs($row['delta_mm']) < 0.5)
                                        <span class="text-muted small">same</span>
                                    @elseif($row['delta_mm'] !== null)
                                        @php $sign = $row['delta_mm'] > 0 ? '+' : ($row['delta_mm'] < 0 ? '−' : ''); @endphp
                                        <span class="{{ $row['flagged'] ? 'text-warning fw-semibold' : 'text-muted' }}">
                                            {{ $sign }}{{ Units::format(abs($row['delta_mm']), $unit, true) ?: '0' }}
                                            @if($row['delta_pct'] !== null)({{ $sign }}{{ abs($row['delta_pct']) }}%)@endif
                                        </span>
                                        @if($row['flagged'])<i class="ri-flag-fill text-warning ms-1"></i>@endif
                                    @elseif($row['flagged'])
                                        <span class="text-warning fw-semibold">changed</span> <i class="ri-flag-fill text-warning"></i>
                                    @elseif(! $row['comparable'] && $cur && $row['previous'])
                                        <span class="small text-muted">not comparable</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-cb.card>
        </div>

        <div class="col-xl-4">
            <x-cb.card title="Actions" icon="ri-flashlight-line">
                <div class="d-grid gap-2">
                    @if($session->isEditable() && $user->can('Capture measurement'))
                        <a href="{{ route('measurements.edit', $session) }}" class="action-btn btn-primary-cb justify-content-center py-2"><i class="ri-edit-line"></i>{{ $session->status === 'rejected' ? 'Fix and resubmit' : 'Continue editing' }}</a>
                    @endif

                    @if($session->status === 'submitted' && $user->can('Approve measurement'))
                        <form method="POST" action="{{ route('measurements.approve', $session) }}" class="d-grid">
                            @csrf
                            <button class="action-btn btn-primary-cb justify-content-center py-2"><i class="ri-checkbox-circle-line"></i>Approve</button>
                        </form>
                        <button type="button" class="action-btn justify-content-center py-2" data-bs-toggle="collapse" data-bs-target="#rejectBox"><i class="ri-arrow-go-back-line"></i>Send back</button>
                        <div class="collapse" id="rejectBox">
                            <form method="POST" action="{{ route('measurements.reject', $session) }}">
                                @csrf
                                <textarea name="reason" class="form-control mb-2" rows="2" required maxlength="500" placeholder="What needs checking?"></textarea>
                                <button class="btn btn-sm btn-danger w-100">Send back for correction</button>
                            </form>
                        </div>
                    @endif

                    @if($session->status === 'approved' && $user->can('Capture measurement'))
                        <button type="button" class="action-btn justify-content-center py-2" data-bs-toggle="collapse" data-bs-target="#correctBox"><i class="ri-edit-box-line"></i>Correct these measurements</button>
                        <div class="collapse" id="correctBox">
                            <form method="POST" action="{{ route('measurements.correct', $session) }}">
                                @csrf
                                <textarea name="reason" class="form-control mb-2" rows="2" required maxlength="500" placeholder="Why do they need correcting?"></textarea>
                                <div class="small text-muted mb-2">A new draft starts from these values. This session stays current until the correction is approved.</div>
                                <button class="btn btn-sm btn-primary w-100">Start correction</button>
                            </form>
                        </div>
                    @endif

                    @if($session->status === 'draft' && $user->can('Capture measurement'))
                        <form method="POST" action="{{ route('measurements.destroy', $session) }}" class="d-grid" onsubmit="return confirm('Delete this draft?')">
                            @csrf @method('DELETE')
                            <button class="action-btn justify-content-center py-2 text-danger"><i class="ri-delete-bin-line"></i>Delete draft</button>
                        </form>
                    @endif

                    <a href="{{ route('customers.show', $customer) }}#measurements" class="action-btn justify-content-center py-2"><i class="ri-user-line"></i>Customer profile</a>
                </div>
            </x-cb.card>

            <x-cb.card title="Details" icon="ri-information-line" class="mt-3">
                <dl class="row small mb-0">
                    <dt class="col-5 text-muted fw-normal">Template</dt><dd class="col-7">{{ $version->template->name }} (v{{ $version->version_no }})</dd>
                    <dt class="col-5 text-muted fw-normal">Entered in</dt><dd class="col-7">{{ \App\Support\Measurements\Units::LABELS[$session->display_unit] ?? $session->display_unit }}</dd>
                    @if($session->submitted_at)<dt class="col-5 text-muted fw-normal">Submitted</dt><dd class="col-7">{{ $session->submitted_at->timezone($tz)->format('d M Y, H:i') }}</dd>@endif
                    @if($session->notes)<dt class="col-5 text-muted fw-normal">Notes</dt><dd class="col-7">{{ $session->notes }}</dd>@endif
                    <dt class="col-5 text-muted fw-normal">Reference</dt><dd class="col-7 text-break"><code>{{ $session->public_id }}</code></dd>
                </dl>
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>
@endsection
