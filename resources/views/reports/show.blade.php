@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php
        use App\Services\Reports\ReportFormat;
        $q = $f->query();
        $accent = fn ($a) => ['blue' => 'sky'][$a] ?? $a;
        $numeric = ['money', 'int', 'qty', 'percent', 'days'];
        $rowLimit = 250;
    @endphp

    <x-cb.hero :title="$report->title()" :icon="$report->icon()" :subtitle="$report->description()" :back="route('reports.index')" back-label="All reports">
        <x-slot:actions>
            @unless($lock)<a href="{{ route('reports.print', [$report->key()] + $q) }}" target="_blank" rel="noopener" class="cb-hero-btn"><i class="ri-printer-line me-1"></i>Print</a>@endunless
            @if($canExport)
                <div class="dropdown">
                    <button class="cb-hero-btn dropdown-toggle" data-bs-toggle="dropdown" type="button"><i class="ri-download-2-line me-1"></i>Export</button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="{{ route('reports.export', [$report->key(), 'pdf'] + $q) }}"><i class="ri-file-pdf-2-line me-2"></i>PDF (to print or share)</a></li>
                        @if($canSheet)
                            <li><a class="dropdown-item" href="{{ route('reports.export', [$report->key(), 'xlsx'] + $q) }}"><i class="ri-file-excel-2-line me-2"></i>Excel (.xlsx)</a></li>
                            <li><a class="dropdown-item" href="{{ route('reports.export', [$report->key(), 'csv'] + $q) }}"><i class="ri-file-text-line me-2"></i>CSV</a></li>
                        @else
                            <li><span class="dropdown-item-text small text-muted"><i class="ri-lock-line me-1"></i>Excel and CSV come with a plan that includes exports. <a href="{{ route('billing.index') }}">See plans</a></span></li>
                        @endif
                    </ul>
                </div>
            @endif
        </x-slot:actions>
        <x-slot:pills>
            <span class="cb-meta-pill"><i class="ri-calendar-line me-1"></i>{{ $report->periodText($f) }}</span>
            @foreach($labels as $k => $v)<span class="cb-meta-pill">{{ $k }}: {{ $v }}</span>@endforeach
            @if($f->branchLocked)<span class="cb-meta-pill"><i class="ri-git-branch-line me-1"></i>Your branch only</span>@endif
        </x-slot:pills>
    </x-cb.hero>
    @include('inventory.partials.flash')

    <style>
        .rep-filters { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
        .rep-quick { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 4px; }
        .rep-quick a { font-size: .78rem; padding: 5px 12px; border-radius: 999px; border: 1px solid var(--cb-border); color: var(--cb-text); text-decoration: none; background: var(--cb-surface); white-space: nowrap; }
        .rep-quick a:hover { border-color: var(--cb-teal); color: var(--cb-teal); }
        .rep-quick a.on { background: var(--cb-teal); border-color: var(--cb-teal); color: #fff; font-weight: 600; }
        .rep-date { display: flex; flex-direction: column; gap: 3px; margin: 0; }
        .rep-date span { font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; font-weight: 700; color: var(--cb-muted); }
        .rep-table td.num, .rep-table th.num { text-align: right; white-space: nowrap; }
        .rep-table tr.strong td { font-weight: 700; border-top: 1px solid var(--cb-border); }
        .rep-table tr.indent td:first-child { padding-left: 2.2rem; color: var(--cb-muted); }
        .rep-table tr.tone-danger td:first-child { box-shadow: inset 3px 0 0 var(--cb-rose); }
        .rep-table tr.tone-warning td:first-child { box-shadow: inset 3px 0 0 var(--cb-amber); }
        .rep-table thead tr { background: linear-gradient(135deg, var(--cb-navy), var(--cb-navy-2)); }
        .rep-table thead th { background: transparent; }
        .rep-table tfoot td { font-weight: 700; background: var(--cb-page); border-top: 2px solid var(--cb-border); padding: 12px 14px; }
        .rep-notes li { margin-bottom: .35rem; }
        .rep-more { display: flex; gap: 8px; flex-wrap: wrap; }
        .rep-more a { font-size: .8rem; padding: 6px 12px; border-radius: 999px; border: 1px solid var(--cb-border); text-decoration: none; color: var(--cb-text); background: var(--cb-surface); }
        .rep-more a:hover { border-color: var(--cb-teal); color: var(--cb-teal); }
        @media (max-width: 575px) { .cb-stat .stat-value { font-size: 1.15rem; white-space: nowrap; } }
        @media (max-width: 767px) {
            .rep-filters > * { flex: 1 1 45%; } .rep-filters .action-btn { flex-basis: 100%; justify-content: center; }
            .rep-filters > .rep-quick { flex: 1 1 100%; flex-wrap: nowrap; overflow-x: auto; padding-bottom: 4px; } .rep-date input { width: 100%; }
            .rep-table.stack tfoot tr { display: block; padding: 6px 0; }
            .rep-table.stack tfoot td { display: flex; justify-content: space-between; border: none; padding: 7px 16px; }
            .rep-table.stack tfoot td:empty { display: none; }
            .rep-table.stack tfoot td::before { content: attr(data-label); font-size: 11px; text-transform: uppercase; letter-spacing: .5px; }
            .rep-table tr.indent td:first-child { padding-left: 16px; }
        }
    </style>

    {{-- Filters (FR-REP-002) --}}
    @if(array_filter($options))
    <form method="GET" class="cb-card mb-3" id="repFilters">
        <div class="cb-card-body rep-filters">
            @if($options['presets'])
                <div class="rep-quick w-100" role="group" aria-label="Quick periods">
                    @foreach(\App\Services\Reports\ReportFilters::QUICK as $k)
                        <a href="{{ route('reports.show', [$report->key(), 'period' => $k] + array_diff_key($q, array_flip(['period', 'from', 'to']))) }}"
                           class="{{ $f->preset === $k ? 'on' : '' }}">{{ $options['presets'][$k] }}</a>
                    @endforeach
                </div>
                <input type="hidden" name="period" value="{{ $f->preset }}">
                <label class="rep-date"><span>From</span><input type="date" name="from" class="cb-select" value="{{ $f->fromDate() }}" max="{{ $f->toDate() }}" required></label>
                <label class="rep-date"><span>To</span><input type="date" name="to" class="cb-select" value="{{ $f->toDate() }}" min="{{ $f->fromDate() }}" required></label>
            @endif
            @if($options['branches'])
                <select name="branch" class="cb-select" aria-label="Branch"><option value="">All branches</option>
                    @foreach($options['branches'] as $id => $name)<option value="{{ $id }}" @selected($f->branchId === (int) $id)>{{ $name }}</option>@endforeach</select>
            @endif
            @if($options['staff'])
                <select name="staff" class="cb-select" aria-label="Staff"><option value="">Everyone</option>
                    @foreach($options['staff'] as $id => $name)<option value="{{ $id }}" @selected($f->staffId === (int) $id)>{{ $name }}</option>@endforeach</select>
            @endif
            @if($options['category'])
                <select name="category" class="cb-select" aria-label="Category"><option value="">All categories</option>
                    @foreach($options['category'] as $k => $v)<option value="{{ $k }}" @selected((string) $f->category === (string) $k)>{{ $v }}</option>@endforeach</select>
            @endif
            @if($options['status'])
                <select name="status" class="cb-select" aria-label="Stage"><option value="">Any stage</option>
                    @foreach($options['status'] as $k => $v)<option value="{{ $k }}" @selected((string) $f->status === (string) $k)>{{ $v }}</option>@endforeach</select>
            @endif
            <button class="action-btn btn-primary-cb ms-md-auto"><i class="ri-filter-3-line"></i>Show report</button>
            @if($options['presets'] && $report->asOf())
                <div class="w-100 small text-muted"><i class="ri-information-line me-1"></i>Amounts are always as of now; the dates choose which {{ $report->rangeNoun() === 'events on' ? 'projects (by event date)' : 'orders and invoices' }} are included. “All time” includes everything.</div>
            @endif
        </div>
    </form>
    @endif

    @if($lock)
        <div class="cb-card">
            <div class="cb-card-body text-center py-5">
                <div class="mb-3" style="font-size:42px;color:var(--cb-muted)"><i class="ri-lock-line"></i></div>
                <h5 class="mb-2">This report is not in your plan yet</h5>
                <p class="text-muted mx-auto mb-3" style="max-width:520px">{{ $lockText }}</p>
                <a href="{{ route('billing.index') }}" class="action-btn btn-primary-cb"><i class="ri-vip-crown-line"></i>See plans</a>
            </div>
        </div>
    @else
        {{-- Summary cards --}}
        @if($result->cards)
        <div class="row g-3 mb-3">
            @foreach($result->cards as $c)
                <div class="col-6 col-xl-3"><x-cb.stat :label="$c['label']" :value="ReportFormat::cell($c['value'], $c['format'])" :icon="$c['icon']" :accent="$accent($c['accent'])" :hint="$c['hint']" /></div>
            @endforeach
        </div>
        @endif

        {{-- Chart --}}
        @if($result->chart && collect($result->chart['series'])->flatMap(fn ($s) => $s['data'])->filter(fn ($v) => (float) $v != 0)->isNotEmpty())
            <x-cb.card :title="$result->chart['title'] ?? 'At a glance'" icon="ri-bar-chart-2-line" class="mb-3">
                <div id="repChart" style="min-height:{{ $result->chart['type'] === 'donut' ? 300 : 320 }}px"></div>
            </x-cb.card>
        @endif

        {{-- Tables --}}
        @foreach($result->tables as $t)
            <x-cb.card :title="$t['title']" icon="ri-table-line" :count="count($t['rows']) ?: null" :flush="true" class="mb-3">
                @if(! $t['rows'])
                    <div class="empty-state py-4"><i class="ri-inbox-line"></i><h6 class="mb-0">{{ $t['empty'] ?? 'Nothing to show for these filters.' }}</h6></div>
                @else
                    <div class="table-responsive">
                    <table class="cb-table stack rep-table mb-0">
                        <thead><tr>@foreach($t['columns'] as $c)<th class="{{ in_array($c['format'], $numeric) ? 'num' : '' }}">{{ $c['label'] }}</th>@endforeach</tr></thead>
                        <tbody>
                        @foreach(array_slice($t['rows'], 0, $rowLimit) as $row)
                            <tr class="{{ ! empty($row['_strong']) ? 'strong' : '' }} {{ ! empty($row['_indent']) ? 'indent' : '' }} {{ ! empty($row['_tone']) ? 'tone-' . $row['_tone'] : '' }} {{ ! empty($row['_muted']) ? 'opacity-50' : '' }}">
                                @foreach($t['columns'] as $i => $c)
                                    @php $text = ReportFormat::cell($row[$c['key']] ?? null, $c['format']); @endphp
                                    <td data-label="{{ $c['label'] }}" class="{{ in_array($c['format'], $numeric) ? 'num' : '' }} {{ in_array($c['format'], $numeric) && is_numeric($row[$c['key']] ?? null) && $row[$c['key']] < 0 ? 'text-danger' : '' }}">
                                        @if($i === 0 && ! empty($row['_link']))<a href="{{ $row['_link'] }}" class="fw-semibold">{{ $text }}</a>@else{{ $text }}@endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                        </tbody>
                        @if($t['totals'])
                            <tfoot><tr>@foreach($t['columns'] as $c)<td data-label="{{ $c['label'] }}" class="{{ in_array($c['format'], $numeric) ? 'num' : '' }}">{{ isset($t['totals'][$c['key']]) ? ReportFormat::cell($t['totals'][$c['key']], $c['format']) : '' }}</td>@endforeach</tr></tfoot>
                        @endif
                    </table>
                    </div>
                    @if(count($t['rows']) > $rowLimit)
                        <div class="small text-muted px-3 py-2 border-top">Showing the first {{ $rowLimit }} of {{ number_format(count($t['rows'])) }} rows @if($canExport) — export to see them all @endif.</div>
                    @endif
                @endif
                @if($t['note'])<div class="small text-muted px-3 py-2 border-top"><i class="ri-information-line me-1"></i>{{ $t['note'] }}</div>@endif
            </x-cb.card>
        @endforeach

        @if($result->notes)
            <x-cb.card title="How these figures are worked out" icon="ri-information-line" class="mb-3">
                <ul class="rep-notes small text-muted mb-0 ps-3">@foreach($result->notes as $n)<li>{{ $n }}</li>@endforeach</ul>
            </x-cb.card>
        @endif
    @endif

    @if($others->isNotEmpty())
        <div class="rep-more mt-2 mb-4">
            <span class="small text-muted me-1 align-self-center">Other reports:</span>
            @foreach($others as $o)<a href="{{ route('reports.show', $o->key()) }}"><i class="{{ $o->icon() }} me-1"></i>{{ $o->title() }}</a>@endforeach
        </div>
    @endif

</div>
</div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        var from = document.querySelector('#repFilters [name=from]'), to = document.querySelector('#repFilters [name=to]');
        if (from && to) {
            from.addEventListener('change', function () { to.min = from.value; });
            to.addEventListener('change', function () { from.max = to.value; });
        }
        document.querySelectorAll('#repFilters select:not(#repPeriod)').forEach(function (s) { s.addEventListener('change', function () { this.form.submit(); }); });
    })();
