@extends('measurements.public.layout')

@section('title', $view['name'])
@section('foot', 'This private page is only for you. Please don’t share the link widely.')

@section('body')
    @php $g = $view['garments']; $pct = $g['total'] ? (int) floor(100 * $g['ready'] / $g['total']) : 0; $today = now($tz)->startOfDay();
        $days = $view['event_date'] ? (int) $today->diffInDays($view['event_date'], false) : null; @endphp
    <div class="panel">
        <div class="small text-muted text-uppercase fw-semibold">{{ $view['type'] }}</div>
        <h1 class="h4 fw-bold mb-1">{{ $view['name'] }}</h1>
        @if($view['event_date'])<div class="text-muted"><i class="ri-calendar-event-line"></i> {{ $view['event_date']->format('l j F Y') }}{{ $view['venue'] ? ' · ' . $view['venue'] : '' }}
            @if($days !== null && $days > 0 && $view['status'] !== 'completed')<span class="fw-semibold" style="color:var(--accent)"> · {{ $days }} days to go</span>@endif</div>@endif
        @if($view['message'])<p class="mt-3 mb-0">{!! nl2br(e($view['message'])) !!}</p>@endif
    </div>

    <div class="panel">
        <div class="d-flex justify-content-between align-items-end mb-2"><span class="fw-semibold">Garments</span><span class="small text-muted">{{ $g['ready'] }} of {{ $g['total'] }} ready</span></div>
        <div class="progress" style="height:10px"><div class="progress-bar" style="width:{{ $pct }}%;background:var(--accent)"></div></div>
        <div class="d-flex justify-content-between small text-muted mt-2"><span>{{ $g['started'] }} being made</span><span>{{ $g['delivered'] }} handed over</span></div>
        @if($view['status'] === 'completed')<div class="mt-2 fw-semibold" style="color:var(--accent)"><i class="ri-checkbox-circle-line"></i> Everything has been handed over. Thank you!</div>@endif
    </div>

    @if($view['milestones']->isNotEmpty())
        <div class="panel">
            <div class="fw-semibold mb-2">Milestones</div>
            @foreach($view['milestones'] as $ms)
                <div class="d-flex gap-2 py-2 {{ $loop->first ? '' : 'border-top' }}">
                    <i class="{{ $ms->status === 'done' ? 'ri-checkbox-circle-fill' : 'ri-checkbox-blank-circle-line' }} fs-5" style="color:{{ $ms->status === 'done' ? 'var(--accent)' : '#b6bcc8' }}"></i>
                    <div><div class="{{ $ms->status === 'done' ? 'fw-semibold' : '' }}">{{ $ms->name }}</div>
                        <div class="small text-muted">{{ $ms->status === 'done' ? 'Done ' . $ms->completed_at?->timezone($tz)->format('j M') : ($ms->due_date ? 'Planned for ' . $ms->due_date->format('j M') : '') }}</div>
                        @if($ms->notes)<div class="small">{{ $ms->notes }}</div>@endif</div>
                </div>
            @endforeach
        </div>
    @endif

    @if($view['updates']->isNotEmpty())
        <div class="panel">
            <div class="fw-semibold mb-2">Updates</div>
            @foreach($view['updates'] as $u)
                <div class="py-2 {{ $loop->first ? '' : 'border-top' }}">
                    <div class="d-flex justify-content-between"><span class="fw-semibold">{{ $u->title ?: 'Update' }}</span><span class="small text-muted">{{ $u->published_at?->timezone($tz)->format('j M') }}</span></div>
                    <p class="mb-1">{!! nl2br(e($u->body)) !!}</p>
                    @if($u->images)<div class="d-flex gap-2 flex-wrap">@foreach($u->imageUrls() as $src)<a href="{{ $src }}" target="_blank" rel="noopener"><img src="{{ $src }}" alt="" style="width:96px;height:96px;object-fit:cover;border-radius:10px"></a>@endforeach</div>@endif
                </div>
            @endforeach
        </div>
    @endif
@endsection
