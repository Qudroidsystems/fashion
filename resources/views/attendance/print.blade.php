<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Attendance code — {{ $tenant->name }}</title>
    <style>
        @page { size: A4; margin: 18mm; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", sans-serif; color: #0f172a; background: #fff; }
        .sheet { max-width: 680px; margin: 24px auto; text-align: center; }
        h1 { font-size: 30px; margin: 0 0 6px; letter-spacing: -.02em; }
        p { color: #475569; margin: 0 0 22px; font-size: 16px; }
        .qr { width: 460px; max-width: 90vw; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 22px; padding: 18px; }
        .qr svg { width: 100%; height: auto; display: block; }
        .foot { margin-top: 20px; font-size: 13px; color: #64748b; }
        .btn { margin-top: 20px; padding: 10px 18px; border-radius: 10px; border: 0; background: #0f766e; color: #fff; font-size: 15px; cursor: pointer; }
        @media print { .btn { display: none; } .sheet { margin: 0 auto; } }
    </style>
</head>
<body>
<div class="sheet">
    <h1>{{ $tenant->name }} — Attendance</h1>
    <p>Scan with the staff app (<strong>Scan attendance</strong>) when you come in and when you leave.</p>
    <div class="qr">{!! $svg !!}</div>
    <div class="foot">{{ $branch->name }} · Printed code no. {{ $settings['static_version'] }} · {{ now()->format('j M Y') }}</div>
    @if($settings['mode'] === 'rotating')
        <div class="foot" style="color:#b45309">Printed codes are switched off in Attendance settings, so this code won’t work until they are switched on.</div>
    @endif
    <button class="btn" onclick="window.print()">Print</button>
</div>
</body>
</html>
