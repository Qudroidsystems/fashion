@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
@php $q = fn (array $over) => route('appointments.index', array_filter(array_merge(request()->only('staff', 'days', 'from'), $over))); @endphp
    <x-cb.hero title="Appointments" icon="ri-calendar-todo-line" subtitle="Who is coming in, when and why — consultations, measurements, collections and fittings in one diary. Customers get a confirmation and a reminder the day before.">
        <x-slot:actions><a href="{{ route('appointments.create') }}" class="cb-hero-btn"><i class="ri-calendar-event-line me-1"></i>Book an appointment</a></x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.flash')

    <x-cb.card :flush="true">
        <form method="GET" class="cb-toolbar flex-wrap">
            <a class="btn btn-light btn-sm" href="{{ $q(['from' => $from->subDays($days)->toDateString()]) }}" title="Earlier"><i class="ri-arrow-left-s-line"></i></a>
            <input type="date" name="from" class="form-control form-control-sm" style="max-width:160px" value="{{ $from->toDateString() }}" onchange="this.form.submit()">
            <a class="btn btn-light btn-sm" href="{{ $q(['from' => $from->addDays($days)->toDateString()]) }}" title="Later"><i class="ri-arrow-right-s-line"></i></a>
            @unless($from->isSameDay($today))<a class="btn btn-link btn-sm" href="{{ $q(['from' => null]) }}">Today</a>@endunless
            <select name="days" class="cb-select" onchange="this.form.submit()">
                @foreach([1 => 'One day', 7 => 'A week', 14 => 'Two weeks', 30 => 'A month'] as $d => $l)<option value="{{ $d }}" @selected($days === $d)>{{ $l }}</option>@endforeach
            </select>
            <select name="staff" class="cb-select" onchange="this.form.submit()"><option value="">Everyone</option>
                @foreach($staff as $s)<option value="{{ $s->id }}" @selected((string) request('staff') === (string) $s->id)>{{ $s->name }}</option>@endforeach</select>
            <span class="ms-auto small text-muted">{{ $from->format('j M') }}@if($days > 1) – {{ $to->format('j M Y') }}@else {{ $from->format('Y') }}@endif</span>
        </form>

        @forelse($diary as $day => $entries)
            @php $d = \Carbon\CarbonImmutable::parse($day, $tz); @endphp
            <div class="px-3 py-2 small fw-semibold text-uppercase d-flex justify-content-between" style="background:var(--cb-surface-2);letter-spacing:.04em">
                <span>{{ $d->isSameDay($today) ? 'Today' : ($d->isSameDay($today->addDay()) ? 'Tomorrow' : $d->format('l j M')) }}</span>
                <span class="text-muted">{{ $entries->count() }}</span>
            </div>
            @foreach($entries as $e)
                @php $m = $e['model']; $isFit = $e['kind'] === 'fitting'; @endphp
                <a href="{{ $isFit ? route('fittings.show', $m) : route('appointments.show', $m) }}" class="d-flex gap-3 px-3 py-2 border-bottom text-body align-items-center">
                    <span class="text-nowrap" style="width:72px"><span class="fw-bold">{{ $e['at']->copy()->timezone($tz)->format('H:i') }}</span><span class="d-block small text-muted">{{ $m->duration_minutes }} min</span></span>
                    <span class="flex-grow-1 text-truncate">
                        <span class="fw-semibold">{{ $m->customer?->name }}</span>
                        <span class="d-block small text-muted text-truncate">
                            <i class="{{ $isFit ? 'ri-t-shirt-air-line' : 'ri-calendar-todo-line' }} me-1"></i>{{ $isFit ? 'Fitting' . ($m->line ? ' · ' . $m->line->description : '') : $m->type_label }}{{ $m->order ? ' · ' . $m->order->number : '' }}{{ $m->branch ? ' · ' . $m->branch->name : '' }}
                        </span>
                    </span>
                    <span class="small text-muted d-none d-md-inline">{{ $m->staff?->name }}</span>
                    @php $past = $e['at']->isPast() && in_array($m->status, ['booked', 'scheduled'], true); @endphp
                    <span class="status-pill {{ $past ? 'st-warning' : $m->status_pill }}">{{ $past ? 'Needs outcome' : $m->status_label }}</span>
                </a>
            @endforeach
        @empty
            <div class="empty-state"><i class="ri-calendar-line"></i><h6>Nothing booked</h6><p class="small text-muted">Book a consultation, measurement or collection with <a href="{{ route('appointments.create') }}">Book an appointment</a>.</p></div>
        @endforelse
    </x-cb.card>
</div>
</div>
</div>
@endsection
