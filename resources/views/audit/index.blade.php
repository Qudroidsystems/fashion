@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php
        $tz = $currentTenant?->timezone ?? config('app.timezone');
        $show = function ($v) {
            if (is_null($v) || $v === '') return '—';
            if (is_bool($v)) return $v ? 'yes' : 'no';
            if (is_array($v)) {
                return array_is_list($v) && ! array_filter($v, 'is_array') ? implode(', ', $v) : json_encode($v, JSON_UNESCAPED_SLASHES);
            }
            return (string) $v;
        };
    @endphp

    <x-cb.hero title="Audit Log" icon="ri-shield-check-line"
               subtitle="Who did what, and when. Records cannot be edited or deleted."
               :back="route('dashboard')" back-label="Dashboard" />

    <x-cb.card title="Activity" icon="ri-history-line" :count="$logs->total()" :flush="true">
        <form method="GET" class="cb-toolbar">
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search description…"></div>
            <select name="action" class="cb-select">
                <option value="">All actions</option>
                @foreach($actions as $a)<option value="{{ $a }}" @selected(($filters['action'] ?? '') === $a)>{{ $a }}</option>@endforeach
            </select>
            <select name="user_id" class="cb-select">
                <option value="">Everyone</option>
                @foreach($users as $u)<option value="{{ $u->id }}" @selected(($filters['user_id'] ?? '') == $u->id)>{{ $u->name }}</option>@endforeach
            </select>
            <input type="date" name="from" class="cb-select" value="{{ $filters['from'] ?? '' }}" title="From">
            <input type="date" name="to" class="cb-select" value="{{ $filters['to'] ?? '' }}" title="To">
            <button class="action-btn btn-primary-cb"><i class="ri-filter-3-line"></i>Filter</button>
            @if(array_filter($filters))<a href="{{ route('audit-logs.index') }}" class="action-btn">Clear</a>@endif
        </form>

        <div class="table-responsive">
            <table class="cb-table stack">
                <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
                <tbody>
                @forelse($logs as $log)
                    <tr>
                        <td data-label="When" class="text-nowrap">{{ $log->created_at->timezone($tz)->format('d M Y, H:i') }}</td>
                        <td data-label="Who">{{ $log->user?->name ?? 'System' }}</td>
                        <td data-label="Action"><span class="status-pill st-info">{{ $log->action }}</span></td>
                        <td data-label="Details" class="small">
                            {{ $log->description }}
                            @if($log->before || $log->after)
                                <details class="mt-1">
                                    <summary class="text-muted">Changes</summary>
                                    @foreach(array_unique(array_merge(array_keys($log->before ?? []), array_keys($log->after ?? []))) as $key)
                                        <div><strong>{{ $key }}:</strong>
                                            <span class="text-danger">{{ $show($log->before[$key] ?? null) }}</span>
                                            → <span class="text-success">{{ $show($log->after[$key] ?? null) }}</span>
                                        </div>
                                    @endforeach
                                </details>
                            @endif
                        </td>
                        <td data-label="IP" class="small text-muted">{{ $log->ip_address }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5"><div class="empty-state"><i class="ri-file-search-line"></i><h6>Nothing recorded yet</h6><p>Sign-ins, staff and role changes and settings updates appear here.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($logs->hasPages())<div class="px-4 py-3">{{ $logs->links() }}</div>@endif
    </x-cb.card>

</div>
</div>
</div>
@endsection
