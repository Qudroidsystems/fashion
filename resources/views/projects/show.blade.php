@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    @php
        use App\Support\Money;
        $s = $summary; $g = $s['garments']; $m = $s['money'];
        $today = now($tz)->startOfDay();
        $days = $project->event_date ? (int) $today->diffInDays($project->event_date, false) : null;
        $open = $project->isOpen();
    @endphp
    <x-cb.hero :title="$project->name" icon="ri-heart-2-line" :subtitle="$project->type_label . ' for ' . $project->customer->name . ' · ' . $project->number"
               :back="route('projects.index')" back-label="Projects">
        <x-slot:pills>
            <span class="status-pill {{ $project->status_pill }}">{{ $project->status_label }}</span>
            @if($project->event_date)<span class="cb-meta-pill"><i class="ri-calendar-event-line me-1"></i>{{ $project->event_date->format('D d M Y') }}{{ $open && $days !== null ? ($days > 0 ? ' · in ' . $days . ' days' : ($days === 0 ? ' · today' : '')) : '' }}</span>@endif
            @if($project->venue)<span class="cb-meta-pill"><i class="ri-map-pin-line me-1"></i>{{ $project->venue }}</span>@endif
            @if($project->manager)<span class="cb-meta-pill">Manager {{ $project->manager->name }}</span>@endif
        </x-slot:pills>
        <x-slot:actions>
            @if($canManage)
                @if($open)<a href="{{ route('projects.edit', $project) }}" class="cb-hero-btn"><i class="ri-edit-line me-1"></i>Edit</a>@endif
                @if($project->status === 'planning')
                    <form method="POST" action="{{ route('projects.confirm', $project) }}" onsubmit="return confirm('Confirm the project? Its orders are confirmed and can go into production.')">@csrf
                        <button class="cb-hero-btn"><i class="ri-check-double-line me-1"></i>Confirm project</button></form>
                @elseif($project->status === 'active' && $g['total'] && $g['delivered'] === $g['total'])
                    <form method="POST" action="{{ route('projects.complete', $project) }}">@csrf<button class="cb-hero-btn"><i class="ri-flag-line me-1"></i>Mark completed</button></form>
                @endif
            @endif
        </x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.flash')
    @if($project->status === 'cancelled')<div class="cb-banner danger"><i class="ri-close-circle-line"></i><div>Cancelled {{ $project->cancelled_at?->timezone($tz)->format('d M Y') }}: {{ $project->cancel_reason }}</div></div>@endif
    @if($open && $s['late_milestones'])<div class="cb-banner warning"><i class="ri-alarm-warning-line"></i><div>{{ $s['late_milestones'] }} milestone(s) are past their date.</div></div>@endif

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><x-cb.stat label="Garments ready" :value="$g['ready'] . ' / ' . $g['total']" icon="ri-t-shirt-air-line" accent="teal" :hint="$g['readiness'] . '% · ' . $g['delivered'] . ' handed over'" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="People" :value="$project->participants->where('status', 'active')->count()" icon="ri-group-line" accent="violet" :hint="$g['bespoke'] ? $g['measured'] . ' of ' . $g['bespoke'] . ' made-to-measure linked to measurements' : null" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Next milestone" :value="\Illuminate\Support\Str::limit($s['next_milestone']?->name ?? '—', 22)" icon="ri-flag-2-line" accent="amber" :hint="$s['next_milestone']?->due_date?->format('d M')" /></div>
        @if($canMoney)
            <div class="col-6 col-lg-3"><x-cb.stat label="Still owed" :value="Money::format($m['outstanding'])" icon="ri-wallet-3-line" accent="rose" :hint="Money::format($m['collected']) . ' collected'" /></div>
        @endif
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            @include('projects.partials.participants')
            @include('projects.partials.milestones')
            @include('projects.partials.updates')
        </div>
        <div class="col-xl-4">
            <x-cb.card title="Client" icon="ri-user-heart-line">
                <a href="{{ route('customers.show', $project->customer) }}" class="fw-semibold">{{ $project->customer->name }}</a>
                <div class="small text-muted">{{ $project->customer->customer_number }}{{ $project->customer->phone ? ' · ' . $project->customer->phone : '' }}</div>
                @if($project->delivery_date)<div class="small mt-2">Hand over by <strong>{{ $project->delivery_date->format('D d M Y') }}</strong></div>@endif
                @if($project->description)<p class="small mt-2 mb-0">{!! nl2br(e($project->description)) !!}</p>@endif
                @if($project->internal_notes)<div class="small text-muted mt-2 p-2 rounded" style="background:var(--cb-surface-2)"><i class="ri-lock-line me-1"></i>{!! nl2br(e($project->internal_notes)) !!}</div>@endif
            </x-cb.card>
            @if($canMoney)@include('projects.partials.money')@endif
            @if($canCost)@include('projects.partials.costs')@endif
            @include('projects.partials.tracking')
            @if($canManage && $open && auth()->user()->can('Cancel order'))
                <x-cb.card title="Cancel project" icon="ri-close-circle-line" class="mt-3">
                    <form method="POST" action="{{ route('projects.cancel', $project) }}" onsubmit="return confirm('Cancel the whole project and its open orders?')">@csrf
                        <input name="reason" class="form-control form-control-sm mb-2" required maxlength="255" placeholder="Why?">
                        <button class="action-btn w-100 justify-content-center text-danger"><i class="ri-close-circle-line"></i>Cancel project</button>
                        <p class="small text-muted mt-2 mb-0">Open orders are cancelled and their reservations released. Payments stay on record for refunds.</p>
                    </form>
                </x-cb.card>
            @endif
        </div>
    </div>
</div>
</div>
</div>
@endsection
