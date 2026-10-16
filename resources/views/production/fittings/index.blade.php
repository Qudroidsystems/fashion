@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    <x-cb.hero title="Fittings" icon="ri-calendar-check-line" subtitle="Who is coming in, when, and for which garment. Customers and staff get reminders before each one.">
        <x-slot:actions>@can('Manage fitting')<a href="{{ route('fittings.create') }}" class="cb-hero-btn"><i class="ri-calendar-event-line me-1"></i>Book a fitting</a>@endcan</x-slot:actions>
    </x-cb.hero>
    @include('production.partials-nav')
    @include('inventory.partials.flash')
    @if($overdue && $when !== 'past')<div class="cb-banner warning"><i class="ri-alarm-warning-line"></i><div>{{ $overdue }} past fitting(s) still need an outcome. <a href="{{ route('fittings.index', ['when' => 'past']) }}">Record them</a>.</div></div>@endif

    <x-cb.card title="{{ $when === 'past' ? 'Past fittings' : 'Coming up' }}" icon="ri-calendar-line" :count="$fittings->total()" :flush="true">
        <form method="GET" class="cb-toolbar">
            <select name="when" class="cb-select" onchange="this.form.submit()"><option value="upcoming" @selected($when !== 'past')>Coming up</option><option value="past" @selected($when === 'past')>Past</option></select>
            <select name="staff" class="cb-select" onchange="this.form.submit()"><option value="">Everyone</option>
                @foreach($staff as $s)<option value="{{ $s->id }}" @selected((string) request('staff') === (string) $s->id)>{{ $s->name }}</option>@endforeach</select>
        </form>
        @forelse($days as $day => $list)
            <div class="px-3 py-2 small fw-semibold text-uppercase" style="background:var(--cb-surface-2);letter-spacing:.04em">{{ \Carbon\Carbon::parse($day)->isToday() ? 'Today' : (\Carbon\Carbon::parse($day)->isTomorrow() ? 'Tomorrow' : \Carbon\Carbon::parse($day)->format('l d M')) }}</div>
            @foreach($list as $f)
                <a href="{{ route('fittings.show', $f) }}" class="d-flex gap-3 px-3 py-2 border-bottom text-body align-items-center">
                    <span class="fw-bold text-nowrap" style="width:64px">{{ $f->scheduled_at->timezone($tz)->format('H:i') }}</span>
                    <span class="flex-grow-1 text-truncate"><span class="fw-semibold">{{ $f->customer->name }}</span>
                        <span class="d-block small text-muted text-truncate">{{ $f->line?->description ?? $f->order?->title ?? 'Fitting' }}{{ $f->order ? ' · ' . $f->order->number : '' }}{{ $f->branch ? ' · ' . $f->branch->name : '' }}</span></span>
                    <span class="small text-muted d-none d-md-inline">{{ $f->staff?->name }}</span>
                    <span class="status-pill {{ $f->status === 'scheduled' && $f->scheduled_at->isPast() ? 'st-warning' : $f->status_pill }}">{{ $f->status === 'scheduled' && $f->scheduled_at->isPast() ? 'Needs outcome' : $f->status_label }}</span>
                </a>
            @endforeach
        @empty
            <div class="empty-state"><i class="ri-calendar-line"></i><h6>No fittings {{ $when === 'past' ? 'yet' : 'booked' }}</h6></div>
        @endforelse
    </x-cb.card>
    <div class="mt-3">{{ $fittings->links() }}</div>
</div>
</div>
</div>
@endsection
