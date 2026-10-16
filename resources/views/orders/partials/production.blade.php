{{-- Production, fittings and handover on the order page (SRS §5.9). Expects $order, $production, $balance, $staff, $tz. --}}
@php
    use App\Models\Delivery;
    use App\Models\ProductionTask;
    use App\Models\ProductionWorkflow;
    $pu = auth()->user();
    $pm = $pu->can('Manage production workflow');
    $p = $production;
    $live = ! in_array($order->status, ['cancelled', 'closed']);
@endphp

<div id="production"></div>
@if($order->risk_level && $live)
    <div class="cb-banner {{ $order->risk_level === 'high' ? 'danger' : 'warning' }} mt-3"><i class="ri-timer-flash-line"></i><div>
        <strong>{{ $order->risk_level === 'high' ? 'Likely to be late.' : 'Keep an eye on this order.' }}</strong>
        @foreach($order->risk_reasons ?? [] as $r)<div class="small">• {{ $r['text'] }}</div>@endforeach
    </div></div>
@endif

<x-cb.card title="Production" icon="ri-scissors-cut-line" class="mt-3">
    @if($p['unplanned']->isNotEmpty() && $pm && in_array($order->status, ['confirmed', 'in_production', 'fitting', 'ready']))
        <form method="POST" action="{{ route('orders.production.plan', $order) }}" class="mb-3 p-3 rounded-3" style="background:var(--cb-surface-2)">@csrf
            <div class="fw-semibold mb-2"><i class="ri-draft-line me-1"></i>Plan production</div>
            @foreach($p['unplanned'] as $row)
                @php $l = $row['line']; @endphp
                <div class="row g-2 align-items-center mb-2">
                    <div class="col-md-6 small"><span class="fw-semibold">{{ $l->description }}</span> <span class="text-muted">· {{ $l->kind_label }}</span></div>
                    <div class="col-md-6"><select name="workflow[{{ $l->id }}]" class="form-select form-select-sm">
                        @foreach($p['workflows'] as $w)<option value="{{ $w->id }}" @selected($row['suggested'] === $w->id)>{{ $w->name }}</option>@endforeach
                        <option value="0" @selected(! $row['suggested'])>Nothing to make (ready as it is)</option>
                    </select></div>
                </div>
            @endforeach
            <button class="action-btn btn-primary-cb mt-1"><i class="ri-play-list-add-line"></i>Create tasks</button>
            <span class="small text-muted ms-2">Due dates are worked back from {{ $order->promised_date ? 'the promise date (' . $order->promised_date->format('d M') . ')' : 'today — set a promise date for better planning' }}.</span>
        </form>
    @elseif($p['unplanned']->isNotEmpty() && $live)
        <p class="small text-muted">Production has not been planned for {{ $p['unplanned']->count() }} item(s) yet.</p>
    @endif

    @forelse($order->lines->where('status', '!=', 'cancelled') as $l)
        @php $lt = $p['tasks']->get($l->id, collect()); @endphp
        @continue($lt->isEmpty())
        <div class="py-2 border-bottom">
            <div class="d-flex justify-content-between small mb-1"><span class="fw-semibold">{{ $l->description }}{{ $l->wearer ? ' · ' . $l->wearer : '' }}</span>
                <span class="status-pill {{ $l->status_pill }}">{{ $l->status_label }}</span></div>
            <div class="d-flex flex-wrap gap-1">
                @foreach($lt as $t)
                    <a href="{{ route('production.tasks.show', $t) }}" class="status-pill {{ $t->status_pill }} text-decoration-none" title="{{ $t->status_label }}{{ $t->assignee ? ' · ' . $t->assignee->name : '' }}{{ $t->due_date ? ' · due ' . $t->due_date->format('d M') : '' }}">
                        @if($t->status === 'done')<i class="ri-check-line"></i>@elseif($t->isOverdue())<i class="ri-alarm-warning-line"></i>@endif
                        {{ $t->kind_label ? $t->kind_label . ': ' : '' }}{{ $t->kind === 'stage' ? $t->name : \Illuminate\Support\Str::limit(preg_replace('/^(Rework|Alteration): /', '', $t->name), 30) }}</a>
                @endforeach
            </div>
        </div>
    @empty
    @endforelse
    @if($p['tasks']->has(0))
        <div class="py-2 border-bottom small"><span class="fw-semibold">Whole order</span>
            @foreach($p['tasks']->get(0) as $t)<a href="{{ route('production.tasks.show', $t) }}" class="status-pill {{ $t->status_pill }} ms-1 text-decoration-none">{{ $t->name }}</a>@endforeach</div>
    @endif
    @if($p['tasks']->isEmpty() && $p['unplanned']->isEmpty())<p class="small text-muted mb-0">Nothing to make on this order.</p>@endif

    @if($pm && ! in_array($order->status, ['draft', 'cancelled', 'closed']))
        <a class="small d-inline-block mt-2" data-bs-toggle="collapse" href="#altBox"><i class="ri-add-line"></i> Add an alteration</a>
        <div class="collapse" id="altBox"><form method="POST" action="{{ route('orders.alterations.store', $order) }}" class="row g-2 mt-1 p-2 rounded-3" style="background:var(--cb-surface-2)">@csrf
            <div class="col-md-6"><select name="order_line_id" class="form-select form-select-sm"><option value="">Whole order</option>
                @foreach($order->lines->where('status', '!=', 'cancelled') as $l)<option value="{{ $l->id }}">{{ $l->description }}</option>@endforeach</select></div>
            <div class="col-md-6"><input name="name" class="form-control form-control-sm" required maxlength="120" placeholder="What to change, e.g. Shorten sleeves 1&quot;"></div>
            <div class="col-md-6"><input name="reason" class="form-control form-control-sm" required maxlength="255" placeholder="Why?"></div>
            <div class="col-md-6"><select name="responsible_party" class="form-select form-select-sm">@foreach(ProductionTask::RESPONSIBLE as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
            <div class="col-md-4"><select name="cost_treatment" class="form-select form-select-sm">@foreach(ProductionTask::COST_TREATMENT as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
            <div class="col-md-2"><input type="number" step="0.01" min="0" name="cost" class="form-control form-control-sm" placeholder="Cost"></div>
            <div class="col-md-3"><select name="assigned_to" class="form-select form-select-sm"><option value="">Assign later</option>@foreach($staff as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
            <div class="col-md-3"><input type="date" name="due_date" class="form-control form-control-sm" title="Due"></div>
            <div class="col-12"><button class="action-btn"><i class="ri-add-line"></i>Add to the board</button>
                <span class="small text-muted ms-1">A chargeable alteration is recorded here; bill it with an invoice line.</span></div>
        </form></div>
    @endif
</x-cb.card>

@if($pu->can('View production') || $pu->can('Manage fitting'))
<x-cb.card title="Fittings" icon="ri-calendar-check-line" class="mt-3" :count="$p['fittings']->count()">
    <x-slot:tools>@if($pu->can('Manage fitting') && in_array($order->status, ['confirmed', 'in_production', 'fitting', 'ready']))<a href="{{ route('fittings.create', ['order' => $order->public_id]) }}" class="action-btn"><i class="ri-calendar-event-line"></i>Book</a>@endif</x-slot:tools>
    @forelse($p['fittings'] as $f)
        <a href="{{ route('fittings.show', $f) }}" class="d-flex justify-content-between small py-2 border-bottom text-body">
            <span><span class="fw-semibold">{{ $f->scheduled_at->timezone($tz)->format('D d M, H:i') }}</span> · {{ $f->line?->description ?? 'Whole order' }}{{ $f->staff ? ' · ' . $f->staff->name : '' }}</span>
            <span class="status-pill {{ $f->status_pill }}">{{ $f->status_label }}{{ $f->outcome === 'alterations' ? ' · alterations' : '' }}</span></a>
    @empty
        <p class="small text-muted mb-0">No fittings booked.</p>
    @endforelse
</x-cb.card>
@endif

@if($p['deliveries']->isNotEmpty() || ($pu->can('Manage delivery') && $p['readyLines']->isNotEmpty()))
<x-cb.card title="Handover" icon="ri-hand-heart-line" class="mt-3">
    @foreach($p['deliveries'] as $d)
        <a href="{{ route('deliveries.show', $d) }}" target="_blank" class="d-flex justify-content-between small py-2 border-bottom text-body">
            <span><span class="fw-semibold">{{ $d->number }}</span> · {{ $d->method === 'delivery' ? 'Delivered to' : 'Collected by' }} {{ $d->recipient_name }} · {{ count($d->line_ids) }} item(s)</span>
            <span class="text-muted">{{ $d->handed_over_at->timezone($tz)->format('d M H:i') }}</span></a>
    @endforeach
    @if($pu->can('Manage delivery') && $p['readyLines']->isNotEmpty() && in_array($order->status, ['in_production', 'fitting', 'ready']))
        @php $owes = $balance > 0.004; @endphp
        @if($owes && ! $p['releaseWithBalance'])
            <div class="cb-banner warning mt-2"><i class="ri-wallet-3-line"></i><div>{{ $c->first_name }} still owes {{ \App\Support\Money::format($balance) }}. Take the payment before handing over (House settings can allow handover with a balance).</div></div>
        @else
        <form method="POST" action="{{ route('orders.deliveries.store', $order) }}" enctype="multipart/form-data" class="mt-2">@csrf
            <div class="small fw-semibold mb-1">Hand over</div>
            @foreach($p['readyLines'] as $l)
                <label class="d-flex gap-2 small"><input type="checkbox" name="line_ids[]" value="{{ $l->id }}" checked> {{ $l->description }}{{ $l->wearer ? ' · ' . $l->wearer : '' }}</label>
            @endforeach
            <div class="row g-2 mt-1">
                <div class="col-md-4"><select name="method" class="form-select form-select-sm" onchange="this.form.querySelector('.dl-addr').classList.toggle('d-none', this.value !== 'delivery')"><option value="collection">Collected in store</option><option value="delivery">Delivered</option></select></div>
                <div class="col-md-4"><input name="recipient_name" class="form-control form-control-sm" required maxlength="120" value="{{ $c->name }}" placeholder="Received by"></div>
                <div class="col-md-4"><input name="relationship" class="form-control form-control-sm" maxlength="60" placeholder="Relationship (if not the customer)"></div>
                <div class="col-md-4"><input name="recipient_phone" class="form-control form-control-sm" maxlength="30" value="{{ $c->phone }}" placeholder="Phone"></div>
                <div class="col-md-4"><select name="confirmation" class="form-select form-select-sm">@foreach(Delivery::CONFIRMATIONS as $k => $v)<option value="{{ $k }}" @selected($k === 'signature')>{{ $v }}</option>@endforeach</select></div>
                <div class="col-md-4"><input name="confirmation_ref" class="form-control form-control-sm" maxlength="120" placeholder="ID / code / note"></div>
                <div class="col-12 dl-addr d-none"><input name="address" class="form-control form-control-sm" maxlength="255" value="{{ $c->address }}" placeholder="Delivery address"></div>
                @if($owes)<div class="col-12"><textarea name="outstanding_conditions" class="form-control form-control-sm" rows="2" required placeholder="{{ \App\Support\Money::format($balance) }} still owed — how and when will it be paid?"></textarea></div>@endif
                <div class="col-md-8"><input type="file" name="evidence" accept="image/*" class="form-control form-control-sm" title="Photo (optional)"></div>
                <div class="col-md-4 d-grid"><button class="action-btn btn-primary-cb justify-content-center"><i class="ri-hand-heart-line"></i>Record handover</button></div>
            </div>
        </form>
        @endif
    @endif
</x-cb.card>
@endif
