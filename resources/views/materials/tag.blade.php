<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tag {{ $m->number }}</title>
    <style>
        @page { size: 90mm 60mm; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", Arial, sans-serif; color: #000; background: #f1f5f9; }
        .tag { width: 90mm; height: 60mm; margin: 16px auto; background: #fff; border: 1px dashed #94a3b8; border-radius: 4mm; padding: 4mm 4.5mm;
               display: grid; grid-template-columns: 1fr 26mm; gap: 3mm; position: relative; }
        .tag::before { content: ''; position: absolute; left: 4mm; top: 50%; width: 4mm; height: 4mm; margin-top: -2mm; border-radius: 50%; border: 1px solid #94a3b8; }
        .main { padding-left: 6mm; display: flex; flex-direction: column; min-width: 0; }
        .house { font-size: 7pt; text-transform: uppercase; letter-spacing: .08em; color: #475569; }
        .no { font-size: 15pt; font-weight: 800; letter-spacing: -.02em; margin: 1mm 0; font-variant-numeric: tabular-nums; }
        .name { font-size: 11pt; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .phone { font-size: 9pt; }
        .what { font-size: 9pt; margin-top: 1.5mm; line-height: 1.25; max-height: 3.8em; overflow: hidden; }
        .meta { margin-top: auto; font-size: 7.5pt; color: #334155; }
        .qr svg { width: 26mm; height: 26mm; display: block; }
        .qr small { display: block; text-align: center; font-size: 6pt; color: #64748b; margin-top: 1mm; }
        .bar { text-align: center; margin: 10px; }
        .bar button { padding: 8px 18px; border: 0; border-radius: 8px; background: #0f766e; color: #fff; font-size: 14px; cursor: pointer; }
        @media print { body { background: #fff; } .tag { margin: 0; border: 0; border-radius: 0; } .bar { display: none; } }
    </style>
</head>
<body>
<div class="tag">
    <div class="main">
        <div class="house">{{ $house->name }}</div>
        <div class="no">{{ $m->number }}</div>
        <div class="name">{{ $m->customer->name }}</div>
        <div class="phone">{{ $m->customer->phone }}</div>
        <div class="what">{{ $m->summary }}</div>
        <div class="meta">
            In {{ $m->received_at->timezone($tz)->format('j M Y') }}@if($m->order) · {{ $m->order->number }}@endif
            @if($m->location)<br>{{ $m->location }}@endif
        </div>
    </div>
    <div class="qr">{!! $qr !!}<small>Scan to open</small></div>
</div>
<div class="bar"><button onclick="window.print()">Print tag</button></div>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
