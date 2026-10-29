@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
@php
    use App\Models\CustomerMaterial;
    use App\Models\MessageLog;
    $c = $m->customer;
    $canManage = auth()->user()->can('Manage customer materials');
    $with = $m->isWithHouse();
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
@endphp

    <x-cb.hero :title="$m->number" icon="ri-archive-drawer-line" :subtitle="$m->summary . ' · ' . $c->name" :back="route('materials.index')" back-label="Customer materials">
        <x-slot:pills>
            <span class="cb-meta-pill">{{ $m->status_label }}</span>
            <span class="cb-meta-pill">{{ $m->kind_label }} · {{ CustomerMaterial::PURPOSES[$m->purpose] ?? $m->purpose }}</span>
            @if($with)<span class="cb-meta-pill">With us {{ $m->daysHeld() }} {{ \Illuminate\Support\Str::plural('day', $m->daysHeld()) }}</span>@endif
            @if($due && $due->lte(now($tz)->startOfDay()))<span class="cb-meta-pill"><i class="ri-alarm-warning-line me-1 text-danger"></i>Should go back since {{ $due->format('j M') }}</span>@endif
        </x-slot:pills>
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('materials.tag', $m) }}" target="_blank" id="printTag"><i class="ri-price-tag-3-line me-1"></i>Print tag</a>
            @if($canManage)<a class="cb-hero-btn" href="{{ route('materials.edit', $m) }}"><i class="ri-pencil-line me-1"></i>Edit</a>@endif
        </x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.flash')
    @if(session('print_tag'))<script>document.addEventListener('DOMContentLoaded', () => window.open(document.getElementById('printTag').href, '_blank'));</script>@endif

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card title="Photos" icon="ri-camera-line" :count="count($m->photos ?? [])">
                @if($m->photos)
                    <div class="d-flex flex-wrap gap-2">
                        @foreach($m->photoUrls() as $i => $u)
                            <div class="position-relative">
                                <a href="{{ $u }}" target="_blank"><img src="{{ $u }}" alt="Photo {{ $i + 1 }}" width="140" height="140" class="rounded object-fit-cover"></a>
                                @if($canManage)
                                <form method="post" action="{{ route('materials.photos.destroy', [$m, $i]) }}" class="position-absolute top-0 end-0 m-1" onsubmit="return confirm('Remove this photo?')">@csrf @method('delete')
                                    <button class="btn btn-sm btn-light rounded-circle p-0" style="width:26px;height:26px" title="Remove"><i class="ri-close-line"></i></button></form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-muted small mb-2">No photos yet. A photo makes it easy to find and to agree on its condition when it goes back.</p>
                @endif
                @if($canManage && count($m->photos ?? []) < \App\Services\Materials\MaterialService::MAX_PHOTOS)
                    <form method="post" action="{{ route('materials.photos', $m) }}" enctype="multipart/form-data" class="mt-3">@csrf
                        @include('materials.partials.photo-picker', ['max' => \App\Services\Materials\MaterialService::MAX_PHOTOS - count($m->photos ?? []), 'autoSubmit' => true])
                    </form>
                @endif
            </x-cb.card>

            <x-cb.card title="Details" icon="ri-information-line" class="mt-3">
                <div class="row g-3 small">
                    <div class="col-md-4"><div class="text-muted">Customer</div><a href="{{ route('customers.show', $c) }}#materials" class="fw-semibold">{{ $c->name }}</a><div>{{ $c->phone }}</div></div>
                    <div class="col-md-4"><div class="text-muted">Order</div>@if($m->order)<a href="{{ route('orders.show', $m->order) }}" class="fw-semibold">{{ $m->order->number }}</a><div>{{ \App\Support\Orders\OrderStatus::label($m->order->status) }}{{ $m->line ? ' · ' . $m->line->description : '' }}</div>@else<span>—</span>@endif</div>
                    <div class="col-md-4"><div class="text-muted">Where</div><div class="fw-semibold">{{ $m->location ?: '—' }}</div><div>{{ $m->branch?->name }}</div></div>
                    <div class="col-md-4"><div class="text-muted">Received</div>{{ $m->received_at->timezone($tz)->format('j M Y, g:i A') }}<div>by {{ $m->receiver?->name ?? '—' }}</div></div>
                    <div class="col-md-4"><div class="text-muted">Quantity</div>{{ $m->quantity ? $qty($m->quantity) . ' ' . (CustomerMaterial::UNITS[$m->unit] ?? $m->unit) : '—' }}</div>
                    <div class="col-md-4"><div class="text-muted">Collect by</div>{{ $m->collect_by?->format('j M Y') ?? '—' }}</div>
                    @if($m->condition_notes)<div class="col-12"><div class="text-muted">Condition and what came with it</div>{!! nl2br(e($m->condition_notes)) !!}</div>@endif
                    @if(! $with)
                        <div class="col-12"><div class="cb-banner {{ $m->status === 'written_off' ? 'danger' : 'success' }} mb-0"><i class="ri-checkbox-circle-line"></i><div>
                            @if($m->status === 'returned')
                                Returned {{ $m->returned_at->timezone($tz)->format('j M Y, g:i A') }} to <strong>{{ $m->returned_to }}</strong>{{ $m->returned_to_phone ? ' (' . $m->returned_to_phone . ')' : '' }} by {{ $m->returner?->name }}
                                @if($m->returned_quantity) · {{ $qty($m->returned_quantity) }} {{ $m->unit }} left over @endif
                                @if($m->delivery) · with <a href="{{ route('deliveries.show', $m->delivery) }}">{{ $m->delivery->number }}</a>@endif
                            @else
                                {{ $m->status_label }} {{ $m->returned_at?->timezone($tz)->format('j M Y') }} by {{ $m->returner?->name }}
                            @endif
                            @if($m->return_note)<div class="small">{{ $m->return_note }}</div>@endif
                        </div></div></div>
                    @endif
                </div>
            </x-cb.card>

            <x-cb.card title="History" icon="ri-history-line" class="mt-3">
                <ul class="list-unstyled mb-0">
                    @foreach($m->events as $e)
                        <li class="d-flex gap-2 py-2 border-bottom small">
                            <i class="{{ match ($e->event) { 'received' => 'ri-inbox-archive-line text-success', 'returned' => 'ri-hand-heart-line text-success', 'reminded' => 'ri-notification-3-line text-warning', 'written_off' => 'ri-error-warning-line text-danger', 'photo' => 'ri-camera-line', 'note' => 'ri-sticky-note-line', default => 'ri-arrow-right-line text-muted' } }}"></i>
                            <div class="flex-grow-1"><span class="fw-semibold">{{ $e->label }}</span>{{ $e->detail ? ' — ' . $e->detail : '' }}
                                <div class="text-muted">{{ $e->created_at->timezone($tz)->format('j M Y, g:i A') }} · {{ $e->user?->name ?? 'Automatic' }}</div></div>
                        </li>
                    @endforeach
                </ul>
                @if($canManage)
                <form method="post" action="{{ route('materials.note', $m) }}" class="d-flex gap-2 mt-3">@csrf
                    <input name="note" class="form-control form-control-sm" maxlength="255" placeholder="Add a note, e.g. customer called, will come Saturday" required>
                    <button class="btn btn-light btn-sm">Add</button></form>
                @endif
            </x-cb.card>
        </div>

        <div class="col-xl-4">
            @if($canManage && $with)
            <x-cb.card title="Give it back" icon="ri-hand-heart-line">
                <form method="post" action="{{ route('materials.return') }}">@csrf
                    <label class="d-flex gap-2 small mb-1"><input type="checkbox" name="materials[]" value="{{ $m->public_id }}" checked onclick="return false"> {{ $m->number }} · {{ $m->summary }}</label>
                    @if($m->quantity)<div class="input-group input-group-sm mb-1 ms-4" style="max-width:220px"><span class="input-group-text">Left over</span><input type="number" step="0.01" min="0" name="quantities[{{ $m->public_id }}]" class="form-control"><span class="input-group-text">{{ $m->unit }}</span></div>@endif
                    @foreach($others as $o)
                        <label class="d-flex gap-2 small mb-1"><input type="checkbox" name="materials[]" value="{{ $o->public_id }}"> {{ $o->number }} · {{ $o->summary }}</label>
                    @endforeach
                    @if($others->isNotEmpty())<div class="form-text mb-1">Also tick the customer’s other items going back now.</div>@endif
                    <input name="returned_to" class="form-control form-control-sm mt-2" required maxlength="120" value="{{ $c->name }}" placeholder="Collected by">
                    <input name="returned_to_phone" class="form-control form-control-sm mt-2" maxlength="30" value="{{ $c->phone }}" placeholder="Phone">
                    <input name="note" class="form-control form-control-sm mt-2" maxlength="255" placeholder="Note (optional)">
                    <button class="action-btn btn-primary-cb w-100 justify-content-center mt-2"><i class="ri-hand-heart-line"></i>Record as given back</button>
                </form>
            </x-cb.card>

            <x-cb.card title="Move" icon="ri-drag-move-line" class="mt-3">
                <form method="post" action="{{ route('materials.move', $m) }}" class="d-flex gap-2">@csrf
                    <input type="hidden" name="to" value="{{ $m->status === 'held' ? 'in_use' : 'held' }}">
                    <input name="location" class="form-control form-control-sm" maxlength="120" placeholder="{{ $m->status === 'held' ? 'e.g. With Bisi (cutting)' : 'Shelf' }}" value="{{ $m->status === 'held' ? '' : $m->location }}">
                    <button class="btn btn-light btn-sm text-nowrap">{{ $m->status === 'held' ? 'To workroom' : 'Back in store' }}</button>
                </form>
                <details class="mt-3 small"><summary class="text-muted">Nothing to give back?</summary>
                    <form method="post" action="{{ route('materials.close', $m) }}" class="mt-2">@csrf
                        <input name="note" class="form-control form-control-sm mb-2" maxlength="255" placeholder="Note — required if writing off">
                        <div class="d-flex gap-2">
                            <button name="how" value="used" class="btn btn-light btn-sm">All used in the garment</button>
                            <button name="how" value="written_off" class="btn btn-outline-danger btn-sm" onclick="return confirm('Write this item off as lost or damaged?')">Lost / damaged</button>
                        </div>
                    </form>
                </details>
            </x-cb.card>

            <x-cb.card title="Remind {{ $c->first_name }}" icon="ri-notification-3-line" class="mt-3">
                <p class="small text-muted mb-2">
                    @if($next) Next automatic reminder: <strong>{{ $next->format('j M') }}</strong>.
                    @elseif(! $settings['reminders']) Automatic reminders are off.
                    @elseif(! $due) Reminders start once {{ $m->order ? 'the order is handed over' : 'it is due' }}.
                    @else No more automatic reminders ({{ $m->reminders_sent }} sent). @endif
                    The message lists everything of theirs still with you.
                </p>
                <form method="post" action="{{ route('customers.materials.remind', $c) }}">@csrf
                    @foreach(MessageLog::CHANNELS as $k => $label)
                        <label class="d-flex gap-2 small"><input type="checkbox" name="channels[]" value="{{ $k }}" @checked($channels[$k] ?? false) @disabled(! ($channels[$k] ?? false))>
                            {{ $label }} @unless($channels[$k] ?? false)<span class="text-muted">— not set up</span>@endunless</label>
                    @endforeach
                    <button class="btn btn-primary btn-sm mt-2">Send reminder now</button>
                    @if($waLink)<a href="{{ $waLink }}" target="_blank" rel="noopener" class="btn btn-success btn-sm mt-2"><i class="ri-whatsapp-line me-1"></i>Open in my WhatsApp</a>@endif
                </form>
                @if($messages->isNotEmpty())
                    <div class="small fw-semibold mt-3 mb-1">Sent</div>
                    @foreach($messages as $l)
                        <div class="small d-flex justify-content-between border-bottom py-1">
                            <span>{{ MessageLog::CHANNELS[$l->channel] }} · {{ $l->created_at->timezone($tz)->format('j M, g:i A') }}</span>
                            <span class="status-pill {{ $l->status === 'sent' ? 'st-success' : ($l->status === 'failed' ? 'st-danger' : 'st-muted') }}" title="{{ $l->error }}">{{ $l->status === 'sent' ? 'Sent' : ($l->status === 'failed' ? 'Failed' : 'Skipped') }}</span>
                        </div>
                    @endforeach
                @endif
            </x-cb.card>
            @endif
        </div>
    </div>

</div>
</div>
</div>
@endsection
