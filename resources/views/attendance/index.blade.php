@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
@php
    $fmt = fn ($t) => $t ? $t->format('g:i A') : '—';
    $dur = fn ($m) => $m ? intdiv($m, 60) . 'h ' . str_pad($m % 60, 2, '0', STR_PAD_LEFT) . 'm' : '—';
    $in = $rows->where('status', 'in')->count();
    $out = $rows->where('status', 'out')->count();
    $absent = $rows->where('status', 'absent')->count();
    $late = $rows->whereNotNull('late_minutes')->count();
    $canManage = auth()->user()->can('Manage attendance');
@endphp

    <x-cb.hero title="Attendance" icon="ri-qr-scan-2-line"
               subtitle="Staff scan the code at the entrance with the staff app when they come in and when they leave.">
        @if($canManage)
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('attendance.kiosk', array_filter(['branch_id' => $branchId])) }}" target="_blank"><i class="ri-tv-2-line me-1"></i>Open entrance screen</a>
            @if($settings['mode'] !== 'rotating')
                <a class="cb-hero-btn" href="{{ route('attendance.print', array_filter(['branch_id' => $branchId])) }}" target="_blank"><i class="ri-printer-line me-1"></i>Print code</a>
            @endif
        </x-slot:actions>
        @endif
    </x-cb.hero>
    @include('inventory.partials.flash')

    <form method="get" class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <a class="btn btn-light btn-sm" href="{{ route('attendance.index', array_filter(['date' => $date->subDay()->toDateString(), 'branch_id' => $branchId])) }}"><i class="ri-arrow-left-s-line"></i></a>
        <input type="date" name="date" value="{{ $date->toDateString() }}" max="{{ now($tz)->toDateString() }}" class="form-control form-control-sm" style="max-width:170px" onchange="this.form.submit()">
        @unless($isToday)
            <a class="btn btn-light btn-sm" href="{{ route('attendance.index', array_filter(['date' => $date->addDay()->toDateString(), 'branch_id' => $branchId])) }}"><i class="ri-arrow-right-s-line"></i></a>
            <a class="btn btn-link btn-sm" href="{{ route('attendance.index', array_filter(['branch_id' => $branchId])) }}">Today</a>
        @endunless
        @if($branches->count() > 1)
            <select name="branch_id" class="form-select form-select-sm" style="max-width:200px" onchange="this.form.submit()">
                <option value="">All branches</option>
                @foreach($branches as $b)<option value="{{ $b->id }}" @selected($branchId == $b->id)>{{ $b->name }}</option>@endforeach
            </select>
        @endif
        <span class="ms-auto small text-muted">{{ $date->format('l, j F Y') }}</span>
    </form>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><x-cb.stat :label="$isToday ? 'In now' : 'Came in'" :value="$isToday ? $in : $in + $out" icon="ri-login-box-line" accent="teal" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Left" :value="$out" icon="ri-logout-box-r-line" accent="violet" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat :label="$isToday ? 'Not in yet' : 'Didn’t come'" :value="$absent" icon="ri-user-unfollow-line" accent="amber" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Late" :value="$late" icon="ri-alarm-warning-line" accent="rose" :hint="'After ' . \Carbon\Carbon::createFromFormat('H:i', $settings['start_time'])->format('g:i A') . ' + ' . $settings['grace_minutes'] . ' min'" /></div>
    </div>

    <x-cb.card title="Staff" icon="ri-team-line" :count="$rows->count()" :flush="true">
        @if($rows->isEmpty())
            <div class="p-4 text-center text-muted">No active staff to show.</div>
        @else
        <div class="table-responsive"><table class="cb-table stack mb-0">
            <thead><tr><th>Person</th><th>Status</th><th>First in</th><th>Last out</th><th class="text-end">Time in</th><th>Scans</th></tr></thead>
            <tbody>
            @foreach($rows as $r)
                <tr>
                    <td data-label="Person">
                        <div class="d-flex align-items-center gap-2">
                            <img src="{{ $r['avatar_url'] }}" alt="" class="rounded-circle" width="32" height="32">
                            <div><div class="fw-semibold">{{ $r['name'] }}</div>@if($r['job_title'])<div class="small text-muted">{{ $r['job_title'] }}</div>@endif</div>
                        </div>
                    </td>
                    <td data-label="Status">
                        @if($r['status'] === 'in')<span class="status-pill st-success">{{ $isToday ? 'In' : 'Didn’t scan out' }}</span>
                        @elseif($r['status'] === 'out')<span class="status-pill st-muted">Left</span>
                        @else<span class="status-pill st-warning">{{ $isToday ? 'Not in yet' : 'Absent' }}</span>@endif
                        @if($r['late_minutes'])<span class="status-pill st-danger ms-1">{{ $r['late_minutes'] }} min late</span>@endif
                    </td>
                    <td data-label="First in">{{ $fmt($r['first_in']) }}</td>
                    <td data-label="Last out">{{ $fmt($r['last_out']) }}</td>
                    <td data-label="Time in" class="text-end">{{ $dur($r['worked_minutes']) }}</td>
                    <td data-label="Scans" class="small">
                        @forelse($r['events'] as $e)
                            <span class="me-2 text-nowrap" title="{{ \App\Models\AttendanceEvent::METHODS[$e['method']] ?? '' }}{{ $e['note'] ? ' — ' . $e['note'] : '' }}{{ $e['branch'] ? ' · ' . $e['branch'] : '' }}">
                                <i class="{{ $e['direction'] === 'in' ? 'ri-arrow-right-down-line text-success' : 'ri-arrow-left-up-line text-muted' }}"></i>{{ $e['at']->format('g:i A') }}@if($e['method'] === 'manual')<sup class="text-warning">*</sup>@endif
                            </span>
                        @empty<span class="text-muted">—</span>@endforelse
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        <div class="px-3 py-2 small text-muted border-top"><sup class="text-warning">*</sup> added by a manager. Hover a time to see the note.</div>
        @endif
    </x-cb.card>

    @if($canManage)
    <div class="row g-3 mt-1">
        <div class="col-lg-6">
            <x-cb.card title="Add a forgotten scan" icon="ri-edit-box-line">
                <form method="post" action="{{ route('attendance.store') }}" class="row g-2">
                    @csrf
                    <div class="col-12">
                        <select name="user_id" class="form-select" required>
                            <option value="">Who?</option>
                            @foreach($staff as $s)<option value="{{ $s['id'] }}" @selected(old('user_id') == $s['id'])>{{ $s['name'] }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-4"><select name="direction" class="form-select"><option value="in">Came in</option><option value="out" @selected(old('direction') === 'out')>Left</option></select></div>
                    <div class="col-4"><input type="date" name="date" class="form-control" value="{{ old('date', $date->toDateString()) }}" max="{{ now($tz)->toDateString() }}" required></div>
                    <div class="col-4"><input type="time" name="time" class="form-control" value="{{ old('time') }}" required></div>
                    <div class="col-12"><input type="text" name="note" class="form-control" maxlength="255" placeholder="Why? e.g. phone was flat" value="{{ old('note') }}" required></div>
                    @if($errors->any())<div class="col-12 small text-danger">{{ $errors->first() }}</div>@endif
                    <div class="col-12"><button class="btn btn-primary">Add</button> <span class="small text-muted ms-2">Scans can’t be changed or deleted; corrections are kept with your name.</span></div>
                </form>
            </x-cb.card>
        </div>
        <div class="col-lg-6">
            <x-cb.card title="Settings" icon="ri-settings-3-line">
                <form method="post" action="{{ route('attendance.settings') }}" class="row g-2">
                    @csrf @method('put')
                    <div class="col-6"><label class="form-label small">Work starts</label><input type="time" name="start_time" class="form-control" value="{{ $settings['start_time'] }}" required></div>
                    <div class="col-6"><label class="form-label small">Late after (minutes)</label><input type="number" name="grace_minutes" min="0" max="240" class="form-control" value="{{ $settings['grace_minutes'] }}" required></div>
                    <div class="col-12"><label class="form-label small">Codes that work</label>
                        <select name="mode" class="form-select">
                            @foreach(\App\Services\Attendance\AttendanceService::MODES as $k => $label)<option value="{{ $k }}" @selected($settings['mode'] === $k)>{{ $label }}</option>@endforeach
                        </select>
                        <div class="form-text">The entrance screen changes its code every 30 seconds, so a photo of it can’t be used from home. A printed code is easier but can be photographed.</div>
                    </div>
                    <div class="col-12 d-flex gap-2 flex-wrap"><button class="btn btn-primary">Save</button></div>
                </form>
                @if($settings['mode'] !== 'rotating')
                <form method="post" action="{{ route('attendance.printed-code') }}" class="mt-3" onsubmit="return confirm('Make a new printed code? The old printed code stops working straight away.')">
                    @csrf
                    <button class="btn btn-light btn-sm"><i class="ri-refresh-line me-1"></i>Replace the printed code</button>
                    <span class="small text-muted ms-1">Do this if the printed code may have been copied.</span>
                </form>
                @endif
            </x-cb.card>
        </div>
    </div>
    @endif

</div>
</div>
</div>
@endsection
