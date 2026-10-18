@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Reports" icon="ri-bar-chart-box-line" subtitle="Money, orders, stock, the workroom, customers and projects — filtered by period, branch and staff, and exportable." />
    @include('inventory.partials.flash')

    <style>
        .rep-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
        .rep-tile { display: flex; gap: 14px; padding: 18px; border-radius: 14px; background: var(--cb-surface); border: 1px solid var(--cb-border);
            text-decoration: none; color: inherit; transition: transform .15s, box-shadow .15s, border-color .15s; position: relative; }
        .rep-tile:hover { transform: translateY(-2px); box-shadow: 0 10px 24px -14px rgba(15, 40, 70, .35); border-color: var(--cb-teal); color: inherit; }
        .rep-tile .ico { flex: 0 0 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; font-size: 22px;
            background: rgba(15, 155, 142, .1); color: var(--cb-teal); }
        .rep-tile.locked .ico { background: rgba(100, 116, 139, .1); color: #64748b; }
        .rep-tile h6 { margin: 0 0 4px; font-weight: 700; }
        .rep-tile p { margin: 0; font-size: .82rem; color: var(--cb-muted); line-height: 1.45; }
        .rep-tile .lock { position: absolute; top: 12px; right: 12px; font-size: .7rem; }
        .rep-group { font-size: .75rem; letter-spacing: .08em; text-transform: uppercase; font-weight: 700; color: var(--cb-muted); margin: 22px 0 10px; }
    </style>

    @foreach($groups as $group => $reports)
        <div class="rep-group">{{ $group }}</div>
        <div class="rep-grid">
            @foreach($reports as $r)
                <a href="{{ route('reports.show', $r->key()) }}" class="rep-tile {{ $locks[$r->key()] ? 'locked' : '' }}">
                    <div class="ico"><i class="{{ $r->icon() }}"></i></div>
                    <div>
                        <h6>{{ $r->title() }}</h6>
                        <p>{{ $r->description() }}</p>
                    </div>
                    @if($locks[$r->key()])<span class="lock status-pill st-muted"><i class="ri-lock-line me-1"></i>Upgrade</span>@endif
                </a>
            @endforeach
        </div>
    @endforeach

    <p class="small text-muted mt-4 mb-0"><i class="ri-information-line me-1"></i>Each report shows only what your role may see @if(\App\Services\Reports\ReportFilters::homeBranch(auth()->user())), for your branch @endif. Exports contain exactly what is on the screen.</p>

</div>
</div>
</div>
@endsection
