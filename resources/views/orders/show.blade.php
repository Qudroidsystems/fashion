@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php
        use App\Support\Money; use App\Support\Orders\OrderStatus;
        $u = auth()->user();
        $canMoney = $u->can('View payment');
        $canEdit = $u->can('Update order');
        $c = $order->customer;
        $activeLines = $order->lines->where('status', '!=', 'cancelled');
    @endphp

    <x-cb.hero :title="$order->number . ($order->title ? ' · ' . $order->title : '')" icon="ri-file-list-3-line"
               :subtitle="$c->name . ' · ' . $c->customer_number . ($c->phone ? ' · ' . $c->phone : '')"
               :back="route('orders.index')" back-label="Orders">
        <x-slot:pills>
            <span class="status-pill {{ $order->status_pill }}">{{ $order->status_label }}</span>
            @if($order->priority === 'rush')<span class="status-pill st-danger">Rush</span>@endif
            @if($order->promised_date)<span class="cb-meta-pill {{ $order->isLate() ? 'bg-danger' : '' }}"><i class="ri-calendar-check-line me-1"></i>Promised {{ $order->promised_date->format('D d M Y') }}</span>@endif
            @if($order->event_date)<span class="cb-meta-pill"><i class="ri-cake-3-line me-1"></i>Event {{ $order->event_date->format('d M Y') }}</span>@endif
            @if($order->revision)<span class="cb-meta-pill">Revision {{ $order->revision }}</span>@endif
            @if($order->quotation)<a href="{{ route('quotations.show', $order->quotation) }}" class="cb-meta-pill text-white">From {{ $order->quotation->number }}</a>@endif
        </x-slot:pills>
        <x-slot:actions>
            <a href="{{ route('customers.show', $c) }}" class="cb-hero-btn"><i class="ri-user-line me-1"></i>Customer</a>
            @if($order->isEditable() && $canEdit)<a href="{{ route('orders.edit', $order) }}" class="cb-hero-btn"><i class="ri-pencil-line me-1"></i>Edit</a>@endif
            <button type="button" class="cb-hero-btn" onclick="window.print()"><i class="ri-printer-line me-1"></i>Job card</button>
        </x-slot:actions>
    </x-cb.hero>
    @include('orders.partials.nav')
    @include('inventory.partials.flash')

    @if($order->status === 'cancelled')
        <div class="cb-banner danger"><i class="ri-close-circle-line"></i><div>Cancelled {{ $order->cancelled_at?->timezone($tz)->format('d M Y') }}: {{ $order->cancel_reason }}
            @if($paid > 0) · {{ Money::format($paid) }} paid is the customer’s credit. @endif</div></div>
    @elseif($order->isLate())
        <div class="cb-banner danger"><i class="ri-alarm-warning-line"></i><div>Past the promised date ({{ $order->promised_date->format('d M Y') }}).</div></div>
    @endif
    @if($order->status === 'draft')
        <div class="cb-banner info"><i class="ri-draft-line"></i><div>Draft — confirm it when the customer agrees. Drafts don’t count as money owed.</div></div>
    @endif

    <div class="row g-3">
        <div class="col-xl-8">
            {{-- Garments --}}
            <x-cb.card title="Garments & items" icon="ri-t-shirt-air-line" :count="$activeLines->count()">
                @foreach($order->lines as $l)
                    <div class="py-3 border-bottom {{ $l->status === 'cancelled' ? 'opacity-50' : '' }}">
                        <div class="d-flex justify-content-between gap-3 flex-wrap">
                            <div class="flex-grow-1">
                                <div class="fw-semibold">{{ $l->qty !== '1' ? $l->qty . ' × ' : '' }}{{ $l->description }}
                                    <span class="status-pill {{ $l->status_pill }} ms-1">{{ $l->status_label }}</span></div>
                                <div class="small text-muted">
                                    {{ $l->kind_label }}
                                    @if($l->styleVersion) · <a href="{{ route('styles.show', $l->styleVersion->style) }}">{{ $l->styleVersion->style->code }} v{{ $l->styleVersion->version_no }}</a>@endif
                                    @if($l->options) · {{ collect($l->options)->pluck('label')->implode(', ') }}@endif
                                    @if($l->wearer) · for {{ $l->wearer }}@endif
                                </div>
                                @if($l->instructions)<div class="small mt-1"><i class="ri-scissors-line me-1 text-muted"></i>{{ $l->instructions }}</div>@endif
                            </div>
                            @if($canMoney)
                                <div class="text-end">
                                    <div class="fw-semibold">{{ Money::format($l->line_total) }}</div>
                                    @if($l->discount > 0)<div class="small text-muted">after {{ Money::format($l->discount) }} off</div>@endif
                                    @if($l->list_price !== null && $l->unit_price < $l->list_price)<div class="small text-warning">below catalogue {{ Money::format($l->list_price) }}</div>@endif
                                </div>
                            @endif
                        </div>
                        @if($l->status !== 'cancelled')
                            @if($canEdit && ! in_array($order->status, ['closed', 'cancelled', 'delivered']))
                                <form method="POST" action="{{ route('order-lines.update', $l->id) }}" class="row g-2 mt-2 align-items-end">
                                    @csrf @method('PATCH')
                                    @if($l->kind === 'bespoke')
                                        <div class="col-md-4"><label class="form-label small mb-1">Measurements</label>
                                            <select name="measurement_session_id" class="form-select form-select-sm">
                                                <option value="">— Not linked —</option>
                                                @foreach($sessions as $s)<option value="{{ $s->id }}" @selected($l->measurement_session_id === $s->id)>{{ $s->templateVersion->template->name }} · {{ $s->verified_at?->format('d M Y') }}</option>@endforeach
                                            </select></div>
                                    @endif
                                    <div class="col-md-3"><label class="form-label small mb-1">Assigned to</label>
                                        <select name="assigned_to" class="form-select form-select-sm"><option value="">—</option>
                                            @foreach($staff as $s)<option value="{{ $s->id }}" @selected($l->assigned_to === $s->id)>{{ $s->name }}</option>@endforeach</select></div>
                                    <div class="col-md-3"><label class="form-label small mb-1">For (wearer)</label><input name="wearer" class="form-control form-control-sm" maxlength="120" value="{{ $l->wearer }}" placeholder="{{ $c->first_name }}"></div>
                                    <div class="col-md-2 d-grid"><button class="btn btn-sm btn-light"><i class="ri-save-line"></i> Save</button></div>
                                </form>
                                @if($l->kind === 'bespoke' && ! $l->measurement_session_id)
                                    <div class="small text-warning mt-1"><i class="ri-ruler-line me-1"></i>No measurements linked.
                                        @if($sessions->isEmpty())<a href="{{ route('measurements.create', $c) }}">Measure {{ $c->first_name }}</a>@endif</div>
                                @endif
                            @else
                                <div class="small text-muted mt-1">
                                    @if($l->measurementSession)<i class="ri-ruler-line"></i> <a href="{{ route('measurements.show', $l->measurementSession) }}">{{ $l->measurementSession->templateVersion->template->name }} measurements</a>@endif
                                    @if($l->assignee) · {{ $l->assignee->name }}@endif
                                </div>
                            @endif
                        @endif
                    </div>
                @endforeach
                @if($canMoney)
                    <div class="d-flex justify-content-end gap-4 pt-3 small">
                        @if($order->discount_total > 0)<span class="text-muted">Discounts −{{ Money::format($order->discount_total) }}</span>@endif
                        <span class="fw-bold fs-6">Total {{ Money::format($order->total) }}</span>
                    </div>
                @endif
            </x-cb.card>

            {{-- Materials --}}
            @can('View inventory')
            <x-cb.card title="Materials" icon="ri-archive-line" class="mt-3" :flush="true">
                <x-slot:tools>
                    @if(in_array($order->status, OrderStatus::OPEN) && $u->can('Issue material'))
                        <a href="{{ route('material-issues.create', ['order' => $order->public_id]) }}" class="action-btn"><i class="ri-scissors-cut-line"></i>Issue to workroom</a>
                    @endif
                </x-slot:tools>
                @if($order->materials->isEmpty() && $issues->isEmpty())
                    <p class="text-muted small p-3 mb-0">No materials planned yet. Reserve fabric and trims so nobody uses them for another job.</p>
                @endif
                @if($order->materials->isNotEmpty())
                    <div class="table-responsive"><table class="cb-table stack mb-0">
                        <thead><tr><th>Material</th><th>For</th><th class="text-end">Needed</th><th>Reservation</th><th></th></tr></thead>
                        <tbody>
                        @foreach($order->materials as $m)
                            @php $r = $m->reservation; @endphp
                            <tr>
                                <td data-label="Material"><a href="{{ route('inventory.items.show', $m->item) }}" class="fw-semibold">{{ $m->item->label }}</a>@if($m->note)<div class="small text-muted">{{ $m->note }}</div>@endif</td>
                                <td data-label="For" class="small">{{ $m->line?->description ?? 'Whole order' }}</td>
                                <td data-label="Needed" class="text-end">{{ $m->item->qty($m->quantity) }}</td>
                                <td data-label="Reservation">
                                    @if(! $r)<span class="text-muted small">—</span>
                                    @elseif($r->status === 'active')<span class="status-pill st-warning">Reserved</span>@if($r->consumed > 0)<span class="small text-muted ms-1">{{ $m->item->qty($r->consumed) }} issued</span>@endif
                                    @elseif($r->status === 'consumed')<span class="status-pill st-success">Issued</span>
                                    @else<span class="status-pill st-muted">Released</span>@endif
                                </td>
                                <td class="text-end">
                                    @if($canEdit && $r?->status === 'active')
                                        <form method="POST" action="{{ route('order-materials.destroy', $m->id) }}" onsubmit="return confirm('Remove this material and release its reservation?')">@csrf @method('DELETE')
                                            <button class="btn btn-sm btn-light" title="Remove"><i class="ri-close-line"></i></button></form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
                @if($canEdit && in_array($order->status, ['draft', ...OrderStatus::OPEN]))
                    <form method="POST" action="{{ route('orders.materials.store', $order) }}" class="row g-2 p-3 border-top">
                        @csrf
                        <div class="col-md-4"><select name="item_id" class="form-select form-select-sm" required><option value="">Reserve material…</option>
                            @foreach($items as $it)<option value="{{ $it->id }}">{{ $it->name }}{{ $it->colour ? ' · ' . $it->colour : '' }} ({{ $it->unit }})</option>@endforeach</select></div>
                        <div class="col-md-2"><input type="number" step="any" min="0" name="quantity" class="form-control form-control-sm" required placeholder="Qty"></div>
                        <div class="col-md-2"><select name="branch_id" class="form-select form-select-sm">@foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></div>
                        <div class="col-md-2"><select name="order_line_id" class="form-select form-select-sm"><option value="">Whole order</option>
                            @foreach($activeLines as $l)<option value="{{ $l->id }}">{{ \Illuminate\Support\Str::limit($l->description, 24) }}</option>@endforeach</select></div>
                        <div class="col-md-2 d-grid"><button class="action-btn btn-primary-cb justify-content-center"><i class="ri-lock-line"></i>Reserve</button></div>
                        <div class="col-12"><label class="small d-flex gap-1"><input type="checkbox" name="allow_short" value="1"> Reserve even if not all in stock yet (it is on order)</label></div>
                    </form>
                @endif
                @if($issues->isNotEmpty())
                    <div class="p-3 border-top">
                        <div class="fw-semibold small mb-2">Issued to the workroom</div>
                        @foreach($issues as $is)
                            <a href="{{ route('material-issues.show', $is) }}" class="d-flex justify-content-between small py-1 text-body">
                                <span>{{ $is->number }} · {{ $is->lines->map(fn ($l) => $l->item->name . ' ' . $l->item->qty($l->net_quantity))->implode(', ') }}</span>
                                @can('View costing')<span class="text-muted">{{ Money::format($is->total_cost) }}</span>@endcan
                            </a>
                        @endforeach
                    </div>
                @endif
            </x-cb.card>
            @endcan

            {{-- History --}}
            <x-cb.card title="History" icon="ri-history-line" class="mt-3">
                @foreach($order->events as $e)
                    <div class="d-flex gap-2 py-2 border-bottom small">
                        <i class="ri-arrow-right-circle-line text-muted"></i>
                        <div class="flex-grow-1"><span class="fw-semibold">{{ $e->label }}</span>@if($e->note) — {{ $e->note }}@endif
                            @unless($e->customer_visible)<span class="badge bg-light text-muted ms-1">staff only</span>@endunless</div>
                        <span class="text-muted text-nowrap">{{ $e->created_at->timezone($tz)->format('d M, H:i') }} · {{ $e->user?->name }}</span>
                    </div>
                @endforeach
                @foreach($order->revisions as $r)
                    <div class="d-flex gap-2 py-2 border-bottom small">
                        <i class="ri-edit-box-line text-muted"></i>
                        <div class="flex-grow-1"><span class="fw-semibold">Revision {{ $r->revision }}</span> — {{ $r->reason }}
                            @if($canMoney)<span class="text-muted">({{ Money::format($r->total_before) }} → {{ Money::format($r->total_after) }})</span>@endif</div>
                        <span class="text-muted text-nowrap">{{ $r->created_at->timezone($tz)->format('d M, H:i') }} · {{ $r->user?->name }}</span>
                    </div>
                @endforeach
                <div class="d-flex gap-2 py-2 small"><i class="ri-add-circle-line text-muted"></i><div class="flex-grow-1">Created</div>
                    <span class="text-muted">{{ $order->created_at->timezone($tz)->format('d M Y, H:i') }} · {{ $order->creator?->name }}</span></div>
            </x-cb.card>
        </div>

        <div class="col-xl-4">
            {{-- Progress --}}
            <x-cb.card title="Progress" icon="ri-route-line">
                @if($canEdit && $next)
                    <form method="POST" action="{{ route('orders.transition', $order) }}">
                        @csrf
                        <input name="note" class="form-control form-control-sm mb-2" maxlength="500" placeholder="Note (optional), e.g. First fitting Saturday 10am">
                        <div class="d-grid gap-2">
                            @foreach($next as $to)
                                <button name="to" value="{{ $to }}" class="action-btn {{ $loop->first ? 'btn-primary-cb' : '' }} justify-content-center py-2">
                                    <i class="ri-arrow-right-line"></i>{{ $to === 'in_production' && $order->status === 'fitting' ? 'Back to production (alteration)' : 'Mark ' . strtolower(OrderStatus::label($to)) }}</button>
                            @endforeach
                        </div>
                    </form>
                @elseif(! $next)
                    <p class="small text-muted mb-0">{{ $order->status === 'closed' ? 'This order is closed.' : ($order->status === 'cancelled' ? 'This order was cancelled.' : '') }}</p>
                @endif
                @can('Cancel order')
                    @if(in_array($order->status, OrderStatus::CANCELLABLE))
                        <button type="button" class="action-btn w-100 justify-content-center mt-2 text-danger" data-bs-toggle="collapse" data-bs-target="#cancelBox"><i class="ri-close-circle-line"></i>Cancel order</button>
                        <div class="collapse mt-2" id="cancelBox"><form method="POST" action="{{ route('orders.cancel', $order) }}">@csrf
                            <input name="reason" class="form-control form-control-sm mb-2" required maxlength="255" placeholder="Why?">
                            <p class="small text-muted mb-2">Reserved materials are released. Issued material stays issued. Money paid becomes the customer’s credit.</p>
                            <button class="btn btn-sm btn-danger w-100">Cancel order</button></form></div>
                    @elseif(isset(OrderStatus::REOPEN[$order->status]))
                        <button type="button" class="action-btn w-100 justify-content-center mt-2" data-bs-toggle="collapse" data-bs-target="#reopenBox"><i class="ri-restart-line"></i>Reopen</button>
                        <div class="collapse mt-2" id="reopenBox"><form method="POST" action="{{ route('orders.reopen', $order) }}">@csrf
                            <input name="reason" class="form-control form-control-sm mb-2" required maxlength="255" placeholder="Why?">
                            <button class="btn btn-sm btn-primary w-100">Reopen</button></form></div>
                    @endif
                @endcan
            </x-cb.card>

            {{-- Money --}}
            @if($canMoney)
            <x-cb.card title="Money" icon="ri-wallet-3-line" class="mt-3">
                <div class="d-flex justify-content-between"><span class="text-muted">Order total</span><span class="fw-semibold">{{ Money::format($order->total) }}</span></div>
                <div class="d-flex justify-content-between"><span class="text-muted">Deposit required</span><span>{{ Money::format($order->deposit_required) }}</span></div>
                <div class="d-flex justify-content-between"><span class="text-muted">Paid</span><span class="text-success">{{ Money::format($paid) }}</span></div>
                <div class="d-flex justify-content-between border-top pt-2 mt-2 fs-5 fw-bold">
                    <span>{{ $balance < 0 ? 'Credit' : 'Balance' }}</span><span class="{{ $balance > 0 ? 'text-danger' : 'text-success' }}">{{ Money::format(abs($balance)) }}</span></div>
                @if($order->status !== 'draft' && $order->status !== 'cancelled' && $paid < $order->deposit_required)
                    <div class="small text-warning mt-1"><i class="ri-error-warning-line"></i> Deposit not yet complete ({{ Money::format($order->deposit_required - $paid) }} to go).</div>
                @endif

                <div class="d-grid gap-2 mt-3">
                    @can('Record payment')
                        @if($balance > 0)<a href="{{ route('payments.create', ['order' => $order->public_id]) }}" class="action-btn btn-primary-cb justify-content-center py-2"><i class="ri-bank-card-line"></i>Record payment</a>@endif
                        @foreach($credit as $p)
                            @if($balance > 0)
                                <form method="POST" action="{{ route('payments.allocate', $p) }}" class="d-grid">@csrf
                                    <input type="hidden" name="order_id" value="{{ $order->id }}">
                                    <button class="action-btn justify-content-center"><i class="ri-coins-line"></i>Use {{ Money::format(min($p->unallocated(), $balance)) }} credit from {{ $p->number }}</button></form>
                            @endif
                        @endforeach
                    @endcan
                    @can('Manage invoice')
                        @if($unbilled > 0 && ! in_array($order->status, ['draft', 'cancelled']))
                            <button type="button" class="action-btn justify-content-center" data-bs-toggle="collapse" data-bs-target="#invBox"><i class="ri-bill-line"></i>Create invoice</button>
                            <div class="collapse" id="invBox"><form method="POST" action="{{ route('invoices.from-order', $order) }}" class="p-2 rounded-3" style="background:#f6f8fb">@csrf
                                <select name="kind" class="form-select form-select-sm mb-2">
                                    @if($paid < $order->deposit_required)<option value="deposit">Deposit ({{ Money::format($order->deposit_required - $paid) }})</option>@endif
                                    <option value="balance">Balance ({{ Money::format($unbilled) }})</option>
                                    <option value="full">Full itemised invoice</option>
                                </select>
                                <input type="number" step="0.01" min="0" name="amount" class="form-control form-control-sm mb-2" placeholder="Amount (optional)">
                                <button class="btn btn-sm btn-primary w-100">Create draft invoice</button></form></div>
                        @endif
                    @endcan
                </div>

                @if($invoices->isNotEmpty())
                    <div class="fw-semibold small mt-3 mb-1">Invoices</div>
                    @foreach($invoices as $inv)
                        <a href="{{ route('invoices.show', $inv) }}" class="d-flex justify-content-between small py-1 text-body">
                            <span>{{ $inv->number ?? 'Draft' }} · {{ \App\Models\Invoice::KINDS[$inv->kind] }}</span>
                            <span>{{ Money::format($inv->total) }} <span class="status-pill {{ $inv->status_pill }} ms-1">{{ $inv->status_label }}</span></span>
                        </a>
                    @endforeach
                @endif
                @if($payments->isNotEmpty())
                    <div class="fw-semibold small mt-3 mb-1">Payments</div>
                    @foreach($payments as $a)
                        <a href="{{ route('payments.show', $a->payment) }}" class="d-flex justify-content-between small py-1 text-body">
                            <span>{{ $a->payment->number }} · {{ $a->payment->method_label }}@if($a->invoice) → {{ $a->invoice->number }}@endif
                                @if($a->amount < 0)<span class="d-block text-muted">{{ $a->note }}</span>@endif</span>
                            <span class="{{ $a->amount < 0 ? 'text-danger' : '' }}">{{ Money::format($a->amount) }}</span>
                        </a>
                    @endforeach
                @endif
            </x-cb.card>
            @endif

            <x-cb.card title="Details" icon="ri-information-line" class="mt-3">
                <dl class="row small mb-0">
                    <dt class="col-5 text-muted fw-normal">Owner</dt><dd class="col-7">{{ $order->owner?->name ?? '—' }}</dd>
                    <dt class="col-5 text-muted fw-normal">Branch</dt><dd class="col-7">{{ $order->branch?->name ?? '—' }}</dd>
                    @if($order->confirmed_at)<dt class="col-5 text-muted fw-normal">Confirmed</dt><dd class="col-7">{{ $order->confirmed_at->timezone($tz)->format('d M Y') }}</dd>@endif
                    @if($order->customer_notes)<dt class="col-5 text-muted fw-normal">For customer</dt><dd class="col-7">{{ $order->customer_notes }}</dd>@endif
                    @if($order->internal_notes)<dt class="col-5 text-muted fw-normal">Internal</dt><dd class="col-7">{{ $order->internal_notes }}</dd>@endif
                </dl>
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>
@endsection