</script>
@if(! $lock && $result->chart)
<script src="{{ asset('theme/layouts/assets/libs/apexcharts/apexcharts.min.js') }}"></script>
<script>
    (function () {
        var el = document.getElementById('repChart');
        if (! el || typeof ApexCharts === 'undefined') return;
        var chart = @json($result->chart);
        var symbol = @json(\App\Support\Money::symbol());
        var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        var fmt = function (v) {
            if (v === null || v === undefined) return '';
            if (chart.format === 'money') {
                var a = Math.abs(v), s = v < 0 ? '−' : '';
                if (a >= 1e6) return s + symbol + (a / 1e6).toFixed(a >= 1e7 ? 0 : 1) + 'm';
                if (a >= 1e3) return s + symbol + (a / 1e3).toFixed(a >= 1e4 ? 0 : 1) + 'k';
                return s + symbol + a.toFixed(0);
            }
            if (chart.format === 'percent') return Math.round(v) + '%';
            return Number(v).toLocaleString();
        };
        var full = function (v) {
            if (chart.format === 'money') return (v < 0 ? '−' : '') + symbol + Math.abs(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            if (chart.format === 'percent') return v + '%';
            return Number(v).toLocaleString();
        };
        var colors = ['#0d9488', '#7c3aed', '#f59e0b', '#0ea5e9', '#f43f5e', '#22c55e', '#64748b', '#0f2342'];
        var opts = {
            chart: { type: chart.type === 'hbar' ? 'bar' : chart.type, height: chart.type === 'donut' ? 300 : 320, toolbar: { show: false }, fontFamily: 'inherit',
                foreColor: dark ? '#94a3b8' : '#64748b', animations: { speed: 400 } },
            colors: colors,
            theme: { mode: dark ? 'dark' : 'light' },
            dataLabels: { enabled: false },
            legend: { position: 'top', horizontalAlign: 'left' },
            tooltip: { theme: dark ? 'dark' : 'light', y: { formatter: full } },
            grid: { borderColor: dark ? '#2b3446' : '#e2e8f0', strokeDashArray: 4 },
        };
        if (chart.type === 'donut') {
            opts.series = chart.series[0].data;
            opts.labels = chart.labels;
            opts.plotOptions = { pie: { donut: { size: '62%' } } };
            opts.legend.position = 'right';
            opts.responsive = [{ breakpoint: 640, options: { legend: { position: 'bottom' } } }];
        } else {
            opts.series = chart.series;
            opts.xaxis = { categories: chart.labels, labels: { formatter: chart.type === 'hbar' ? fmt : undefined, trim: true, hideOverlappingLabels: true, rotate: -30 } };
            opts.yaxis = { labels: { formatter: chart.type === 'hbar' ? undefined : fmt, maxWidth: 180 } };
            opts.plotOptions = { bar: { horizontal: chart.type === 'hbar', borderRadius: 4, columnWidth: chart.labels.length > 20 ? '80%' : '55%', barHeight: '60%' } };
            if (chart.series.length === 1) opts.legend.show = false;
        }
        new ApexCharts(el, opts).render();
    })();
</script>
@endif
@endpush
