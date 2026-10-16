@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
<style>
    .prod-board { display:grid; grid-template-columns: repeat(4, minmax(240px, 1fr)); gap:14px; align-items:start; }
    @media (max-width: 1199px) { .prod-board { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 767px) { .prod-board { grid-template-columns: 1fr; } }
    .prod-col { background:var(--cb-surface-2); border:1px solid var(--cb-border); border-radius:16px; padding:10px; min-height:120px; }
    .prod-col h6 { font-size:13px; text-transform:uppercase; letter-spacing:.04em; color:var(--cb-muted); margin:4px 4px 10px; display:flex; justify-content:space-between; }
    .prod-card { background:var(--cb-surface); border:1px solid var(--cb-border); border-left:3px solid transparent; border-radius:12px; padding:10px 12px; margin-bottom:8px; }
    .prod-card.is-rush { border-left-color:var(--cb-rose); }
    .prod-avatar { width:26px; height:26px; border-radius:50%; background:var(--cb-teal); color:#fff; display:inline-grid; place-items:center; font-size:11px; font-weight:600; }
    .prod-actions form { display:inline; }
</style>
    <x-cb.hero title="Production" icon="ri-scissors-cut-line" subtitle="Every garment's next step: who has it, when it's due, and what's holding it up.">
        <x-slot:actions>@if($manager)<a href="{{ route('production.setup') }}" class="cb-hero-btn"><i class="ri-settings-4-line me-1"></i>Workflows</a>@endif</x-slot:actions>
    </x-cb.hero>
    @include('production.partials-nav')
    @include('inventory.partials.flash')

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><x-cb.stat label="Open tasks" :value="$stats['open']" icon="ri-list-check-2" accent="teal" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Overdue" :value="$stats['overdue']" icon="ri-alarm-warning-line" accent="rose" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Due today" :value="$stats['today']" icon="ri-calendar-todo-line" accent="amber" /></div>
        <div class="col-6 col-lg-3"><a href="{{ route('fittings.index') }}" class="text-decoration-none"><x-cb.stat label="Fittings today" :value="$stats['fittings']" icon="ri-calendar-check-line" accent="violet" /></a></div>
    </div>

    @if($atRisk->isNotEmpty() || $toPlan->isNotEmpty())
    <div class="row g-3 mb-3">
        @if($atRisk->isNotEmpty())
        <div class="col-lg-{{ $toPlan->isNotEmpty() ? 7 : 12 }}">
            <x-cb.card title="Delivery at risk" icon="ri-timer-flash-line" :count="$atRisk->count()" :flush="true">
                @foreach($atRisk as $o)
                    <a href="{{ route('orders.show', $o) }}" class="d-flex justify-content-between gap-2 px-3 py-2 border-bottom text-body">
                        <span class="text-truncate"><span class="status-pill {{ $o->risk_level === 'high' ? 'st-danger' : 'st-warning' }} me-1">{{ $o->risk_level === 'high' ? 'Likely late' : 'Watch' }}</span>
                            <span class="fw-semibold">{{ $o->number }}</span> · {{ $o->customer?->name }}
                            <span class="d-block small text-muted text-truncate">{{ collect($o->risk_reasons)->pluck('text')->first() }}</span></span>
                        <span class="small text-muted text-nowrap">Promised {{ $o->promised_date?->format('d M') }}</span>
                    </a>
                @endforeach
            </x-cb.card>
        </div>
        @endif
        @if($toPlan->isNotEmpty())
        <div class="col-lg-{{ $atRisk->isNotEmpty() ? 5 : 12 }}">
            <x-cb.card title="Confirmed, not yet planned" icon="ri-draft-line" :count="$toPlan->count()" :flush="true">
                @foreach($toPlan as $o)
                    <a href="{{ route('orders.show', $o) }}#production" class="d-flex justify-content-between px-3 py-2 border-bottom text-body">
                        <span><span class="fw-semibold">{{ $o->number }}</span> · {{ $o->customer?->name }}</span>
                        <span class="small text-muted">{{ $o->promised_date ? 'Promised ' . $o->promised_date->format('d M') : 'No date' }}</span></a>
                @endforeach
            </x-cb.card>
        </div>
        @endif
    </div>
    @endif

    <form method="GET" class="cb-toolbar mb-3 rounded-3" style="background:var(--cb-surface);border:1px solid var(--cb-border)">
        <select name="who" class="cb-select" onchange="this.form.submit()">
            <option value="mine" @selected($who === 'mine')>Mine & unassigned</option>
            @if($manager)<option value="all" @selected($who === 'all')>Everyone</option><option value="unassigned" @selected($who === 'unassigned')>Unassigned</option>
                @foreach($staff as $s)<option value="{{ $s->id }}" @selected((string) $who === (string) $s->id)>{{ $s->name }}</option>@endforeach
            @endif
        </select>
        <select name="stage" class="cb-select" onchange="this.form.submit()"><option value="">All stages</option>
            @foreach($stages as $s)<option @selected(request('stage') === $s)>{{ $s }}</option>@endforeach</select>
        <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ request('q') }}" placeholder="Order, task or customer…"></div>
        @if($waiting)<span class="small text-muted ms-auto"><i class="ri-hourglass-line"></i> {{ $waiting }} waiting on earlier stages</span>@endif
    </form>

    <div class="prod-board">
        @foreach(['pending' => 'To do', 'in_progress' => 'In progress', 'on_hold' => 'On hold', 'done' => 'Done (3 days)'] as $k => $label)
            <div class="prod-col">
                <h6><span>{{ $label }}</span><span>{{ $columns[$k]->count() }}</span></h6>
                @forelse($columns[$k] as $t)
                    @include('production.partials-card', ['t' => $t])
                @empty
                    <div class="text-center small text-muted py-3">Nothing here</div>
                @endforelse
            </div>
        @endforeach
    </div>
</div>
</div>
</div>
@endsection
