@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php
        use App\Support\Money;
        use App\Support\Orders\OrderStatus;
        $tz = $tenant->timezone ?: config('app.timezone');
        $hour = now($tz)->hour;
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        $doneCount = collect($checklist)->where('done', true)->count();
        $setupDone = $doneCount === count($checklist);
        $m = $figures['money'] ?? null; $o = $figures['orders'] ?? null; $p = $figures['production'] ?? null;
        $s = $figures['stock'] ?? null; $fu = $figures['follow_ups'] ?? null;
        $u = auth()->user();
    @endphp

    <x-cb.hero :title="$greeting . ', ' . $u->first_name" icon="ri-store-2-line"
               :subtitle="'Here is what is happening at ' . $tenant->name . (isset($figures['branch']) ? ' — ' . $figures['branch'] : '') . ' today.'">
        <x-slot:pills>
            <span class="cb-meta-pill"><i class="ri-calendar-line me-1"></i>{{ now($tz)->format('l, j F Y') }}</span>
            @if($tenant->status === 'trial' && $tenant->trial_ends_at)
                <span class="cb-meta-pill"><i class="ri-hourglass-line me-1"></i>Trial ends {{ $tenant->trial_ends_at->timezone($tz)->format('j M Y') }}</span>
            @endif
            @if(isset($figures['branch']))<span class="cb-meta-pill"><i class="ri-git-branch-line me-1"></i>{{ $figures['branch'] }} only</span>@endif
        </x-slot:pills>
        <x-slot:actions>
            @can('Create order')<a href="{{ route('orders.create') }}" class="cb-hero-btn"><i class="ri-add-line me-1"></i>New order</a>@endcan
            @can('View reports')<a href="{{ route('reports.index') }}" class="cb-hero-btn"><i class="ri-bar-chart-box-line me-1"></i>Reports</a>@endcan
        </x-slot:actions>
    </x-cb.hero>

    @if($tenant->status === 'past_due')
        <div class="cb-banner warning"><i class="ri-error-warning-line"></i><div><strong>Payment overdue.</strong> Your subscription payment is overdue. Please settle it to avoid interruption.</div></div>
    @endif

    <style>
        .dash-link { text-decoration: none; display: block; height: 100%; }
        .dash-link .cb-stat { height: 100%; transition: transform .15s; }
        .dash-link:hover .cb-stat { transform: translateY(-2px); }
        .pipe { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
        .pipe a { text-decoration: none; padding: 12px; border-radius: 12px; border: 1px solid var(--cb-border); background: var(--cb-surface); display: block; }
        .pipe a:hover { border-color: var(--cb-teal); }
        .pipe .n { font-size: 1.5rem; font-weight: 700; color: var(--cb-heading); line-height: 1.1; }
        .pipe .l { font-size: .78rem; color: var(--cb-muted); }
        .pipe .bar { height: 4px; border-radius: 4px; background: var(--cb-border); margin-top: 8px; overflow: hidden; }
        .pipe .bar span { display: block; height: 100%; background: var(--cb-teal); }
        .dash-list li { display: flex; align-items: center; gap: 10px; padding: 10px 20px; border-bottom: 1px solid var(--cb-border); font-size: .875rem; }
        .dash-list li:last-child { border-bottom: 0; }
        .load { flex: 1; height: 6px; border-radius: 6px; background: var(--cb-border); overflow: hidden; display: flex; }
        .load .ok { background: var(--cb-teal); } .load .late { background: var(--cb-rose); }
        @media (max-width: 575px) { .cb-stat .stat-value { font-size: 1.15rem; white-space: nowrap; } }
        @media (max-width: 575px) { .pipe { grid-template-columns: repeat(2, 1fr); } }
    </style>

    {{-- Headline figures --}}
    <div class="row g-3 mb-3">
        @if($m)
            <div class="col-6 col-xl-3"><a class="dash-link" href="{{ $u->can('View reports') && $u->can('View financial reports') ? route('reports.show', ['sales', 'period' => 'today']) : route('payments.index') }}">
                <x-cb.stat label="Collected today" :value="Money::format($m['collected_today'])" icon="ri-hand-coin-line" accent="teal" :hint="'This month ' . Money::format($m['collected_month'])" /></a></div>
            <div class="col-6 col-xl-3"><a class="dash-link" href="{{ $u->can('View reports') ? route('reports.show', 'receivables') : route('orders.index') }}">
                <x-cb.stat label="Owed to you" :value="Money::format($m['owed'])" icon="ri-wallet-3-line" accent="amber" :hint="$m['handed_unpaid'] > 0 ? Money::format($m['handed_unpaid']) . ' on garments already handed over' : $m['owed_hint']" /></a></div>
            @isset($m['earned_month'])
            <div class="col-6 col-xl-3"><a class="dash-link" href="{{ $u->can('View reports') ? route('reports.show', 'sales') : '#' }}">
                <x-cb.stat label="Sales earned this month" :value="Money::format($m['earned_month'])" icon="ri-shopping-bag-3-line" accent="violet" :hint="'Deposits held ' . Money::format($m['deposits'])" /></a></div>
            @endisset
        @endif
        @if($o)
            <div class="col-6 col-xl-3"><a class="dash-link" href="{{ route('orders.index') }}">
                <x-cb.stat label="Overdue orders" :value="$o['overdue']" icon="ri-alarm-warning-line" :accent="$o['overdue'] ? 'rose' : 'green'" :hint="$o['due_week'] . ' due in the next 7 days · ' . $o['ready'] . ' ready to collect'" /></a></div>
        @endif
        @if($p)
            <div class="col-6 col-xl-3"><a class="dash-link" href="{{ route('production.board') }}">
                <x-cb.stat label="Workroom tasks open" :value="$p['open']" icon="ri-scissors-cut-line" accent="sky" :hint="$p['overdue'] . ' late · ' . $p['fittings'] . ' fitting(s) today'" /></a></div>
        @endif
        @if($s)
            <div class="col-6 col-xl-3"><a class="dash-link" href="{{ $u->can('View reports') ? route('reports.show', 'stock') : route('inventory.index') }}">
                <x-cb.stat label="Stock running low" :value="$s['low']" icon="ri-archive-line" :accent="$s['out'] ? 'rose' : 'amber'" :hint="$s['out'] . ' out of stock'" /></a></div>
        @endif
        @if($fu)
            <div class="col-6 col-xl-3"><a class="dash-link" href="{{ route('follow-ups.index') }}">
                <x-cb.stat label="My follow-ups due" :value="$fu['mine']" icon="ri-alarm-line" accent="amber" :hint="$fu['overdue'] . ' overdue across the house'" /></a></div>
        @endif
        @can('View customer')
            <div class="col-6 col-xl-3"><a class="dash-link" href="{{ route('customers.index') }}">
                <x-cb.stat label="Customers" :value="number_format($stats['customers'])" icon="ri-user-heart-line" accent="teal" :hint="$stats['new_customers'] . ' new this month'" /></a></div>
        @endcan
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            @if($m)
            <x-cb.card title="Money collected — last 14 days" icon="ri-line-chart-line" class="mb-3">
                <x-slot:tools>@can('View reports')<a href="{{ route('reports.show', ['sales', 'period' => 'last_30']) }}" class="action-btn">Sales report</a>@endcan</x-slot:tools>
                <div id="dashMoney" style="min-height:240px"></div>
            </x-cb.card>
            @endif

            @if($o)
            <x-cb.card title="Order pipeline" icon="ri-flow-chart" :count="$o['open']" class="mb-3">
                <x-slot:tools>@if($o['drafts'])<a href="{{ route('orders.index', ['status' => 'draft']) }}" class="small">{{ $o['drafts'] }} draft(s)</a>@endif</x-slot:tools>
                <div class="pipe">
                    @foreach($o['pipeline'] as $status => $n)
                        <a href="{{ route('orders.index', ['status' => $status]) }}">
                            <div class="n">{{ $n }}</div><div class="l">{{ OrderStatus::label($status) }}</div>
                            <div class="bar"><span style="width:{{ $o['open'] ? round(100 * $n / $o['open']) : 0 }}%"></span></div>
                        </a>
                    @endforeach
                </div>
            </x-cb.card>

            <x-cb.card title="Due this week and overdue" icon="ri-calendar-todo-line" :flush="true" class="mb-3">
                @if($o['due']->isEmpty())
                    <div class="empty-state py-4"><i class="ri-checkbox-circle-line"></i><h6 class="mb-0">Nothing due in the next 7 days.</h6></div>
                @else
                    <ul class="list-unstyled mb-0 dash-list">
                        @foreach($o['due'] as $d)
                            @php $late = $d->promised_date->toDateString() < $o['today'] && $d->status !== 'ready'; @endphp
                            <li>
                                <div class="flex-grow-1">
                                    <a href="{{ route('orders.show', $d) }}" class="fw-semibold">{{ $d->number }}</a>
                                    <span class="text-muted">· {{ $d->customer?->name }}@if($d->title) · {{ $d->title }}@endif</span>
                                    @if($d->priority === 'rush')<span class="status-pill st-danger ms-1">Rush</span>@endif
                                    @if($d->risk_level === 'high')<span class="status-pill st-warning ms-1">At risk</span>@endif
                                </div>
                                <span class="status-pill {{ $d->status === 'ready' ? 'st-success' : 'st-info' }}">{{ OrderStatus::label($d->status) }}</span>
                                <span class="text-nowrap small {{ $late ? 'text-danger fw-semibold' : 'text-muted' }}" style="min-width:86px;text-align:right">{{ $late ? 'Late · ' : '' }}{{ $d->promised_date->format('D j M') }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-cb.card>
            @endif

            @if($p && $p['people'])
            <x-cb.card title="Workroom load" icon="ri-team-line" :flush="true" class="mb-3">
                <x-slot:tools><a href="{{ route('production.board') }}" class="action-btn">Board</a></x-slot:tools>
                @php $max = max(1, collect($p['people'])->max('open')); @endphp
                <ul class="list-unstyled mb-0 dash-list">
                    @foreach($p['people'] as $w)
                        <li>
                            <a href="{{ route('production.board', ['who' => $w['id'] ?? 'unassigned']) }}" style="width:150px" class="text-truncate">{{ $w['name'] }}</a>
                            <div class="load"><span class="ok" style="width:{{ round(100 * ($w['open'] - $w['late']) / $max) }}%"></span><span class="late" style="width:{{ round(100 * $w['late'] / $max) }}%"></span></div>
                            <span class="small text-nowrap" style="min-width:90px;text-align:right">{{ $w['open'] }} open @if($w['late']) · <span class="text-danger">{{ $w['late'] }} late</span>@endif</span>
                        </li>
                    @endforeach
                </ul>
            </x-cb.card>
            @endif
        </div>

        <div class="col-xl-4">
            @unless($setupDone)
            <x-cb.card title="Getting started" icon="ri-rocket-2-line" :count="$doneCount . '/' . count($checklist)" class="mb-3">
                <div class="progress-track mb-3"><div class="progress-fill" style="width: {{ round($doneCount / max(count($checklist), 1) * 100) }}%"></div></div>
                <ul class="list-unstyled mb-0">
                    @foreach($checklist as $item)
                        <li class="d-flex align-items-center gap-2 py-2 border-bottom">
                            <i class="{{ $item['done'] ? 'ri-checkbox-circle-fill text-success' : 'ri-checkbox-blank-circle-line text-muted' }} fs-5"></i>
                            <span class="flex-grow-1 small {{ $item['done'] ? 'text-muted text-decoration-line-through' : '' }}">{{ $item['label'] }}</span>
                            @can($item['can'])@if(! $item['done'] && Route::has($item['route']))<a href="{{ route($item['route']) }}" class="action-btn btn-primary-cb">Open</a>@endif @endcan
                        </li>
                    @endforeach
                </ul>
            </x-cb.card>
            @endunless

            @if($s && $s['items'])
            <x-cb.card title="Stock running low" icon="ri-archive-line" :count="$s['low']" :flush="true" class="mb-3">
                <ul class="list-unstyled mb-0 dash-list">
                    @foreach($s['items'] as $l)
                        <li>
                            <a href="{{ route('inventory.items.show', $l['item']) }}" class="flex-grow-1 text-truncate">{{ $l['item']->name }}@if($l['branch'])<span class="text-muted small"> · {{ $l['branch'] }}</span>@endif</a>
                            <span class="small text-nowrap {{ $l['out'] ? 'text-danger fw-semibold' : 'text-warning' }}">{{ $l['out'] ? 'Out' : rtrim(rtrim(number_format($l['available'], 3), '0'), '.') . ' ' . $l['item']->unit . ' left' }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-cb.card>
            @endif

            @if($reports->isNotEmpty())
            <x-cb.card title="Reports" icon="ri-bar-chart-box-line" :flush="true" class="mb-3">
                <ul class="list-unstyled mb-0 dash-list">
                    @foreach($reports as $r)
                        <li><i class="{{ $r->icon() }} text-muted"></i><a href="{{ route('reports.show', $r->key()) }}" class="flex-grow-1">{{ $r->title() }}</a>@if($r->lockedBy())<i class="ri-lock-line text-muted small"></i>@endif</li>
                    @endforeach
                    <li><a href="{{ route('reports.index') }}" class="small">All reports →</a></li>
                </ul>
            </x-cb.card>
            @endif

            @if($birthdays->count())
            <x-cb.card title="Birthdays this week" icon="ri-cake-2-line" class="mb-3" :flush="true">
                <ul class="list-unstyled mb-0 dash-list">
                    @foreach($birthdays as $b)
                        <li class="justify-content-between">
                            <a href="{{ route('customers.show', $b) }}">{{ $b->first_name }} {{ $b->last_name }}</a>
                            <span class="small {{ $b->next_bday->isToday() ? 'fw-bold text-success' : 'text-muted' }}">{{ $b->next_bday->isToday() ? 'Today' : $b->next_bday->format('D j M') }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-cb.card>
            @endif

            @if($recent->count())
            <x-cb.card title="Recent activity" icon="ri-history-line" class="mb-3" :flush="true">
                <x-slot:tools><a href="{{ route('audit-logs.index') }}" class="action-btn">View all</a></x-slot:tools>
                <ul class="list-unstyled mb-0 dash-list">
                    @foreach($recent as $log)
                        <li class="d-block">
                            <div class="fw-semibold">{{ \Illuminate\Support\Str::headline(str_replace('.', ' ', $log->action)) }}</div>
                            <div class="text-muted small">{{ $log->user?->name ?? 'System' }} · {{ $log->created_at->timezone($tz)->diffForHumans() }}</div>
                        </li>
                    @endforeach
                </ul>
            </x-cb.card>
            @endif

            @if($setupDone)
            <div class="small text-muted px-1 mb-3"><i class="ri-team-line me-1"></i>{{ $stats['staff'] }} staff · {{ $stats['branches'] }} branch(es) · {{ $stats['roles'] }} roles</div>
            @endif
        </div>
    </div>

</div>
</div>
</div>
@endsection

@if(! empty($figures['money']))
@push('scripts')
<script src="{{ asset('theme/layouts/assets/libs/apexcharts/apexcharts.min.js') }}"></script>
<script>
    (function () {
        var el = document.getElementById('dashMoney');
        if (! el || typeof ApexCharts === 'undefined') return;
        var t = @json($figures['money']['trend']), symbol = @json(Money::symbol());
        var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        var short = function (v) { var a = Math.abs(v), s = v < 0 ? '−' : ''; return a >= 1e6 ? s + symbol + (a / 1e6).toFixed(1) + 'm' : a >= 1e3 ? s + symbol + Math.round(a / 1e3) + 'k' : s + symbol + Math.round(a); };
        new ApexCharts(el, {
            chart: { type: 'area', height: 240, toolbar: { show: false }, fontFamily: 'inherit', foreColor: dark ? '#94a3b8' : '#64748b', sparkline: { enabled: false } },
            series: [{ name: 'Collected', data: t.data }], colors: ['#0d9488'],
            stroke: { curve: 'smooth', width: 2.5 }, fill: { type: 'gradient', gradient: { opacityFrom: .35, opacityTo: .02 } },
            dataLabels: { enabled: false }, xaxis: { categories: t.labels, labels: { rotate: 0, hideOverlappingLabels: true } },
            yaxis: { labels: { formatter: short } }, grid: { borderColor: dark ? '#2b3446' : '#e2e8f0', strokeDashArray: 4 },
            tooltip: { theme: dark ? 'dark' : 'light', y: { formatter: function (v) { return symbol + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 }); } } },
        }).render();
    })();
</script>
@endpush
@endif
