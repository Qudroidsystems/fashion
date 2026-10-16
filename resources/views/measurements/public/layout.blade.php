@php $accent = $house?->brand_color ?: '#6d4aff'; @endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title', 'Measurements') · {{ $house?->name ?? 'Fashion house' }}</title>
    <link href="{{ asset('theme/layouts/assets/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('theme/layouts/assets/css/icons.min.css') }}" rel="stylesheet">
    <style>
        :root { --accent: {{ $accent }}; }
        body { background: #f5f5f8; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #1f2430; }
        .wrap { max-width: 640px; margin: 0 auto; padding: 16px 16px 48px; }
        .brand { display: flex; align-items: center; gap: 12px; padding: 12px 0 20px; }
        .brand-mark { width: 44px; height: 44px; border-radius: 12px; background: var(--accent); color: #fff; display: grid; place-items: center; font-weight: 700; overflow: hidden; }
        .brand-mark img { width: 100%; height: 100%; object-fit: cover; }
        .panel { background: #fff; border-radius: 18px; padding: 20px; box-shadow: 0 1px 3px rgba(20,20,40,.06); margin-bottom: 16px; }
        .btn-accent { background: var(--accent); border-color: var(--accent); color: #fff; }
        .btn-accent:hover { filter: brightness(.95); color: #fff; }
        .field { border-top: 1px solid #eef0f4; padding: 14px 0; }
        .field:first-child { border-top: 0; padding-top: 0; }
        .field label { font-weight: 600; }
        .how { font-size: .85rem; color: #6b7280; }
        .form-control-lg { font-size: 1.1rem; }
        .unit-switch a { text-decoration: none; }
        .foot { text-align: center; font-size: .8rem; color: #9aa0ad; margin-top: 24px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <div class="brand-mark">@if($house?->logo_url)<img src="{{ $house->logo_url }}" alt="">@else{{ $house?->initials ?? 'FH' }}@endif</div>
        <div>
            <div class="fw-bold">{{ $house?->name ?? 'Measurements' }}</div>
            @if($house?->phone)<div class="small text-muted">{{ $house->phone }}</div>@endif
        </div>
    </div>
    @yield('body')
    <div class="foot">@hasSection('foot')@yield('foot')@else Your measurements are only shared with {{ $house?->name ?? 'the fashion house' }}.@endif</div>
</div>
</body>
</html>
