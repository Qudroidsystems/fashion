@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    @php $tz = \App\Support\Tenancy\CurrentTenant::get()->timezone; $today = now($tz)->startOfDay(); @endphp
    <x-cb.hero title="Weddings & projects" icon="ri-heart-2-line" subtitle="Weddings, events, uniforms and group orders: everyone being dressed, their garments, milestones and money in one place.">
        <x-slot:actions>@can('Manage project')<a href="{{ route('projects.create') }}" class="cb-hero-btn"><i class="ri-add-line me-1"></i>New project</a>@endcan</x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.flash')

    <x-cb.card title="Projects" icon="ri-list-check" :count="$projects->total()" :flush="true">
        <form method="GET" class="cb-toolbar">
            <ul class="nav nav-pills gap-1 flex-nowrap overflow-auto">
                @foreach(['open' => 'Open', 'planning' => 'Planning', 'active' => 'In progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $l)
                    @php $n = match ($k) { 'open' => ($counts['planning'] ?? 0) + ($counts['active'] ?? 0), 'all' => $counts->sum(), default => $counts[$k] ?? 0 }; @endphp
                    <li class="nav-item"><a href="{{ request()->fullUrlWithQuery(['status' => $k, 'page' => null]) }}" class="nav-link py-1 px-3 text-nowrap {{ $status === $k ? 'active' : '' }}">{{ $l }} <span class="opacity-75">{{ $n }}</span></a></li>
                @endforeach
            </ul>
            <input type="hidden" name="status" value="{{ $status }}">
            <select name="type" class="cb-select" onchange="this.form.submit()"><option value="">All kinds</option>
                @foreach(\App\Models\Project::TYPES as $k => $l)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $l }}</option>@endforeach</select>
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ request('q') }}" placeholder="Name, number or client…"></div>
        </form>
        @if($projects->isEmpty())
            <div class="empty-state"><i class="ri-heart-2-line"></i><h6>No projects here</h6>
                @can('Manage project')<p class="small text-muted">Start one for a wedding, event or uniform order.</p><a href="{{ route('projects.create') }}" class="action-btn btn-primary-cb">New project</a>@endcan</div>
        @else
        <div class="table-responsive"><table class="cb-table stack mb-0">
            <thead><tr><th>Project</th><th>Client</th><th>Event</th><th class="text-end">People</th><th style="min-width:160px">Garments ready</th><th>Manager</th><th>Status</th></tr></thead>
            <tbody>
            @foreach($projects as $p)
                @php $days = $p->event_date ? (int) $today->diffInDays($p->event_date, false) : null; $pct = $p->garment_total ? round(100 * $p->garment_ready / $p->garment_total) : 0; @endphp
                <tr onclick="location.href='{{ route('projects.show', $p) }}'" style="cursor:pointer">
                    <td data-label="Project"><a href="{{ route('projects.show', $p) }}" class="fw-semibold">{{ $p->name }}</a><div class="small text-muted">{{ $p->number }} · {{ $p->type_label }}</div></td>
                    <td data-label="Client">{{ $p->customer?->name }}</td>
                    <td data-label="Event">@if($p->event_date){{ $p->event_date->format('d M Y') }}
                        @if($p->isOpen())<div class="small {{ $days < 0 ? 'text-muted' : ($days <= 14 ? 'text-danger fw-semibold' : 'text-muted') }}">{{ $days < 0 ? abs($days) . ' days ago' : ($days === 0 ? 'Today' : 'in ' . $days . ' days') }}</div>@endif
                        @else<span class="text-muted">—</span>@endif</td>
                    <td data-label="People" class="text-end">{{ $p->participant_count }}</td>
                    <td data-label="Garments ready">
                        @if($p->garment_total)
                            <div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1" style="height:6px"><div class="progress-bar bg-success" style="width:{{ $pct }}%"></div></div><span class="small text-nowrap">{{ $p->garment_ready }}/{{ $p->garment_total }}</span></div>
                        @else<span class="small text-muted">None yet</span>@endif
                    </td>
                    <td data-label="Manager" class="small">{{ $p->manager?->name }}</td>
                    <td data-label="Status"><span class="status-pill {{ $p->status_pill }}">{{ $p->status_label }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @endif
    </x-cb.card>
    <div class="mt-3">{{ $projects->links() }}</div>
</div>
</div>
</div>
@endsection
