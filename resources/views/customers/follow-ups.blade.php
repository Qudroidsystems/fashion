@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Follow-ups" icon="ri-alarm-line" subtitle="Calls, fittings to book and check-ins you promised customers."
               :back="route('customers.index')" back-label="Customers" />

    @if(session('success'))
        <div class="cb-banner success"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>
    @endif

    <div class="term-chips mb-3 d-flex flex-wrap gap-2">
        @foreach(['mine' => 'Mine (' . $counts['mine'] . ')', 'today' => 'Due today', 'overdue' => 'Overdue (' . $counts['overdue'] . ')', 'open' => 'All open (' . $counts['open'] . ')', 'done' => 'Done'] as $key => $label)
            <a href="{{ route('follow-ups.index', ['show' => $key]) }}" class="term-chip {{ $filter === $key ? 'active' : '' }}">{{ $label }}</a>
        @endforeach
    </div>

    <x-cb.card title="Follow-ups" icon="ri-list-check-2" :count="$tasks->total()" :flush="true">
        <div class="table-responsive">
            <table class="cb-table stack">
                <thead><tr><th>Task</th><th>Customer</th><th>Assigned to</th><th>Due</th><th></th></tr></thead>
                <tbody>
                @forelse($tasks as $t)
                    <tr>
                        <td data-label="Task">
                            <span class="fw-semibold">{{ $t->title }}</span>
                            @if($t->isOverdue())<span class="status-pill st-danger ms-1">Overdue</span>@endif
                            @if($t->status !== 'open')<span class="status-pill st-muted ms-1">{{ ucfirst($t->status) }}</span>@endif
                        </td>
                        <td data-label="Customer">
                            <a href="{{ route('customers.show', $t->customer) }}#follow-ups">{{ $t->customer->name }}</a>
                            <div class="small text-muted">{{ $t->customer->phone }}</div>
                        </td>
                        <td data-label="Assigned to">{{ $t->assignee?->name ?? '—' }}</td>
                        <td data-label="Due" class="text-nowrap">{{ $t->due_at?->timezone($tz)->format('d M, H:i') ?? '—' }}</td>
                        <td class="text-end">
                            @can('Update customer')
                            <form method="POST" action="{{ route('follow-ups.update', $t) }}" class="d-inline">
                                @csrf @method('PATCH')
                                @if($t->status === 'open')
                                    <button name="status" value="done" class="action-btn btn-primary-cb"><i class="ri-check-line"></i>Done</button>
                                @else
                                    <button name="status" value="open" class="action-btn">Reopen</button>
                                @endif
                            </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><div class="empty-state"><i class="ri-checkbox-circle-line"></i><h6>Nothing here</h6><p>You're all caught up.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($tasks->hasPages())<div class="px-4 py-3">{{ $tasks->links() }}</div>@endif
    </x-cb.card>

</div>
</div>
</div>
@endsection
