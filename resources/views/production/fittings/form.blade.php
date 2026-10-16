@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    <x-cb.hero title="Book a fitting" icon="ri-calendar-event-line" :subtitle="$order ? $order->number . ' · ' . $order->customer->name : 'Choose the customer and a time.'"
               :back="$order ? route('orders.show', $order) : route('fittings.index')" :back-label="$order ? 'Order' : 'Fittings'" />
    @include('inventory.partials.flash')
    <form method="POST" action="{{ route('fittings.store') }}" class="row g-3">@csrf
        <div class="col-xl-8">
            <x-cb.card title="Fitting" icon="ri-calendar-event-line">
                <div class="row g-3">
                    @if($order)
                        <input type="hidden" name="order_id" value="{{ $order->id }}">
                        <div class="col-md-6"><label class="form-label">Garment</label>
                            <select name="order_line_id" class="form-select"><option value="">Whole order</option>
                                @foreach($order->lines->whereNotIn('status', ['cancelled', 'delivered']) as $l)
                                    <option value="{{ $l->id }}" @selected(old('order_line_id', $task?->order_line_id) == $l->id)>{{ $l->description }}{{ $l->wearer ? ' — ' . $l->wearer : '' }}</option>
                                @endforeach</select></div>
                        @if($task)<input type="hidden" name="task_id" value="{{ $task->id }}">@endif
                    @else
                        <div class="col-md-6"><label class="form-label">Customer</label>
                            <select name="customer_id" class="form-select" required><option value="">Choose…</option>
                                @foreach($customers as $c)<option value="{{ $c->id }}" @selected(old('customer_id', $customer?->id) == $c->id)>{{ $c->name }} · {{ $c->customer_number }}</option>@endforeach</select></div>
                    @endif
                    <div class="col-md-6"><label class="form-label">With</label>
                        <select name="staff_id" class="form-select"><option value="">Anyone</option>
                            @foreach($staff as $s)<option value="{{ $s->id }}" @selected(old('staff_id', $task?->assigned_to ?? auth()->id()) == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
                    <div class="col-md-4"><label class="form-label">Date</label><input type="date" name="date" class="form-control" required min="{{ now($tz)->toDateString() }}" value="{{ old('date', now($tz)->addDay()->toDateString()) }}"></div>
                    <div class="col-md-4"><label class="form-label">Time</label><input type="time" name="time" class="form-control" required value="{{ old('time', '11:00') }}"></div>
                    <div class="col-md-4"><label class="form-label">Length</label><select name="duration_minutes" class="form-select">
                        @foreach([15, 30, 45, 60, 90, 120] as $m)<option value="{{ $m }}" @selected(old('duration_minutes', 30) == $m)>{{ $m < 60 ? $m . ' min' : ($m / 60) . ' h' }}</option>@endforeach</select></div>
                    <div class="col-md-6"><label class="form-label">Where</label><select name="branch_id" class="form-select">
                        @foreach($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id', $order?->branch_id) == $b->id)>{{ $b->name }}</option>@endforeach</select></div>
                    <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2" maxlength="1000" placeholder="e.g. bring the shoes she'll wear">{{ old('notes') }}</textarea></div>
                </div>
            </x-cb.card>
        </div>
        <div class="col-xl-4">
            <x-cb.card title="Reminders" icon="ri-notification-3-line">
                <p class="small text-muted">Reminders go out {{ collect(\App\Support\Tenancy\CurrentTenant::get()->setting('fitting_reminder_hours', [24, 2]))->map(fn ($h) => $h . 'h')->implode(' and ') }} before — in-app to the staff member, and by email to the customer if they agreed to order updates by email. Moving the fitting cancels the old reminders.</p>
                <button class="action-btn btn-primary-cb w-100 justify-content-center py-2"><i class="ri-calendar-check-line"></i>Book fitting</button>
            </x-cb.card>
        </div>
    </form>
</div>
</div>
</div>
@endsection
