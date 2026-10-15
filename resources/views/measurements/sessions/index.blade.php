@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Measurements" icon="ri-ruler-2-line" subtitle="Sessions waiting for review, and everything measured recently.">
        @can('Manage measurement templates')
        <x-slot:actions>
            <a href="{{ route('measurement-templates.index') }}" class="cb-hero-btn"><i class="ri-file-list-2-line me-1"></i>Templates</a>
        </x-slot:actions>
        @endcan
    </x-cb.hero>

    <div class="term-chips mb-3 d-flex flex-wrap gap-2">
        @foreach(['submitted' => 'Waiting for review', 'draft' => 'Drafts', 'rejected' => 'Sent back', 'approved' => 'Approved', 'all' => 'All'] as $key => $label)
            <a href="{{ route('measurements.index', ['status' => $key]) }}" class="term-chip {{ $status === $key ? 'active' : '' }}">
                {{ $label }}@if($key !== 'all') ({{ $counts[$key] ?? 0 }})@endif
            </a>
        @endforeach
    </div>

    <x-cb.card title="Sessions" icon="ri-list-check-2" :count="$sessions->total()" :flush="true">
        <div class="table-responsive">
            <table class="cb-table stack">
                <thead><tr><th>Customer</th><th>Template</th><th>Source</th><th>Measured</th><th>Flags</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @forelse($sessions as $s)
                    <tr>
                        <td data-label="Customer"><a href="{{ route('customers.show', $s->customer) }}#measurements">{{ $s->customer->name }}</a><div class="small text-muted">{{ $s->customer->customer_number }}</div></td>
                        <td data-label="Template">{{ $s->templateVersion->label }}</td>
                        <td data-label="Source" class="small">{{ \App\Models\MeasurementSession::METHODS[$s->capture_method] }}@if($s->measurer)<div class="text-muted">{{ $s->measurer->name }}</div>@endif</td>
                        <td data-label="Measured" class="small text-nowrap">{{ ($s->submitted_at ?? $s->measured_at)?->timezone($tz)->format('d M Y, H:i') }}</td>
                        <td data-label="Flags">
                            @if($n = count($s->change_summary['flagged'] ?? []))<span class="status-pill st-warning">{{ $n }} changed</span>@endif
                            @if($o = ($s->change_summary['out_of_range'] ?? 0))<span class="status-pill st-danger">{{ $o }} unusual</span>@endif
                        </td>
                        <td data-label="Status"><span class="status-pill {{ $s->status_pill }}">{{ ucfirst($s->status) }}</span></td>
                        <td class="text-end"><a href="{{ route('measurements.show', $s) }}" class="action-btn btn-primary-cb">{{ $s->status === 'submitted' ? 'Review' : 'Open' }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><i class="ri-ruler-2-line"></i><h6>Nothing here</h6><p>Measure a customer from their profile.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($sessions->hasPages())<div class="px-4 py-3">{{ $sessions->links() }}</div>@endif
    </x-cb.card>

</div>
</div>
</div>
@endsection
