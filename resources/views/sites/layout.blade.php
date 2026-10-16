@php
    $accent = $house->brand_color ?: '#7A2E4D';
    $name = $house->name;
    $wa = preg_replace('/\D+/', '', (string) ($site['whatsapp'] ?? ''));
    if ($wa && str_starts_with($wa, '0')) { $wa = '234' . substr($wa, 1); }
    $nav = ['' => 'Home', '/collection' => 'Collection', '/shop' => 'Ready to wear', '/contact' => 'Contact'];
    $here = '/' . trim(\Illuminate\Support\Str::after(request()->path(), ltrim($base, '/')), '/');
    $here = $here === '/' ? '' : $here;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $site['tagline'] ?? 'Bespoke fashion') · {{ $name }}</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit($site['about'] ?? ($site['tagline'] ?? $name), 155) }}">
    <meta name="theme-color" content="{{ $accent }}">
    @if($house->logo_url)<link rel="icon" href="{{ $house->logo_url }}">@endif
    <meta property="og:title" content="{{ $name }}"><meta property="og:type" content="website">
    @if(! empty($site['hero_path']))<meta property="og:image" content="{{ asset('storage/' . $site['hero_path']) }}">@endif
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --accent: {{ $accent }}; --ink: #17151C; --muted: #6B6874; --line: #ECE9EE; --soft: #F8F6F8; --serif: "Cormorant Garamond", Georgia, serif; --sans: "Manrope", "Segoe UI", system-ui, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: var(--sans); color: var(--ink); background: #fff; font-size: 16px; line-height: 1.65; -webkit-font-smoothing: antialiased; }
        a { color: inherit; } img { max-width: 100%; display: block; }
        a:focus-visible, button:focus-visible, input:focus-visible, textarea:focus-visible, select:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
        .wrap { width: min(1160px, 100% - 40px); margin-inline: auto; }
        h1, h2, h3 { font-family: var(--serif); font-weight: 600; line-height: 1.05; margin: 0; letter-spacing: -0.01em; }
        h1 { font-size: clamp(2.8rem, 6.4vw, 5.2rem); }
        h2 { font-size: clamp(2rem, 3.6vw, 3rem); }
        h3 { font-size: 1.45rem; }
        p { margin: 0; }
        .muted { color: var(--muted); }
        header.top { position: sticky; top: 0; z-index: 10; background: rgba(255,255,255,.92); backdrop-filter: blur(8px); border-bottom: 1px solid var(--line); }
        header.top .wrap { display: flex; align-items: center; justify-content: space-between; gap: 1rem; min-height: 72px; }
        .mark { display: flex; align-items: center; gap: .7rem; text-decoration: none; }
        .mark img { width: 42px; height: 42px; border-radius: 10px; object-fit: cover; }
        .mark .init { width: 42px; height: 42px; border-radius: 10px; background: var(--accent); color: #fff; display: grid; place-items: center; font-weight: 700; }
        .mark span { font-family: var(--serif); font-size: 1.55rem; font-weight: 600; }
        nav.main { display: flex; gap: 1.6rem; align-items: center; }
        nav.main a { text-decoration: none; font-weight: 500; font-size: .95rem; color: var(--muted); }
        nav.main a[aria-current="page"], nav.main a:hover { color: var(--ink); }
        nav.main a.btn, nav.main a.btn:hover { color: #fff; }
        .grid.small { grid-template-columns: repeat(4, 1fr); }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: .5rem; padding: .85rem 1.4rem; border-radius: 999px; font-weight: 600; text-decoration: none; border: 1.5px solid var(--accent); background: var(--accent); color: #fff; cursor: pointer; font: inherit; font-weight: 600; }
        .btn.ghost { background: transparent; color: var(--accent); }
        .btn:hover { filter: brightness(.94); }
        .menu-toggle { display: none; background: none; border: 0; font-size: 1.5rem; }
        main section { padding: 84px 0; }
        .eyebrowless { margin-bottom: .8rem; }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 28px; }
        .card { text-decoration: none; display: block; }
        .card .pic { aspect-ratio: 3 / 4; border-radius: 16px; overflow: hidden; background: var(--soft); }
        .card .pic img { width: 100%; height: 100%; object-fit: cover; transition: transform .5s ease; }
        .card:hover .pic img { transform: scale(1.03); }
        .card h3 { margin-top: 1rem; }
        .card .price { color: var(--muted); font-size: .95rem; margin-top: .2rem; }
        .ph { width: 100%; height: 100%; display: grid; place-items: center; background: color-mix(in srgb, var(--accent) 8%, #fff); }
        .ph svg { width: 46%; height: auto; }
        footer.foot { border-top: 1px solid var(--line); padding: 48px 0 36px; margin-top: 40px; font-size: .95rem; }
        footer.foot .wrap { display: grid; grid-template-columns: 1.4fr 1fr 1fr; gap: 2rem; }
        footer.foot h4 { font-family: var(--sans); font-size: .95rem; margin: 0 0 .6rem; }
        footer.foot .small { margin-top: 2rem; grid-column: 1 / -1; font-size: .82rem; color: var(--muted); display: flex; justify-content: space-between; flex-wrap: wrap; gap: .5rem; }
        .wa { position: fixed; right: 18px; bottom: 18px; z-index: 20; width: 56px; height: 56px; border-radius: 50%; background: #25D366; display: grid; place-items: center; box-shadow: 0 10px 25px rgba(0,0,0,.2); }
        .wa svg { width: 28px; height: 28px; }
        label { display: block; font-weight: 600; font-size: .92rem; margin-bottom: .35rem; }
        input, textarea, select { width: 100%; padding: .8rem .95rem; border: 1.5px solid var(--line); border-radius: 12px; font: inherit; background: #fff; }
        textarea { min-height: 130px; }
        .field { margin-bottom: 1.1rem; }
        .notice { padding: 1rem 1.2rem; border-radius: 12px; background: color-mix(in srgb, var(--accent) 10%, #fff); border: 1px solid color-mix(in srgb, var(--accent) 30%, #fff); }
        .err { color: #B42318; font-size: .9rem; margin-top: .3rem; }
        @media (max-width: 900px) {
            .grid, .grid.small { grid-template-columns: repeat(2, 1fr); gap: 18px; }
            footer.foot .wrap { grid-template-columns: 1fr; }
            nav.main { display: none; position: absolute; top: 72px; left: 0; right: 0; background: #fff; flex-direction: column; align-items: flex-start; padding: 16px 20px 22px; border-bottom: 1px solid var(--line); }
            nav.main.open { display: flex; }
            .menu-toggle { display: block; }
            main section { padding: 56px 0; }
        }
        @media (max-width: 520px) { .grid, .grid.small { grid-template-columns: 1fr 1fr; gap: 12px; } .card h3 { font-size: 1.15rem; } }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
        @stack('css')
    </style>
</head>
<body>
<header class="top">
    <div class="wrap">
        <a href="{{ $base ?: '/' }}" class="mark">
            @if($house->logo_url)<img src="{{ $house->logo_url }}" alt="">@else<span class="init">{{ $house->initials }}</span>@endif
            <span>{{ $name }}</span>
        </a>
        <button class="menu-toggle" aria-label="Menu" onclick="document.getElementById('mainNav').classList.toggle('open')">☰</button>
        <nav class="main" id="mainNav" aria-label="Main">
            @foreach($nav as $path => $label)<a href="{{ $base . $path ?: '/' }}" @if($here === $path) aria-current="page" @endif>{{ $label }}</a>@endforeach
            <a href="{{ $base }}/contact{{ $full ? '?type=booking' : '' }}" class="btn" style="padding:.6rem 1.1rem">{{ $full ? 'Book a fitting' : 'Make an enquiry' }}</a>
        </nav>
    </div>
</header>

<main>@yield('body')</main>

<footer class="foot">
    <div class="wrap">
        <div>
            <div class="mark" style="margin-bottom:.8rem">@if($house->logo_url)<img src="{{ $house->logo_url }}" alt="">@else<span class="init">{{ $house->initials }}</span>@endif<span>{{ $name }}</span></div>
            @if(! empty($site['tagline']))<p class="muted">{{ $site['tagline'] }}</p>@endif
        </div>
        <div>
            <h4>Visit</h4>
            @foreach($branches->take(3) as $b)<p class="muted">{{ $b->name }}{{ $b->address ? ' — ' . $b->address : '' }}{{ $b->city ? ', ' . $b->city : '' }}</p>@endforeach
            @if(! empty($site['hours']))<p class="muted" style="margin-top:.5rem;white-space:pre-line">{{ $site['hours'] }}</p>@endif
        </div>
        <div>
            <h4>Talk to us</h4>
            @if($house->phone)<p><a href="tel:{{ preg_replace('/[^0-9+]/', '', $house->phone) }}">{{ $house->phone }}</a></p>@endif
            @if($house->email)<p><a href="mailto:{{ $house->email }}">{{ $house->email }}</a></p>@endif
            <p style="margin-top:.6rem;display:flex;gap:1rem">
                @if(! empty($site['instagram']))<a href="https://instagram.com/{{ ltrim($site['instagram'], '@') }}" target="_blank" rel="noopener">Instagram</a>@endif
                @if(! empty($site['tiktok']))<a href="https://tiktok.com/@{{ ltrim($site['tiktok'], '@') }}" target="_blank" rel="noopener">TikTok</a>@endif
                @if(! empty($site['facebook']))<a href="https://facebook.com/{{ ltrim($site['facebook'], '@') }}" target="_blank" rel="noopener">Facebook</a>@endif
            </p>
        </div>
        <div class="small"><span>© {{ now()->year }} {{ $name }}</span>@unless($full)<span>Made with {{ $platform }}</span>@endunless</div>
    </div>
</footer>
@if($wa)
    <a class="wa" href="https://wa.me/{{ $wa }}?text={{ rawurlencode('Hello ' . $name . ', I found you on your website.') }}" target="_blank" rel="noopener" aria-label="Chat on WhatsApp">
        <svg viewBox="0 0 32 32" aria-hidden="true"><path fill="#fff" d="M16 3C9 3 3.3 8.6 3.3 15.6c0 2.4.7 4.7 1.9 6.6L3 29l7-2.1c1.8 1 3.9 1.6 6 1.6 7 0 12.7-5.6 12.7-12.6S23 3 16 3zm0 23.2c-1.9 0-3.8-.5-5.4-1.5l-.4-.2-4.1 1.2 1.2-4-.3-.4c-1.1-1.7-1.6-3.6-1.6-5.6C5.4 9.8 10.2 5.2 16 5.2s10.6 4.6 10.6 10.5S21.8 26.2 16 26.2zm5.8-7.8c-.3-.2-1.9-.9-2.2-1-.3-.1-.5-.2-.7.2-.2.3-.8 1-1 1.2-.2.2-.4.2-.7.1-.3-.2-1.3-.5-2.5-1.5-.9-.8-1.5-1.8-1.7-2.1-.2-.3 0-.5.1-.6l.5-.6c.2-.2.2-.3.3-.5.1-.2.1-.4 0-.6l-1-2.4c-.3-.6-.5-.5-.7-.5h-.6c-.2 0-.6.1-.9.4-.3.3-1.2 1.1-1.2 2.7s1.2 3.1 1.4 3.3c.2.2 2.4 3.6 5.7 5 .8.3 1.4.5 1.9.7.8.2 1.5.2 2.1.1.6-.1 1.9-.8 2.2-1.5.3-.7.3-1.4.2-1.5-.1-.1-.3-.2-.6-.4z"/></svg>
    </a>
@endif
</body>
</html>
