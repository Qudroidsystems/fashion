@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    @php use App\Models\ProductionTask; $u = auth()->user(); $f = $fitting; $local = $f->scheduled_at->timezone($tz); @endphp
    <x-cb.hero :title="'Fitting · ' . $f->customer->name" icon="ri-calendar-check-line" :subtitle="$local->format('l d M Y, H:i') . ' · ' . $f->duration_minutes . ' min' . ($f->branch ? ' · ' . $f->branch->name : '')"
               :back="route('fittings.index')" back-label="Fittings">
        <x-slot:pills>
            <span class="status-pill {{ $f->status_pill }}">{{ $f->status_label }}</span>
            @if($f->outcome)<span class="status-pill {{ $f->outcome === 'approved' ? 'st-success' : 'st-warning' }}">{{ $f->outcome === 'approved' ? 'Approved' : 'Alterations needed' }}</span>@endif
            <span class="cb-meta-pill">{{ $f->number }}</span>
            @if($f->staff)<span class="cb-meta-pill">With {{ $f->staff->name }}</span>@endif
        </x-slot:pills>
        <x-slot:actions>@if($f->order)<a href="{{ route('orders.show', $f->order) }}" class="cb-hero-btn"><i class="ri-file-list-3-line me-1"></i>{{ $f->order->number }}</a>@endif</x-slot:actions>
    </x-cb.hero>
    @include('production.partials-nav')
    @include('inventory.partials.flash')
    @if($f->status === 'rescheduled' && $f->rescheduledTo)<div class="cb-banner info"><i class="ri-calendar-2-line"></i><div>Moved to <a href="{{ route('fittings.show', $f->rescheduledTo) }}">{{ $f->rescheduledTo->scheduled_at->timezone($tz)->format('D d M, H:i') }}</a>.</div></div>@endif
    @if($previous)<div class="cb-banner info"><i class="ri-history-line"></i><div>Moved from <a href="{{ route('fittings.show', $previous) }}">{{ $previous->scheduled_at->timezone($tz)->format('D d M, H:i') }}</a>.</div></div>@endif

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card title="Details" icon="ri-information-line">
                <div class="row g-2 small">
                    <div class="col-sm-6"><span class="text-muted d-block">Customer</span><a href="{{ route('customers.show', $f->customer) }}" class="fw-semibold">{{ $f->customer->name }}</a> · {{ $f->customer->phone }}</div>
                    <div class="col-sm-6"><span class="text-muted d-block">Garment</span>{{ $f->line?->description ?? ($f->order ? 'Whole order' : '—') }}</div>
                    @if($f->task)<div class="col-sm-6"><span class="text-muted d-block">Production stage</span><a href="{{ route('production.tasks.show', $f->task) }}">{{ $f->task->name }}</a> · {{ $f->task->status_label }}</div>@endif
                    @if($f->recorded_at)<div class="col-sm-6"><span class="text-muted d-block">Recorded</span>{{ $f->recorder?->name }} · {{ $f->recorded_at->timezone($tz)->format('d M H:i') }}</div>@endif
                    @if($f->notes)<div class="col-12"><span class="text-muted d-block">Notes</span><span style="white-space:pre-line">{{ $f->notes }}</span></div>@endif
                </div>
            </x-cb.card>

            @if($f->status === 'scheduled' && $u->can('Manage fitting'))
            <x-cb.card title="How did it go?" icon="ri-chat-check-line" class="mt-3">
                <form method="POST" action="{{ route('fittings.record', $f) }}" id="outcomeForm">@csrf
                    <div class="d-flex flex-wrap gap-3 mb-3">
                        <label class="d-flex gap-1"><input type="radio" name="outcome_choice" value="approved" checked> Attended — approved</label>
                        <label class="d-flex gap-1"><input type="radio" name="outcome_choice" value="alterations"> Attended — needs alterations</label>
                        <label class="d-flex gap-1"><input type="radio" name="outcome_choice" value="no_show"> Did not come</label>
                    </div>
                    <input type="hidden" name="status" value="attended"><input type="hidden" name="outcome" value="approved">
                    <div id="altBox" class="d-none mb-3">
                        <div class="small text-muted mb-2">Each alteration goes on the production board and holds the fitting stage until it's done.</div>
                        <div id="altRows"></div>
                        <button type="button" class="btn btn-sm btn-light" id="altAdd"><i class="ri-add-line"></i> Another alteration</button>
                    </div>
                    <textarea name="notes" class="form-control mb-3" rows="2" placeholder="Notes"></textarea>
                    <button class="action-btn btn-primary-cb"><i class="ri-check-line"></i>Record</button>
                </form>
                <template id="altTpl">
                    <div class="row g-2 mb-2 alt-row">
                        <div class="col-md-4"><input name="alterations[__][name]" class="form-control form-control-sm" placeholder="e.g. Take in waist 1&quot;" maxlength="120"></div>
                        <div class="col-md-3"><select name="alterations[__][responsible_party]" class="form-select form-select-sm">
                            @foreach(ProductionTask::RESPONSIBLE as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                        <div class="col-md-2"><select name="alterations[__][cost_treatment]" class="form-select form-select-sm">
                            @foreach(ProductionTask::COST_TREATMENT as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                        <div class="col-md-3"><select name="alterations[__][assigned_to]" class="form-select form-select-sm"><option value="">Assign later</option>
                            @foreach($staff as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
                    </div>
                </template>
            </x-cb.card>
            @endif
        </div>
        <div class="col-xl-4">
            <x-cb.card title="Reminders" icon="ri-notification-3-line">
                @forelse($f->reminders->sortBy('remind_at') as $r)
                    <div class="d-flex justify-content-between small py-1 border-bottom"><span>{{ $r->hours_before }}h before · {{ $r->remind_at->timezone($tz)->format('D d M H:i') }}</span>
                        <span class="status-pill {{ ['pending' => 'st-info', 'sent' => 'st-success', 'cancelled' => 'st-muted', 'skipped' => 'st-muted'][$r->status] }}">{{ ucfirst($r->status) }}</span></div>
                @empty
                    <p class="small text-muted mb-0">No reminders (booked too close to the time).</p>
                @endforelse
            </x-cb.card>
            @if($f->status === 'scheduled' && $u->can('Manage fitting'))
            <x-cb.card title="Move" icon="ri-calendar-2-line" class="mt-3">
                <form method="POST" action="{{ route('fittings.reschedule', $f) }}">@csrf
                    <input type="datetime-local" name="scheduled_at" class="form-control form-control-sm mb-2" required value="{{ $local->copy()->addDay()->format('Y-m-d\TH:i') }}">
                    <input name="reason" class="form-control form-control-sm mb-2" maxlength="255" placeholder="Why? (optional)">
                    <button class="action-btn w-100 justify-content-center">Move fitting</button></form>
            </x-cb.card>
            <x-cb.card title="Cancel" icon="ri-close-circle-line" class="mt-3">
                <form method="POST" action="{{ route('fittings.cancel', $f) }}">@csrf
                    <input name="reason" class="form-control form-control-sm mb-2" required maxlength="255" placeholder="Why?">
                    <button class="action-btn w-100 justify-content-center text-danger">Cancel fitting</button></form>
            </x-cb.card>
            @endif
        </div>
    </div>
</div>
</div>
</div>
@push('scripts')
<script>
(function () {
    const form = document.getElementById('outcomeForm'); if (!form) return;
    const box = document.getElementById('altBox'), rows = document.getElementById('altRows'), tpl = document.getElementById('altTpl').innerHTML;
    let n = 0;
    const add = () => { rows.insertAdjacentHTML('beforeend', tpl.replaceAll('__', n++)); };
    document.getElementById('altAdd').addEventListener('click', add);
    form.querySelectorAll('input[name=outcome_choice]').forEach(r => r.addEventListener('change', () => {
        const v = form.querySelector('input[name=outcome_choice]:checked').value;
        form.status.value = v === 'no_show' ? 'no_show' : 'attended';
        form.outcome.value = v === 'no_show' ? '' : v;
        box.classList.toggle('d-none', v !== 'alterations');
        if (v === 'alterations' && !rows.children.length) add();
    }));
})();
</script>
@endpush
@endsection
