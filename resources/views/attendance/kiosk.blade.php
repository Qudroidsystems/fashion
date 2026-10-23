<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Entrance — {{ $tenant->name }}</title>
    <style>
        :root { --bg: #0b1220; --fg: #f8fafc; --muted: #94a3b8; --teal: #14b8a6; }
        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; background: var(--bg); color: var(--fg); font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "Inter", "Segoe UI", sans-serif; }
        .wrap { min-height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 22px; padding: 24px; text-align: center; }
        h1 { margin: 0; font-size: clamp(22px, 3.2vw, 38px); font-weight: 700; letter-spacing: -.02em; }
        .sub { color: var(--muted); font-size: clamp(14px, 1.6vw, 19px); margin: 0; }
        .qr { background: #fff; border-radius: 28px; padding: 22px; width: min(72vh, 84vw); aspect-ratio: 1; display: grid; place-items: center; position: relative;
              box-shadow: 0 30px 80px -30px rgba(20, 184, 166, .55); transition: opacity .35s ease, transform .35s ease; }
        .qr svg { width: 100%; height: 100%; display: block; }
        .qr.swap { opacity: .25; transform: scale(.985); }
        .bar { width: min(72vh, 84vw); height: 6px; border-radius: 3px; background: rgba(148, 163, 184, .2); overflow: hidden; }
        .bar i { display: block; height: 100%; background: var(--teal); width: 100%; transform-origin: left; }
        .clock { font-size: clamp(30px, 5vw, 64px); font-weight: 700; font-variant-numeric: tabular-nums; letter-spacing: -.03em; }
        .row { display: flex; gap: 14px; align-items: center; justify-content: center; flex-wrap: wrap; }
        .note { color: var(--muted); font-size: 13px; }
        .off { color: #fbbf24; font-size: 18px; max-width: 520px; }
        select { background: transparent; color: var(--muted); border: 1px solid rgba(148, 163, 184, .3); border-radius: 8px; padding: 4px 8px; }
        .err { color: #fca5a5; font-size: 14px; min-height: 18px; }
    </style>
</head>
<body>
<div class="wrap">
    <div>
        <h1>{{ $tenant->name }}</h1>
        <p class="sub">Coming in or leaving? Open the staff app, tap <strong>Scan attendance</strong> and point it here.</p>
    </div>

    @if($mode === 'static')
        <p class="off">The entrance screen is switched off — this house uses the printed code. A manager can change this in Attendance settings.</p>
    @else
        <div class="qr" id="qr" aria-label="Attendance code"></div>
        <div class="bar"><i id="bar"></i></div>
    @endif

    <div class="row">
        <div class="clock" id="clock">{{ now(\App\Support\Tenancy\CurrentTenant::get()->timezone ?: config('app.timezone'))->format('g:i A') }}</div>
    </div>
    <div class="row note">
        <span>{{ $branch->name }}</span>
        @if($branches->count() > 1)
            <select onchange="location.search='?branch_id='+this.value">
                @foreach($branches as $b)<option value="{{ $b->id }}" @selected($b->id === $branch->id)>{{ $b->name }}</option>@endforeach
            </select>
        @endif
        <span>· The code changes every 30 seconds</span>
    </div>
    <div class="err" id="err"></div>
</div>

@if($mode !== 'static')
<script>
(function () {
    const url = @json(route('attendance.kiosk.code', ['branch_id' => $branch->id]));
    const qr = document.getElementById('qr'), bar = document.getElementById('bar'), clock = document.getElementById('clock'), err = document.getElementById('err');
    let timer, last = '';
    async function load() {
        clearTimeout(timer);
        try {
            const r = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
            if (r.status === 401 || r.status === 419) { location.reload(); return; }
            const d = await r.json();
            if (d.off) { location.reload(); return; }
            if (d.svg !== last) {
                qr.classList.add('swap');
                setTimeout(() => { qr.innerHTML = d.svg; qr.classList.remove('swap'); }, last ? 180 : 0);
                last = d.svg;
            }
            clock.textContent = d.time;
            err.textContent = '';
            const secs = Math.max(1, d.expires_in);
            bar.style.transition = 'none'; bar.style.transform = 'scaleX(' + (secs / 30) + ')';
            requestAnimationFrame(() => requestAnimationFrame(() => { bar.style.transition = 'transform ' + secs + 's linear'; bar.style.transform = 'scaleX(0)'; }));
            timer = setTimeout(load, secs * 1000 + 150);
        } catch (e) {
            err.textContent = 'Can’t reach the server — retrying…';
            timer = setTimeout(load, 5000);
        }
    }
    load();
    // Keep the screen awake where the browser allows it.
    if ('wakeLock' in navigator) { const lock = () => navigator.wakeLock.request('screen').catch(() => {}); lock(); document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && lock()); }
})();
</script>
@endif
</body>
</html>
