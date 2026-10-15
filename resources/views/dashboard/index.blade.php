@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @php
        $tz = $tenant->timezone ?: config('app.timezone');
        $hour = now($tz)->hour;
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        $doneCount = collect($checklist)->where('done', true)->count();
    @endphp

    <x-cb.hero :title="$greeting . ', ' . auth()->user()->first_name" icon="ri-store-2-line"
               :subtitle="'Here is what is happening at ' . $tenant->name . ' today.'">
        <x-slot:pills>
            <span class="cb-meta-pill"><i class="ri-calendar-line me-1"></i>{{ now($tz)->format('l, j F Y') }}</span>
            <span class="cb-meta-pill"><i class="ri-map-pin-time-line me-1"></i>{{ $tz }}</span>
            <span class="cb-meta-pill"><i class="ri-money-dollar-circle-line me-1"></i>{{ $tenant->currency_code }}</span>
            @if($tenant->status === 'trial' && $tenant->trial_ends_at)
                <span class="cb-meta-pill"><i class="ri-hourglass-line me-1"></i>Trial ends {{ $tenant->trial_ends_at->timezone($tz)->format('j M Y') }}</span>
            @endif
        </x-slot:pills>
        @can('Manage tenant settings')
        <x-slot:actions>
            <a href="{{ route('settings.tenant.edit') }}" class="cb-hero-btn"><i class="ri-settings-3-line me-1"></i>House settings</a>
        </x-slot:actions>
        @endcan
    </x-cb.hero>

    @if($tenant->status === 'past_due')
        <div class="cb-banner warning"><i class="ri-error-warning-line"></i><div><strong>Payment overdue.</strong> Your subscription payment is overdue. Please settle it to avoid interruption.</div></div>
    @endif

    {{-- Stats --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3"><x-cb.stat label="Active staff" :value="$stats['staff']" icon="ri-team-line" accent="teal" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Branches & locations" :value="$stats['branches']" icon="ri-building-4-line" accent="sky" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Roles" :value="$stats['roles']" icon="ri-shield-user-line" accent="violet" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Invitations pending" :value="$stats['invited']" icon="ri-mail-send-line" accent="amber" /></div>
    </div>

    <div class="row g-3">
        {{-- Getting started --}}
        <div class="col-xl-5">
            <x-cb.card title="Getting started" icon="ri-rocket-2-line" :count="$doneCount . '/' . count($checklist)">
                <div class="progress-track mb-3"><div class="progress-fill" style="width: {{ round($doneCount / max(count($checklist), 1) * 100) }}%"></div></div>
                <ul class="list-unstyled mb-0">
                    @foreach($checklist as $item)
                        <li class="d-flex align-items-center gap-2 py-2 border-bottom">
                            <i class="{{ $item['done'] ? 'ri-checkbox-circle-fill text-success' : 'ri-checkbox-blank-circle-line text-muted' }} fs-5"></i>
                            <span class="flex-grow-1 {{ $item['done'] ? 'text-muted text-decoration-line-through' : '' }}">{{ $item['label'] }}</span>
                            @can($item['can'])
                                @if(Route::has($item['route']))
                                    <a href="{{ route($item['route']) }}" class="action-btn btn-primary-cb">Open</a>
                                @endif
                            @endcan
                        </li>
                    @endforeach
                </ul>
            </x-cb.card>

            @if($recent->count())
            <x-cb.card title="Recent activity" icon="ri-history-line" class="mt-3" :flush="true">
                <x-slot:tools>
                    <a href="{{ route('audit-logs.index') }}" class="action-btn btn-primary-cb">View all</a>
                </x-slot:tools>
                <ul class="list-unstyled mb-0">
                    @foreach($recent as $log)
                        <li class="px-4 py-2 border-bottom small">
                            <div class="fw-semibold">{{ \Illuminate\Support\Str::headline(str_replace('.', ' ', $log->action)) }}</div>
                            <div class="text-muted">{{ $log->user?->name ?? 'System' }} · {{ $log->created_at->timezone($tz)->diffForHumans() }}</div>
                        </li>
                    @endforeach
                </ul>
            </x-cb.card>
            @endif
        </div>

        {{-- Module roadmap --}}
        <div class="col-xl-7">
            <x-cb.card title="Modules" icon="ri-apps-2-line">
                <x-slot:tools><span class="small text-muted">Built in phases — see the SRS roadmap</span></x-slot:tools>
                <div class="row g-3">
                    @foreach($modules as $m)
                        <div class="col-md-6">
                            <div class="d-flex gap-3 p-3 rounded-3 h-100" style="border:1px dashed var(--cb-border);">
                                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3"
                                     style="width:44px;height:44px;background:var(--cb-surface-2);color:var(--cb-teal);font-size:22px;">
                                    <i class="{{ $m['icon'] }}"></i>
                                </div>
                                <div>
                                    <div class="fw-semibold" style="color:var(--cb-heading)">{{ $m['name'] }}</div>
                                    <div class="small text-muted">{{ $m['desc'] }}</div>
                                    <span class="status-pill st-muted mt-1">Phase {{ $m['phase'] }} · coming soon</span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>
@endsection
