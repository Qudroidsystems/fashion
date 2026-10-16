@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    @php
        use App\Models\ProductionTask;
        $u = auth()->user();
        $manager = $u->can('Manage production workflow');
        $line = $task->line;
        $sv = $line?->styleVersion;
        $open = $task->isOpen();
    @endphp
    <x-cb.hero :title="$task->name" icon="ri-scissors-cut-line"
               :subtitle="($line?->description ?? 'Whole order') . ' · ' . $task->order->number . ' · ' . $task->order->customer->name"
               :back="route('production.board')" back-label="Board">
        <x-slot:pills>
            <span class="status-pill {{ $task->status_pill }}">{{ $task->status_label }}</span>
            @if($task->kind_label)<span class="status-pill {{ $task->kind === 'rework' ? 'st-danger' : 'st-warning' }}">{{ $task->kind_label }}</span>@endif
            <span class="cb-meta-pill">{{ $task->number }}</span>
            @if($task->due_date)<span class="cb-meta-pill {{ $task->isOverdue() ? 'bg-danger' : '' }}">Due {{ $task->due_date->format('D d M') }}</span>@endif
            @if($task->priority !== 'normal')<span class="cb-meta-pill">{{ ucfirst($task->priority) }}</span>@endif
            <span class="cb-meta-pill">{{ $task->assignee?->name ?? 'Unassigned' }}</span>
        </x-slot:pills>
        <x-slot:actions><a href="{{ route('orders.show', $task->order) }}" class="cb-hero-btn"><i class="ri-file-list-3-line me-1"></i>Order</a></x-slot:actions>
    </x-cb.hero>
    @include('production.partials-nav')
    @include('inventory.partials.flash')
    @if($waitingOn)<div class="cb-banner info"><i class="ri-hourglass-line"></i><div>Waiting on {{ $waitingOn }}. It moves to “To do” by itself when that is done.</div></div>@endif

    <div class="row g-3">
        <div class="col-xl-8">
            {{-- Garment progress --}}
            @if($siblings->count() > 1)
            <x-cb.card title="This garment" icon="ri-route-line">
                <div class="d-flex flex-wrap gap-2">
                    @foreach($siblings as $s)
                        <a href="{{ route('production.tasks.show', $s) }}" class="status-pill {{ $s->id === $task->id ? 'st-violet' : $s->status_pill }} text-decoration-none" title="{{ $s->status_label }}{{ $s->assignee ? ' · ' . $s->assignee->name : '' }}">
                            @if($s->status === 'done')<i class="ri-check-line"></i>@endif {{ $s->name }}</a>
                    @endforeach
                </div>
            </x-cb.card>
            @endif

            {{-- What to do --}}
            <x-cb.card title="Work instructions" icon="ri-booklet-line" class="{{ $siblings->count() > 1 ? 'mt-3' : '' }}">
                <div class="row g-3">
                    @if($sv && $sv->cover())
                        <div class="col-sm-3"><img src="{{ $sv->cover()->url }}" class="img-fluid rounded-3" alt=""></div>
                    @endif
                    <div class="{{ $sv && $sv->cover() ? 'col-sm-9' : 'col-12' }}">
                        @if($task->instructions)<p class="mb-2" style="white-space:pre-line">{{ $task->instructions }}</p>@endif
                        @if($task->reason)<p class="mb-1"><span class="text-muted">Reason:</span> {{ $task->reason }}</p>@endif
                        @if($task->responsible_party)<p class="mb-1 small text-muted">Responsible: {{ ProductionTask::RESPONSIBLE[$task->responsible_party] ?? $task->responsible_party }} · {{ ProductionTask::COST_TREATMENT[$task->cost_treatment] ?? '' }}@if($task->cost && $u->can('View payment')) · {{ \App\Support\Money::format($task->cost) }}@endif</p>@endif
                        @if($line)
                            <div class="fw-semibold">{{ $line->description }} @if($line->quantity > 1)<span class="text-muted">× {{ rtrim(rtrim(number_format($line->quantity, 2), '0'), '.') }}</span>@endif</div>
                            @if($line->wearer)<div class="small">For {{ $line->wearer }}</div>@endif
                            @if(! empty($line->options))<div class="small text-muted">With {{ collect($line->options)->pluck('label')->implode(', ') }}</div>@endif
                            @if($line->instructions)<div class="small mt-1" style="white-space:pre-line">{{ $line->instructions }}</div>@endif
                            @if($sv?->technical_notes)<div class="small mt-2 p-2 rounded-3" style="background:var(--cb-surface-2)"><span class="text-muted">Workroom notes ({{ $sv->style->code }} {{ $sv->label }}):</span> {{ $sv->technical_notes }}</div>@endif
                            <div class="small mt-2">
                                @if($line->measurementSession)<i class="ri-ruler-line"></i> <a href="{{ route('measurements.show', $line->measurementSession) }}">{{ $line->measurementSession->templateVersion->template->name }} measurements</a>
                                @else<span class="text-warning"><i class="ri-ruler-line"></i> No measurements linked to this garment yet.</span>@endif
                            </div>
                        @endif
                        @if(! $task->instructions && ! $line && ! $task->reason)<p class="text-muted small mb-0">No instructions.</p>@endif
                    </div>
                </div>
            </x-cb.card>

            {{-- Quality check form --}}
            @if($task->type === 'qc' && $open && $u->can('Record quality check'))
            <x-cb.card title="Quality check" icon="ri-shield-check-line" class="mt-3">
                @if($task->status === 'blocked')
                    <p class="text-muted small mb-0">The check opens once the earlier work is done.</p>
                @else
                <form method="POST" action="{{ route('production.tasks.qc', $task) }}">@csrf
                    @foreach(($task->checklist?->items ?: [['label' => 'Garment is acceptable', 'required' => true]]) as $i => $item)
                        <div class="row g-2 align-items-center py-2 border-bottom">
                            <div class="col-md-6">{{ $item['label'] }} @unless($item['required'] ?? true)<span class="small text-muted">(optional)</span>@endunless</div>
                            <div class="col-md-2 d-flex gap-2">
                                <input type="hidden" name="answers[{{ $i }}][passed]" value="0">
                                <label class="d-flex align-items-center gap-1"><input type="checkbox" name="answers[{{ $i }}][passed]" value="1" class="form-check-input mt-0" checked> OK</label>
                            </div>
                            <div class="col-md-4"><input name="answers[{{ $i }}][note]" class="form-control form-control-sm" placeholder="Note (if not OK)" maxlength="255"></div>
                        </div>
                    @endforeach
                    <div class="row g-2 mt-2">
                        <div class="col-md-8"><textarea name="issues" class="form-control form-control-sm" rows="2" placeholder="Issues found (for rework), or remarks"></textarea></div>
                        <div class="col-md-4"><select name="rework_assignee" class="form-select form-select-sm"><option value="">Rework goes to: last tailor</option>
                            @foreach($staff as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
                    </div>
                    <button class="action-btn btn-primary-cb mt-3"><i class="ri-shield-check-line"></i>Record check</button>
                    <span class="small text-muted ms-2">Untick anything not right — a failed required item creates rework.</span>
                </form>
                @endif
            </x-cb.card>
            @endif

            @if($task->qualityChecks->isNotEmpty())
            <x-cb.card title="Quality checks" icon="ri-shield-check-line" class="mt-3">
                @foreach($task->qualityChecks as $qc)
                    <div class="py-2 border-bottom">
                        <div class="d-flex justify-content-between"><span><span class="status-pill {{ $qc->result === 'pass' ? 'st-success' : 'st-danger' }}">{{ $qc->result === 'pass' ? 'Passed' : 'Failed' }}</span>
                            <span class="small text-muted ms-1">{{ $qc->reviewer?->name }} · {{ $qc->created_at->timezone($tz)->format('d M H:i') }}</span></span>
                            @if($qc->reworkTask)<a href="{{ route('production.tasks.show', $qc->reworkTask) }}" class="small">Rework {{ $qc->reworkTask->number }} ({{ $qc->reworkTask->status_label }})</a>@endif</div>
                        @foreach(collect($qc->results)->where('passed', false) as $r)<div class="small text-danger"><i class="ri-close-line"></i> {{ $r['label'] }}{{ $r['note'] ? ' — ' . $r['note'] : '' }}</div>@endforeach
                        @if($qc->issues)<div class="small mt-1">{{ $qc->issues }}</div>@endif
                    </div>
                @endforeach
            </x-cb.card>
            @endif

            {{-- History --}}
            <x-cb.card title="History" icon="ri-history-line" class="mt-3">
                @if($canWork || $u->can('Record quality check'))
                <form method="POST" action="{{ route('production.tasks.note', $task) }}" enctype="multipart/form-data" class="row g-2 mb-3">@csrf
                    <div class="col-md-7"><input name="note" class="form-control form-control-sm" required maxlength="1000" placeholder="Add a note…"></div>
                    <div class="col-md-3"><input type="file" name="evidence" accept="image/*" class="form-control form-control-sm" title="Photo (optional)"></div>
                    <div class="col-md-2 d-grid"><button class="action-btn justify-content-center">Add</button></div>
                    @if($manager)<div class="col-12"><label class="small d-flex gap-1"><input type="checkbox" name="customer_visible" value="1"> Customer can see this update</label></div>@endif
                </form>
                @endif
                @foreach($task->events as $e)
                    <div class="d-flex gap-2 py-2 border-bottom small">
                        <i class="ri-checkbox-blank-circle-fill" style="font-size:8px;margin-top:6px;color:var(--cb-teal)"></i>
                        <div class="flex-grow-1">
                            <span class="fw-semibold">{{ $e->label }}</span>@if($e->customer_visible) <span class="status-pill st-info">Customer sees</span>@endif
                            <span class="text-muted">· {{ $e->user?->name ?? 'System' }} · {{ $e->created_at->timezone($tz)->format('d M, H:i') }}</span>
                            @if($e->note)<div>{{ $e->note }}</div>@endif
                            @if($e->evidence_url)<a href="{{ $e->evidence_url }}" target="_blank"><img src="{{ $e->evidence_url }}" alt="" class="rounded-2 mt-1" style="max-height:90px"></a>@endif
                        </div>
                    </div>
                @endforeach
            </x-cb.card>
        </div>

        <div class="col-xl-4">
            {{-- Actions --}}
            @if($open && $canWork && $task->status !== 'blocked')
            <x-cb.card title="Update" icon="ri-flashlight-line">
                @if(in_array($task->status, ['pending', 'on_hold']))
                    <form method="POST" action="{{ route('production.tasks.start', $task) }}" class="mb-2">@csrf
                        <button class="action-btn btn-primary-cb w-100 justify-content-center py-2"><i class="ri-play-line"></i>{{ $task->status === 'on_hold' ? 'Resume' : 'Start' }}{{ $task->assigned_to ? '' : ' (takes it)' }}</button></form>
                @endif
                @if($task->type !== 'qc')
                    <form method="POST" action="{{ route('production.tasks.complete', $task) }}" enctype="multipart/form-data" class="mb-2">@csrf
                        @if($task->kind !== 'stage')<textarea name="note" class="form-control form-control-sm mb-2" rows="2" required placeholder="What was done to fix it?"></textarea>
                        @else<input name="note" class="form-control form-control-sm mb-2" maxlength="1000" placeholder="Note (optional)">@endif
                        <input type="file" name="evidence" accept="image/*" class="form-control form-control-sm mb-2" title="Photo (optional)">
                        @if($task->type === 'fitting')<p class="small text-muted mb-2">Usually finished by recording the fitting. Use this only if no booking is needed.</p>@endif
                        <button class="action-btn w-100 justify-content-center"><i class="ri-check-double-line"></i>Mark done</button>
                    </form>
                @endif
                @if(in_array($task->status, ['pending', 'in_progress']))
                    <a class="small" data-bs-toggle="collapse" href="#holdBox">Put on hold…</a>
                    <div class="collapse mt-2" id="holdBox"><form method="POST" action="{{ route('production.tasks.hold', $task) }}">@csrf
                        <input name="reason" class="form-control form-control-sm mb-2" required maxlength="500" placeholder="Why? e.g. waiting for lining">
                        <button class="action-btn w-100 justify-content-center">Put on hold</button></form></div>
                @endif
            </x-cb.card>
            @elseif($open && ! $canWork && $task->status !== 'blocked')
                <div class="cb-banner info"><i class="ri-user-line"></i><div>Assigned to {{ $task->assignee?->name }}.</div></div>
            @endif

            @if($task->type === 'fitting' && $open && $u->can('Manage fitting'))
            <x-cb.card title="Fittings" icon="ri-calendar-check-line" class="mt-3">
                @foreach($fittings as $f)
                    <a href="{{ route('fittings.show', $f) }}" class="d-flex justify-content-between small py-1 border-bottom text-body">
                        <span>{{ $f->scheduled_at->timezone($tz)->format('D d M, H:i') }}</span><span class="status-pill {{ $f->status_pill }}">{{ $f->status_label }}</span></a>
                @endforeach
                <a href="{{ route('fittings.create', ['task' => $task->public_id]) }}" class="action-btn w-100 justify-content-center mt-2"><i class="ri-calendar-event-line"></i>Book a fitting</a>
            </x-cb.card>
            @endif

            @if($task->status === 'done' && $manager && ! in_array($task->order->status, ['delivered', 'closed', 'cancelled']))
            <x-cb.card title="Reopen" icon="ri-arrow-go-back-line" class="mt-3">
                <form method="POST" action="{{ route('production.tasks.reopen', $task) }}">@csrf
                    <input name="reason" class="form-control form-control-sm mb-2" required maxlength="500" placeholder="Why does it need redoing?">
                    <button class="action-btn w-100 justify-content-center">Reopen task</button>
                    <p class="small text-muted mt-2 mb-0">Later stages wait again until it is done.</p></form>
            </x-cb.card>
            @endif

            @if($manager && $open)
            <x-cb.card title="Plan" icon="ri-user-settings-line" class="mt-3">
                <form method="POST" action="{{ route('production.tasks.update', $task) }}">@csrf @method('PATCH')
                    <label class="form-label small">Assigned to @if($task->role)<span class="text-muted">(usually {{ $task->role->name }})</span>@endif</label>
                    <select name="assigned_to" class="form-select form-select-sm mb-2"><option value="">Nobody yet</option>
                        @foreach($staff as $s)<option value="{{ $s->id }}" @selected($task->assigned_to === $s->id)>{{ $s->name }}</option>@endforeach</select>
                    <div class="row g-2 mb-2">
                        <div class="col-7"><label class="form-label small">Due</label><input type="date" name="due_date" value="{{ $task->due_date?->toDateString() }}" class="form-control form-control-sm"></div>
                        <div class="col-5"><label class="form-label small">Priority</label><select name="priority" class="form-select form-select-sm">
                            @foreach(ProductionTask::PRIORITIES as $k => $l)<option value="{{ $k }}" @selected($task->priority === $k)>{{ $l }}</option>@endforeach</select></div>
                    </div>
                    <textarea name="instructions" class="form-control form-control-sm mb-2" rows="3" placeholder="Instructions">{{ $task->instructions }}</textarea>
                    <button class="action-btn w-100 justify-content-center">Save</button>
                </form>
            </x-cb.card>
            @endif

            <x-cb.card title="Materials" icon="ri-archive-line" class="mt-3">
                @forelse($issues as $mi)
                    <a href="{{ route('material-issues.show', $mi) }}" class="d-block small py-1 border-bottom text-body"><span class="fw-semibold">{{ $mi->number }}</span>
                        <span class="text-muted">{{ $mi->lines->map(fn ($l) => $l->item->qty($l->quantity) . ' ' . $l->item->name)->implode(', ') }}</span></a>
                @empty
                    <p class="small text-muted mb-2">Nothing issued for this garment yet.</p>
                @endforelse
                @can('Issue material')
                    <a href="{{ route('material-issues.create', ['task' => $task->public_id]) }}" class="action-btn w-100 justify-content-center mt-2"><i class="ri-scissors-line"></i>Issue material</a>
                @endcan
            </x-cb.card>
        </div>
    </div>
</div>
</div>
</div>
@endsection
