@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
@php $open = $a->status === 'booked'; $when = $a->starts_at->copy()->timezone($tz); @endphp
    <x-cb.hero :title="$a->type_label . ' · ' . $a->customer->name" icon="ri-calendar-todo-line" :subtitle="$a->number . ' · ' . $when->format('l j F Y, g:i A') . ' · ' . $a->duration_minutes . ' min'"
               :back="route('appointments.index', ['from' => $when->toDateString()])" back-label="Appointments">
        <x-slot:pills>
            <span class="cb-meta-pill"><span class="status-pill {{ $a->status_pill }}">{{ $a->status_label }}</span></span>
            @if($a->staff)<span class="cb-meta-pill"><i class="ri-user-line me-1"></i>{{ $a->staff->name }}</span>@endif
            @if($a->branch)<span class="cb-meta-pill"><i class="ri-store-2-line me-1"></i>{{ $a->branch->name }}</span>@endif
        </x-slot:pills>
        <x-slot:actions>
            @if($a->customer->phone)<a href="tel:{{ $a->customer->phone }}" class="cb-hero-btn"><i class="ri-phone-line me-1"></i>Call</a>@endif
            <a href="{{ route('customers.show', $a->customer) }}" class="cb-hero-btn"><i class="ri-user-heart-line me-1"></i>Customer</a>
        </x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.flash')
    @if($errors->any())<div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>@endif

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card title="Details" icon="ri-information-line">
                <div class="row g-3 small">
                    <div class="col-sm-6"><div class="text-muted">Customer</div><a href="{{ route('customers.show', $a->customer) }}" class="fw-semibold">{{ $a->customer->name }}</a>@if($a->customer->phone)<div>{{ $a->customer->phone }}</div>@endif</div>
                    <div class="col-sm-6"><div class="text-muted">Order</div>@if($a->order)<a href="{{ route('orders.show', $a->order) }}">{{ $a->order->number }}</a>@else — @endif</div>
                    <div class="col-sm-6"><div class="text-muted">Booked by</div>{{ $a->creator?->name ?? '—' }} · {{ $a->created_at->timezone($tz)->format('j M, g:i A') }}</div>
                    @if($a->notes)<div class="col-12"><div class="text-muted">Notes</div>{{ $a->notes }}</div>@endif
                    @if($a->outcome)<div class="col-12"><div class="text-muted">What happened</div>{{ $a->outcome }}</div>@endif
                    @if($a->cancel_reason)<div class="col-12"><div class="text-muted">Why cancelled</div>{{ $a->cancel_reason }}</div>@endif
                </div>
            </x-cb.card>

            <x-cb.card title="Messages to {{ $a->customer->first_name }}" icon="ri-chat-3-line" :count="$messages->count()" class="mt-3">
                @forelse($messages as $msg)
                    <div class="py-2 small {{ $loop->last ? '' : 'border-bottom' }}">
                        <div class="d-flex justify-content-between"><span class="fw-semibold">{{ \App\Services\Messaging\CustomerUpdates::EVENTS[$msg->purpose][0] ?? 'Message' }}</span>
                            <span class="text-muted">{{ ($msg->send_after && $msg->status === 'queued' ? $msg->send_after : $msg->created_at)->timezone($tz)->format('j M, g:i A') }}</span></div>
                        <div class="text-muted">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $msg->body), 160) }}</div>
                        <span class="status-pill {{ ['sent' => 'st-success', 'failed' => 'st-danger', 'cancelled' => 'st-muted'][$msg->status] ?? 'st-info' }}">{{ ['sent' => 'Sent · ' . $msg->result, 'failed' => 'Not sent', 'cancelled' => 'Cancelled', 'queued' => $msg->send_after ? 'Will send' : 'Sending…', 'sending' => 'Sending…'][$msg->status] ?? $msg->status }}</span>
                        @if($msg->status === 'failed')<span class="text-muted">{{ $msg->result }}</span>@endif
                    </div>
                @empty
                    <p class="small text-muted mb-0">No messages. Turn on appointment confirmations and reminders in <a href="{{ route('settings.customer-messages') }}">Customer messages</a>.</p>
                @endforelse
            </x-cb.card>
        </div>

        <div class="col-xl-4">
            @if($open)
            <x-cb.card title="What happened?" icon="ri-checkbox-circle-line">
                <form method="post" action="{{ route('appointments.status', $a) }}">@csrf
                    <textarea name="note" rows="2" maxlength="1000" class="form-control form-control-sm mb-2" placeholder="Notes (needed to cancel)"></textarea>
                    <div class="d-flex flex-wrap gap-2">
                        <button name="status" value="done" class="btn btn-success btn-sm"><i class="ri-check-line me-1"></i>Done</button>
                        <button name="status" value="no_show" class="btn btn-warning btn-sm">Didn’t come</button>
                        <button name="status" value="cancelled" class="btn btn-light btn-sm">Cancel it</button>
                    </div>
                </form>
            </x-cb.card>
            <x-cb.card title="Move it" icon="ri-calendar-2-line" class="mt-3">
                <form method="post" action="{{ route('appointments.reschedule', $a) }}">@csrf
                    <input type="datetime-local" name="starts_at" class="form-control form-control-sm mb-2" required value="{{ $when->format('Y-m-d\TH:i') }}" step="300">
                    <select name="duration_minutes" class="form-select form-select-sm mb-2">@foreach([15, 30, 45, 60, 90, 120] as $m)<option value="{{ $m }}" @selected($a->duration_minutes === $m)>{{ $m }} minutes</option>@endforeach</select>
                    @if($errors->has('starts_at') && str_contains($errors->first('starts_at'), 'already have'))<label class="d-flex gap-2 small mb-2"><input type="checkbox" name="allow_clash" value="1"> Move anyway</label>@endif
                    <button class="btn btn-light btn-sm">Move</button>
                    <div class="form-text">The customer gets the new time, and the reminder moves with it.</div>
                </form>
            </x-cb.card>
            @endif
            @if($others->isNotEmpty())
            <x-cb.card title="{{ $a->customer->first_name }}’s other appointments" icon="ri-history-line" class="mt-3">
                @foreach($others as $o)
                    <a href="{{ route('appointments.show', $o) }}" class="d-flex justify-content-between py-1 small text-body"><span>{{ $o->type_label }} · {{ $o->starts_at->timezone($tz)->format('j M Y') }}</span><span class="status-pill {{ $o->status_pill }}">{{ $o->status_label }}</span></a>
                @endforeach
            </x-cb.card>
            @endif
        </div>
    </div>
</div>
</div>
</div>
@endsection
