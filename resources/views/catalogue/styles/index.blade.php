@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php use App\Support\Money; @endphp

    <x-cb.hero title="Styles" icon="ri-t-shirt-air-line" subtitle="Bespoke designs you make to measure. Each change is published as a new version, so past orders stay reproducible.">
        <x-slot:pills>
            <span class="cb-meta-pill">{{ $counts['active'] }} active</span>
            @if($counts['draft'])<span class="cb-meta-pill">{{ $counts['draft'] }} not yet published</span>@endif
        </x-slot:pills>
        <x-slot:actions>
            @can('Manage style')
                <a href="{{ route('catalogue-categories.index') }}" class="cb-hero-btn"><i class="ri-price-tag-3-line me-1"></i>Categories</a>
                <a href="{{ route('styles.create') }}" class="cb-hero-btn"><i class="ri-add-line me-1"></i>New style</a>
            @endcan
        </x-slot:actions>
    </x-cb.hero>

    @if(session('success'))<div class="cb-banner success"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>@endif

    <x-cb.card :flush="true" class="mb-3">
        <form method="GET" class="cb-toolbar">
            <div class="cb-search"><i class="ri-search-line"></i>
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Style name or code…">
            </div>
            <select name="category" class="cb-select" onchange="this.form.submit()">
                <option value="">All categories</option>
                @foreach($categories as $c)<option value="{{ $c->id }}" @selected(request('category') == $c->id)>{{ $c->name }}</option>@endforeach
            </select>
            <select name="status" class="cb-select" onchange="this.form.submit()">
                @foreach(['active' => 'Active', 'draft' => 'Not yet published', 'archived' => 'Archived', 'all' => 'All'] as $k => $l)
                    <option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>
                @endforeach
            </select>
            <button class="action-btn btn-primary-cb ms-auto"><i class="ri-filter-3-line"></i>Apply</button>
        </form>
    </x-cb.card>

    <div class="row g-3">
        @forelse($styles as $s)
            @php
                $v = $s->currentVersion ?? $s->draft;
                $cover = $v?->images->first();
            @endphp
            <div class="col-sm-6 col-lg-4 col-xxl-3">
                <a href="{{ route('styles.show', $s) }}" class="cb-card d-block h-100 text-reset text-decoration-none style-card">
                    <div class="style-thumb">
                        @if($cover)<img src="{{ $cover->url }}" alt="{{ $s->name }}" loading="lazy">@else<i class="ri-t-shirt-air-line"></i>@endif
                        <div class="style-badges">
                            @if(! $s->current_version_id)<span class="status-pill st-warning">Draft</span>
                            @elseif($s->draft)<span class="status-pill st-violet">Edits pending</span>@endif
                            @if($s->status === 'archived')<span class="status-pill st-muted">Archived</span>@endif
                            @if($s->show_in_catalogue && $s->current_version_id)<span class="status-pill st-success" title="Visible to customers"><i class="ri-eye-line"></i></span>@endif
                        </div>
                    </div>
                    <div class="p-3">
                        <div class="small text-muted">{{ $s->code }}{{ $s->category ? ' · ' . $s->category->name : '' }}</div>
                        <div class="fw-semibold text-truncate">{{ $s->name }}</div>
                        <div class="d-flex justify-content-between small mt-1">
                            <span>{{ $v?->base_price !== null ? 'From ' . Money::format($v->base_price) : 'Price on request' }}</span>
                            @if($v?->lead_time_days)<span class="text-muted"><i class="ri-time-line"></i> {{ $v->lead_time_days }} days</span>@endif
                        </div>
                    </div>
                </a>
            </div>
        @empty
            <div class="col-12"><div class="empty-state"><i class="ri-t-shirt-air-line"></i><h6>No styles here yet</h6>
                <p>@can('Manage style')Add your first design with pictures, price and the measurements it needs.@else Nothing matches these filters.@endcan</p>
                @can('Manage style')<a href="{{ route('styles.create') }}" class="action-btn btn-primary-cb"><i class="ri-add-line"></i>New style</a>@endcan
            </div></div>
        @endforelse
    </div>

    <div class="mt-3">{{ $styles->links() }}</div>

</div>
</div>
</div>

<style>
    .style-card { overflow: hidden; transition: transform .15s ease, box-shadow .15s ease; }
    .style-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(20, 30, 60, .08); }
    .style-thumb { position: relative; aspect-ratio: 4 / 5; background: var(--cb-soft, #f3f5f9); display: grid; place-items: center; }
    .style-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .style-thumb > i { font-size: 48px; color: #c3c8d4; }
    .style-badges { position: absolute; top: 10px; left: 10px; display: flex; gap: 6px; }
</style>
@endsection
