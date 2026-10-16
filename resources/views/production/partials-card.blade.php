{{-- One task on the board. --}}
@php $overdue = $t->isOverdue(); $dueToday = $t->due_date && $t->due_date->toDateString() === $today; @endphp
<div class="prod-card {{ $t->priority === 'rush' ? 'is-rush' : '' }}">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <a href="{{ route('production.tasks.show', $t) }}" class="fw-semibold text-body">{{ $t->name }}</a>
        <span class="d-flex gap-1 flex-shrink-0">
            @if($t->kind_label)<span class="status-pill {{ $t->kind === 'rework' ? 'st-danger' : 'st-warning' }}">{{ $t->kind_label }}</span>@endif
            @if($t->priority !== 'normal')<span class="status-pill {{ $t->priority === 'rush' ? 'st-danger' : 'st-warning' }}">{{ ucfirst($t->priority) }}</span>@endif
        </span>
    </div>
    <div class="small text-muted text-truncate">{{ $t->line?->description ?? 'Whole order' }}{{ $t->line?->wearer ? ' · for ' . $t->line->wearer : '' }}</div>
    <div class="small text-truncate"><a href="{{ route('orders.show', $t->order) }}" class="text-muted">{{ $t->order->number }}</a> · {{ $t->order->customer?->name }}
        @if($t->order->risk_level)<i class="ri-timer-flash-line {{ $t->order->risk_level === 'high' ? 'text-danger' : 'text-warning' }}" title="Delivery at risk"></i>@endif</div>
    <div class="d-flex justify-content-between align-items-center mt-2">
        <span class="small {{ $overdue ? 'text-danger fw-semibold' : ($dueToday ? 'text-warning fw-semibold' : 'text-muted') }}">
            @if($t->status === 'done')<i class="ri-check-line"></i> {{ $t->completed_at?->timezone($tz)->format('d M H:i') }}
            @elseif($t->due_date)<i class="ri-calendar-line"></i> {{ $overdue ? 'Overdue · ' : ($dueToday ? 'Today · ' : '') }}{{ $t->due_date->format('d M') }}@endif
        </span>
        <span class="d-flex align-items-center gap-1">
            @if($t->assignee)<span class="prod-avatar" title="{{ $t->assignee->name }}">{{ collect(explode(' ', $t->assignee->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</span>
            @else<span class="small text-muted">Unassigned</span>@endif
        </span>
    </div>
    @if(in_array($t->status, ['pending', 'in_progress', 'on_hold']) && $t->type !== 'qc' && ($manager || $t->assigned_to === auth()->id() || ! $t->assigned_to) && auth()->user()->can('Update production'))
        <div class="d-flex gap-1 mt-2 prod-actions">
            @if(in_array($t->status, ['pending', 'on_hold']))
                <form method="POST" action="{{ route('production.tasks.start', $t) }}">@csrf<button class="btn btn-sm btn-light py-0"><i class="ri-play-line"></i> {{ $t->status === 'on_hold' ? 'Resume' : 'Start' }}</button></form>
            @endif
            @if($t->kind === 'stage' && $t->type === 'work')
                <form method="POST" action="{{ route('production.tasks.complete', $t) }}">@csrf<button class="btn btn-sm btn-light py-0"><i class="ri-check-line"></i> Done</button></form>
            @endif
        </div>
    @endif
</div>
