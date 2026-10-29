@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    <x-cb.hero title="Book an appointment" icon="ri-calendar-event-line" subtitle="The customer gets a confirmation now and a reminder the day before, the way you set in Customer messages."
               :back="route('appointments.index')" back-label="Appointments" />
    @include('inventory.partials.flash')
    @if($errors->any())<div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>@endif

    <form method="post" action="{{ route('appointments.store') }}">@csrf
        <div class="row g-3">
            <div class="col-xl-8">
                <x-cb.card title="Who and what" icon="ri-user-heart-line">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Customer</label>
                            @if($customer)
                                <input type="hidden" name="customer_id" value="{{ $customer->id }}">
                                <div class="form-control bg-light">{{ $customer->name }} · {{ $customer->customer_number }}</div>
                            @else
                                <select name="customer_id" class="form-select" required onchange="location.search='?customer='+this.selectedOptions[0].dataset.pid+'&type='+document.querySelector('[name=type]').value">
                                    <option value="">Choose…</option>
                                    @foreach($customers as $c)<option value="{{ $c->id }}" data-pid="{{ $c->public_id }}" @selected(old('customer_id') == $c->id)>{{ $c->name }} · {{ $c->customer_number }}{{ $c->phone ? ' · ' . $c->phone : '' }}</option>@endforeach
                                </select>
                                <div class="form-text">New customer? <a href="{{ route('customers.create') }}">Add them first</a>.</div>
                            @endif
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">What for</label>
                            <select name="type" class="form-select">@foreach(\App\Models\Appointment::TYPES as $k => $l)<option value="{{ $k }}" @selected(old('type', $a->type) === $k)>{{ $l }}</option>@endforeach</select>
                        </div>
                        @if($orders->isNotEmpty())
                        <div class="col-md-6">
                            <label class="form-label">Order <span class="text-muted">(optional)</span></label>
                            <select name="order_id" class="form-select"><option value="">—</option>@foreach($orders as $o)<option value="{{ $o->id }}" @selected(old('order_id', $a->order_id) == $o->id)>{{ $o->number }}{{ $o->title ? ' · ' . $o->title : '' }}</option>@endforeach</select>
                        </div>
                        @endif
                        <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" rows="2" maxlength="1000" class="form-control" placeholder="e.g. Bride and two bridesmaids; bring fabric samples">{{ old('notes') }}</textarea></div>
                    </div>
                </x-cb.card>
            </div>
            <div class="col-xl-4">
                <x-cb.card title="When and with whom" icon="ri-time-line">
                    <label class="form-label">Date and time</label>
                    <input type="datetime-local" name="starts_at" class="form-control mb-3" required value="{{ old('starts_at') }}" min="{{ now($tz)->format('Y-m-d\TH:i') }}" step="300">
                    <label class="form-label">How long</label>
                    <select name="duration_minutes" class="form-select mb-3">@foreach([15, 30, 45, 60, 90, 120] as $m)<option value="{{ $m }}" @selected((int) old('duration_minutes', $a->duration_minutes) === $m)>{{ $m < 60 ? $m . ' minutes' : ($m / 60) . ' hour' . ($m > 60 ? 's' : '') }}</option>@endforeach</select>
                    <label class="form-label">With</label>
                    <select name="staff_id" class="form-select mb-3"><option value="">Anyone</option>@foreach($staff as $s)<option value="{{ $s->id }}" @selected((int) old('staff_id', $a->staff_id) === $s->id)>{{ $s->name }}</option>@endforeach</select>
                    @if($branches->count() > 1)
                        <label class="form-label">Branch</label>
                        <select name="branch_id" class="form-select mb-3">@foreach($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id', \App\Support\Tenancy\CurrentTenant::membership()?->branch_id) == $b->id)>{{ $b->name }}</option>@endforeach</select>
                    @endif
                    @if($errors->has('starts_at') && str_contains($errors->first('starts_at'), 'already have'))
                        <label class="d-flex gap-2 small mb-3"><input type="checkbox" name="allow_clash" value="1"> Book anyway</label>
                    @endif
                    <button class="action-btn btn-primary-cb w-100 justify-content-center py-2"><i class="ri-calendar-check-line"></i>Book</button>
                </x-cb.card>
            </div>
        </div>
    </form>
</div>
</div>
</div>
@endsection
