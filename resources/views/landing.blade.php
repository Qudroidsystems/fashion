<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $siteName }} — run your fashion house from measurement to handover</title>
    <meta name="description" content="Customers and measurements, orders and deposits, fabric stock, the shop till, production, fittings and weddings — one place for tailoring studios, bridal houses and fashion brands.">
    <meta name="theme-color" content="#1B2559">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --indigo: #1B2559;
            --ink: #121735;
            --gold: #F2A93B;
            --hibiscus: #D9466F;
            --chalk: #F6F7FB;
            --line: #C9D0EA;
            --muted: #5B6386;
            --display: "Bricolage Grotesque", "Avenir Next Condensed", "Arial Narrow", sans-serif;
            --body: "Instrument Sans", "Segoe UI", system-ui, sans-serif;
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body { margin: 0; font-family: var(--body); font-size: 17px; line-height: 1.6; color: var(--ink); background: var(--chalk); -webkit-font-smoothing: antialiased; }
        img, svg { display: block; max-width: 100%; }
        a { color: inherit; }
        a:focus-visible, button:focus-visible { outline: 3px solid var(--gold); outline-offset: 3px; border-radius: 6px; }
        .wrap { width: min(1180px, 100% - 40px); margin-inline: auto; }
        h1, h2, h3 { font-family: var(--display); line-height: 1.04; letter-spacing: -0.02em; margin: 0; }
        h2 { font-size: clamp(2rem, 4.2vw, 3.3rem); font-weight: 800; max-width: 18ch; }
        h3 { font-size: 1.3rem; font-weight: 600; letter-spacing: -0.01em; }
        p { margin: 0; }
        .lead { font-size: 1.15rem; color: var(--muted); max-width: 54ch; }

        /* Buttons */
        .btn { display: inline-flex; align-items: center; gap: .55rem; padding: .9rem 1.4rem; border-radius: 999px; font-weight: 600; text-decoration: none; border: 2px solid transparent; transition: transform .15s ease, background .15s ease; }
        .btn:hover { transform: translateY(-1px); }
        .btn-gold { background: var(--gold); color: var(--ink); }
        .btn-gold:hover { background: #f5b85a; }
        .btn-ghost { border-color: rgba(255,255,255,.4); color: #fff; }
        .btn-ghost:hover { background: rgba(255,255,255,.08); }
        .btn-ink { background: var(--indigo); color: #fff; }

        /* Header + hero */
        .hero { position: relative; color: #fff; background: var(--indigo); overflow: hidden; isolation: isolate; }
        .hero::before { /* adire dots */
            content: ""; position: absolute; inset: 0; z-index: -1; opacity: .16;
            background-image: radial-gradient(circle at center, #fff 1.6px, transparent 2px), radial-gradient(circle at center, #fff 1px, transparent 1.4px);
            background-size: 28px 28px, 28px 28px; background-position: 0 0, 14px 14px;
            mask-image: linear-gradient(110deg, transparent 30%, #000 75%);
        }
        .hero::after { /* adire ring motif */
            content: ""; position: absolute; z-index: -1; width: 760px; height: 760px; right: -220px; top: -160px; border-radius: 50%;
            background: repeating-radial-gradient(circle, rgba(255,255,255,.07) 0 2px, transparent 2px 26px);
        }
        nav.top { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 22px 0; }
        .brand { display: flex; align-items: center; gap: .6rem; font-family: var(--display); font-weight: 800; font-size: 1.2rem; text-decoration: none; letter-spacing: -0.01em; }
        .brand svg { width: 34px; height: 34px; }
        .nav-links { display: flex; align-items: center; gap: 1.6rem; }
        .nav-links a.plain { text-decoration: none; opacity: .85; font-weight: 500; }
        .nav-links a.plain:hover { opacity: 1; }
        .hero-grid { display: grid; grid-template-columns: 1.05fr 1fr; align-items: center; gap: 2rem; padding: 48px 0 0; }
        .hero h1 { font-size: clamp(2.6rem, 5.6vw, 5rem); font-weight: 800; font-variation-settings: "opsz" 96; max-width: 13ch; }
        .hero-copy { padding-bottom: 64px; }
        .hero .lead { color: #D6DBF2; margin-top: 1.4rem; }
        .hero-ctas { display: flex; flex-wrap: wrap; gap: .8rem; margin-top: 2.2rem; }
        .hero-note { margin-top: 1.2rem; font-size: .92rem; color: #AEB6DF; }
        .hero-art { position: relative; align-self: end; }
        .hero-art svg { width: 100%; height: auto; }

        /* The one orchestrated moment: the gown is drawn, the tape unrolls, the needle sews the seam. */
        .draw { stroke-dasharray: 1; stroke-dashoffset: 1; animation: draw 1.6s cubic-bezier(.6,.1,.3,1) forwards; }
        .d1 { animation-delay: .2s; } .d2 { animation-delay: .9s; } .d3 { animation-delay: 1.5s; } .d4 { animation-delay: 2.1s; }
        .fill-in { opacity: 0; animation: fade .9s ease forwards; }
        .f1 { animation-delay: 1.4s; } .f2 { animation-delay: 2.2s; } .f3 { animation-delay: 2.8s; }
        .pin { transform-box: fill-box; transform-origin: center; transform: scale(0); animation: pop .35s cubic-bezier(.3,1.6,.5,1) forwards; }
        .p1 { animation-delay: 2.9s; } .p2 { animation-delay: 3.05s; } .p3 { animation-delay: 3.2s; } .p4 { animation-delay: 3.35s; }
        .seam { stroke-dasharray: 9 8; }
        .seam-mask { stroke-dasharray: 1; stroke-dashoffset: 1; animation: draw 2.4s linear 2.2s forwards; }
        @keyframes draw { to { stroke-dashoffset: 0; } }
        @keyframes fade { to { opacity: 1; } }
        @keyframes pop { to { transform: scale(1); } }
        .rise { opacity: 0; transform: translateY(14px); animation: rise .8s ease forwards; }
        .r1 { animation-delay: .1s; } .r2 { animation-delay: .25s; } .r3 { animation-delay: .4s; }
        @keyframes rise { to { opacity: 1; transform: none; } }

        /* Sequence: the order's life */
        section { padding: 110px 0; }
        .flow-head { display: flex; justify-content: space-between; align-items: end; gap: 2rem; flex-wrap: wrap; }
        .flow { list-style: none; padding: 0; margin: 64px 0 0; display: grid; grid-template-columns: repeat(5, 1fr); position: relative; }
        .flow::before { content: ""; position: absolute; left: 6%; right: 6%; top: 46px; border-top: 3px dashed var(--line); }
        .flow li { position: relative; padding: 0 14px; text-align: left; }
        .flow .ico { width: 92px; height: 92px; border-radius: 50%; background: #fff; border: 2px solid var(--line); display: grid; place-items: center; position: relative; }
        .flow .ico svg { width: 52px; height: 52px; }
        .flow .step { position: absolute; top: -6px; right: -6px; width: 30px; height: 30px; border-radius: 50%; background: var(--indigo); color: #fff; font-family: var(--display); font-weight: 800; display: grid; place-items: center; font-size: .95rem; }
        .flow h3 { margin-top: 1.2rem; }
        .flow p { color: var(--muted); font-size: .97rem; margin-top: .4rem; }

        /* Weddings band */
        .wedding { background: #fff; border-block: 1px solid #E5E8F3; }
        .wedding-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4rem; align-items: center; }
        .wedding ul { list-style: none; padding: 0; margin: 2rem 0 0; display: grid; gap: 1.1rem; }
        .wedding li { display: grid; grid-template-columns: 28px 1fr; gap: .8rem; }
        .wedding li svg { width: 22px; height: 22px; margin-top: 4px; }
        .wedding li strong { display: block; font-weight: 600; }
        .wedding li span { color: var(--muted); font-size: .97rem; }
        .trio { position: relative; }
        .trio svg { width: 100%; height: auto; }
        .tracker { position: absolute; right: 0; bottom: 6%; width: min(270px, 60%); background: var(--ink); color: #fff; border-radius: 20px; padding: 18px 20px; box-shadow: 0 24px 50px -20px rgba(18,23,53,.55); }
        .tracker small { color: #AEB6DF; display: block; }
        .tracker .bar { height: 8px; background: rgba(255,255,255,.14); border-radius: 99px; margin: 10px 0 12px; overflow: hidden; }
        .tracker .bar i { display: block; height: 100%; width: 62%; background: var(--gold); border-radius: 99px; }
        .tracker .ms { display: flex; gap: .5rem; align-items: center; font-size: .9rem; margin-top: .3rem; }
        .tracker .dot { width: 10px; height: 10px; border-radius: 50%; border: 2px solid var(--gold); flex: none; }
        .tracker .dot.on { background: var(--gold); }

        /* Everything else, as a plain two-column list */
        .more { display: grid; grid-template-columns: .8fr 1.2fr; gap: 4rem; }
        .more dl { margin: 0; display: grid; grid-template-columns: 1fr 1fr; gap: 2.4rem 3rem; }
        .more dt { font-family: var(--display); font-weight: 600; font-size: 1.25rem; letter-spacing: -0.01em; display: flex; gap: .6rem; align-items: center; }
        .more dt svg { width: 26px; height: 26px; flex: none; }
        .more dd { margin: .5rem 0 0; color: var(--muted); }

        /* App */
        .app { background: var(--ink); color: #fff; position: relative; overflow: hidden; }
        .app::before { content: ""; position: absolute; inset: 0; opacity: .08;
            background: repeating-linear-gradient(90deg, var(--gold) 0 6px, transparent 6px 22px, var(--hibiscus) 22px 26px, transparent 26px 48px); }
        .app-grid { position: relative; display: grid; grid-template-columns: 1fr .8fr; gap: 4rem; align-items: center; }
        .app .lead { color: #C3C9E8; margin-top: 1.2rem; }
        .demo-tag { display: inline-block; background: var(--hibiscus); color: #fff; font-weight: 600; font-size: .85rem; padding: .25rem .75rem; border-radius: 999px; margin-bottom: 1.2rem; }
        .stores { display: flex; flex-wrap: wrap; gap: .8rem; margin-top: 2rem; }
        .store { display: inline-flex; align-items: center; gap: .7rem; padding: .7rem 1.2rem; border-radius: 14px; background: #fff; color: var(--ink); text-decoration: none; min-width: 210px; border: 2px solid #fff; }
        .store svg { width: 26px; height: 26px; flex: none; }
        .store small { display: block; font-size: .75rem; color: var(--muted); line-height: 1.1; }
        .store b { display: block; font-size: 1.05rem; line-height: 1.2; }
        .store.soon { background: transparent; color: #fff; border-color: rgba(255,255,255,.3); cursor: default; }
        .store.soon small { color: #AEB6DF; }
        .app-points { margin: 2rem 0 0; padding: 0; list-style: none; display: grid; gap: .5rem; color: #D6DBF2; }
        .app-points li::before { content: ""; display: inline-block; width: 18px; border-top: 2px dashed var(--gold); margin-right: .7rem; vertical-align: middle; }
        .phone { width: min(300px, 100%); margin-inline: auto; aspect-ratio: 9 / 19; border-radius: 42px; background: #0B0E22; padding: 12px; box-shadow: 0 40px 80px -30px rgba(0,0,0,.7), inset 0 0 0 2px #2B3260; transform: rotate(-4deg); }
        .screen { height: 100%; border-radius: 32px; background: var(--chalk); color: var(--ink); overflow: hidden; display: flex; flex-direction: column; }
        .screen header { background: var(--indigo); color: #fff; padding: 26px 18px 16px; }
        .screen header small { color: #AEB6DF; }
        .screen header b { font-family: var(--display); font-size: 1.35rem; display: block; }
        .task { margin: 10px 12px 0; background: #fff; border-radius: 14px; padding: 11px 12px; font-size: .82rem; line-height: 1.35; border-left: 4px solid var(--line); }
        .task b { display: block; font-size: .9rem; }
        .task span { color: var(--muted); }
        .task.now { border-left-color: var(--gold); }
        .task.rush { border-left-color: var(--hibiscus); }
        .task .row { display: flex; justify-content: space-between; margin-top: 6px; }
        .chip { font-size: .7rem; font-weight: 600; padding: 1px 8px; border-radius: 99px; background: #EEF0F8; }
        .chip.go { background: #FDECCB; color: #8A5300; }


        /* Pricing */
        .pricing-head { display: flex; justify-content: space-between; align-items: end; gap: 2rem; flex-wrap: wrap; }
        .period { display: inline-flex; background: #fff; border: 2px solid var(--line); border-radius: 999px; padding: 4px; }
        .period button { border: 0; background: transparent; font: inherit; font-weight: 600; color: var(--muted); padding: .5rem 1.1rem; border-radius: 999px; cursor: pointer; }
        .period button[aria-pressed="true"] { background: var(--indigo); color: #fff; }
        .plans { display: grid; grid-template-columns: repeat(var(--n, 4), 1fr); gap: 0; margin-top: 48px; border: 2px solid var(--indigo); border-radius: 24px; overflow: hidden; background: #fff; }
        .plan { padding: 30px 26px 32px; display: flex; flex-direction: column; border-left: 1px dashed var(--line); position: relative; }
        .plan:first-child { border-left: 0; }
        .plan.pick { background: var(--indigo); color: #fff; }
        .plan.pick .muted, .plan.pick li { color: #D6DBF2; }
        .plan .tag { position: absolute; top: 18px; right: 18px; background: var(--gold); color: var(--ink); font-size: .78rem; font-weight: 600; padding: .15rem .65rem; border-radius: 999px; }
        .plan h3 { font-size: 1.5rem; font-weight: 800; }
        .plan .muted { color: var(--muted); font-size: .95rem; min-height: 2.9em; margin-top: .3rem; }
        .plan .price { font-family: var(--display); font-weight: 800; font-size: 2.3rem; letter-spacing: -0.03em; margin: 1.2rem 0 .2rem; line-height: 1; }
        .plan .per { font-size: .9rem; }
        .plan ul { list-style: none; padding: 0; margin: 1.4rem 0 1.8rem; display: grid; gap: .55rem; font-size: .95rem; flex: 1; align-content: start; }
        .plan .plus { font-size: .85rem; font-weight: 600; margin: .4rem 0 -.1rem; color: inherit; opacity: .8; display: block; }
        .plan li { display: grid; grid-template-columns: 18px 1fr; gap: .55rem; }
        .plan li svg { width: 16px; height: 16px; margin-top: 5px; }
        .plan li.limit { font-weight: 600; }
        .plan .btn { justify-content: center; }
        .plan .btn-line { border-color: var(--line); color: var(--indigo); }
        .pricing-note { margin-top: 1.4rem; color: var(--muted); font-size: .95rem; }
        @media (max-width: 1080px) { .plans { grid-template-columns: repeat(2, 1fr); } .plan:nth-child(3) { border-left: 0; } .plan:nth-child(n+3) { border-top: 1px dashed var(--line); } }
        @media (max-width: 640px) { .plans { grid-template-columns: 1fr; } .plan { border-left: 0; } .plan + .plan { border-top: 1px dashed var(--line); } }

        /* Closing + footer */
        .close { text-align: center; padding: 120px 0; }
        .close h2 { margin-inline: auto; }
        .close .hero-ctas { justify-content: center; }
        .close .btn-ghost { color: var(--indigo); border-color: var(--line); }
        footer { border-top: 1px solid #E1E4F0; padding: 34px 0; font-size: .92rem; color: var(--muted); }
        footer .wrap { display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }

        @media (max-width: 960px) {
            .hero-grid, .wedding-grid, .more, .app-grid { grid-template-columns: 1fr; }
            .hero-art { max-width: 460px; margin-inline: auto; }
            .flow { grid-template-columns: 1fr; gap: 2rem; margin-top: 40px; }
            .flow::before { left: 45px; right: auto; top: 0; bottom: 0; border-top: 0; border-left: 3px dashed var(--line); }
            .flow li { display: grid; grid-template-columns: 92px 1fr; gap: 1.2rem; align-items: start; padding: 0; }
            .flow h3 { margin-top: .6rem; }
            .more dl { grid-template-columns: 1fr; gap: 1.8rem; }
            .nav-links a.plain { display: none; }
            section { padding: 80px 0; }
            .phone { transform: none; }
        }
        @media (max-width: 480px) {
            .wrap { width: calc(100% - 32px); }
            .tracker { position: relative; width: 100%; margin-top: -20px; }
            .store { min-width: 0; width: 100%; }
            .btn { width: 100%; justify-content: center; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
            .draw, .seam-mask { stroke-dashoffset: 0; }
            .fill-in, .rise { opacity: 1; transform: none; }
            .pin { transform: scale(1); }
            .needle { display: none; }
        }
    </style>
</head>
<body>

<header class="hero">
    <div class="wrap">
        <nav class="top" aria-label="Main">
            <a href="{{ route('home') }}" class="brand">
                <svg viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="20" r="19" fill="#F2A93B"/><path d="M11 29 L28 10" stroke="#1B2559" stroke-width="3" stroke-linecap="round"/><ellipse cx="26.6" cy="11.6" rx="1.4" ry="2.6" transform="rotate(42 26.6 11.6)" fill="none" stroke="#F6F7FB" stroke-width="1.4"/><path d="M11 29 C 16 33, 24 31, 30 26" stroke="#D9466F" stroke-width="2" fill="none" stroke-dasharray="3 3"/></svg>
                {{ $siteName }}
            </a>
            <div class="nav-links">
                <a href="#how" class="plain">How it works</a>
                <a href="#weddings" class="plain">Weddings</a>
                <a href="#app" class="plain">Mobile app</a>
                @if($plans->isNotEmpty())<a href="#pricing" class="plain">Pricing</a>@endif
                @if($signedIn)
                    <a href="{{ route('dashboard') }}" class="btn btn-ghost" style="padding:.55rem 1.1rem">Open dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="btn btn-ghost" style="padding:.55rem 1.1rem">Sign in</a>
                @endif
            </div>
        </nav>

        <div class="hero-grid">
            <div class="hero-copy">
                <h1 class="rise r1">From the tape measure to the final fitting.</h1>
                <p class="lead rise r2">One place for your customers’ measurements, orders and deposits, fabric stock, the shop till, the workroom and every wedding you dress. Built for tailoring studios, bridal houses and fashion brands.</p>
                <div class="hero-ctas rise r3">
                    @if($signedIn)
                        <a href="{{ route('dashboard') }}" class="btn btn-gold">Open your dashboard</a>
                    @else
                        @if($canSignUp)<a href="{{ route('register') }}" class="btn btn-gold">Set up your fashion house</a>@endif
                        <a href="{{ route('login') }}" class="btn {{ $canSignUp ? 'btn-ghost' : 'btn-gold' }}">Sign in</a>
                    @endif
                </div>
                @if($canSignUp)<p class="hero-note rise r3">Free trial. No card needed. Your data stays yours.</p>@endif
            </div>

            <div class="hero-art" aria-hidden="true">
                <svg viewBox="0 0 520 600" role="img">
                    <defs>
                        <pattern id="adire" width="18" height="18" patternUnits="userSpaceOnUse">
                            <rect width="18" height="18" fill="#2A3880"/><circle cx="9" cy="9" r="3.2" fill="none" stroke="#C9D0EA" stroke-width="1.4"/><circle cx="0" cy="0" r="1.4" fill="#C9D0EA"/><circle cx="18" cy="18" r="1.4" fill="#C9D0EA"/>
                        </pattern>
                        <pattern id="asooke" width="16" height="16" patternUnits="userSpaceOnUse" patternTransform="rotate(-20)">
                            <rect width="16" height="16" fill="#F2A93B"/><rect width="16" height="4" fill="#D9466F"/><rect y="9" width="16" height="2" fill="#121735"/>
                        </pattern>
                        <pattern id="lace" width="22" height="22" patternUnits="userSpaceOnUse">
                            <rect width="22" height="22" fill="#FBEFF3"/><path d="M11 3 C15 7 15 15 11 19 C7 15 7 7 11 3Z" fill="none" stroke="#E8A4B8" stroke-width="1.2"/><circle cx="11" cy="11" r="1.6" fill="#E8A4B8"/>
                        </pattern>
                        <mask id="sew"><path class="seam-mask" pathLength="1" d="M30 560 C 120 520, 170 590, 260 560 S 420 520, 500 570" stroke="#fff" stroke-width="14" fill="none"/></mask>
                    </defs>

                    {{-- fabric swatches --}}
                    <g class="fill-in f1">
                        <circle cx="80" cy="150" r="54" fill="url(#adire)" stroke="#C9D0EA" stroke-width="2"/>
                        <rect x="400" y="96" width="92" height="92" rx="18" fill="url(#asooke)" transform="rotate(12 446 142)"/>
                    </g>

                    {{-- dress form --}}
                    <g stroke="#C9D0EA" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path class="draw d1" pathLength="1" d="M248 64 h24 v28 h-24 z"/>
                        <path class="draw d1" pathLength="1" d="M200 100 C170 110 150 130 150 165 C150 200 170 225 180 250 C186 265 176 290 172 320 C168 345 175 360 190 368 L330 368 C345 360 352 345 348 320 C344 290 334 265 340 250 C350 225 370 200 370 165 C370 130 350 110 320 100 C300 94 280 92 260 92 C240 92 220 94 200 100 Z"/>
                        <path class="draw d2" pathLength="1" d="M260 368 V540 M260 540 L196 584 M260 540 L324 584 M260 540 V590"/>
                    </g>
                    <ellipse class="fill-in f1" cx="260" cy="62" rx="24" ry="8" fill="#C9D0EA"/>

                    {{-- the gown, drawn onto the form --}}
                    <path class="fill-in f2" d="M190 112 C215 132 240 142 260 142 C280 142 305 132 330 112 L338 170 C334 205 326 230 322 252 C352 330 392 430 410 540 Q395 556 380 540 Q365 556 350 540 Q335 556 320 540 Q305 556 290 540 Q275 556 260 540 Q245 556 230 540 Q215 556 200 540 Q185 556 170 540 Q155 556 140 540 Q125 556 110 540 C128 430 168 330 198 252 C194 230 186 205 182 170 Z" fill="url(#lace)"/>
                    <path class="draw d3" pathLength="1" d="M190 112 C215 132 240 142 260 142 C280 142 305 132 330 112 L338 170 C334 205 326 230 322 252 C352 330 392 430 410 540 Q395 556 380 540 Q365 556 350 540 Q335 556 320 540 Q305 556 290 540 Q275 556 260 540 Q245 556 230 540 Q215 556 200 540 Q185 556 170 540 Q155 556 140 540 Q125 556 110 540 C128 430 168 330 198 252 C194 230 186 205 182 170 Z" fill="none" stroke="#D9466F" stroke-width="3" stroke-linejoin="round"/>
                    <path class="draw d4" pathLength="1" d="M198 252 C230 262 290 262 322 252" fill="none" stroke="#F2A93B" stroke-width="5" stroke-linecap="round"/>

                    {{-- pins --}}
                    <g fill="#F2A93B" stroke="#121735" stroke-width="1.5">
                        <circle class="pin p1" cx="214" cy="160" r="6"/><circle class="pin p2" cx="306" cy="160" r="6"/>
                        <circle class="pin p3" cx="230" cy="300" r="6"/><circle class="pin p4" cx="296" cy="330" r="6"/>
                    </g>

                    {{-- tape measure unrolling over the shoulder --}}
                    <g>
                        <path class="draw d2" pathLength="1" d="M160 128 C 210 70, 330 70, 382 150 C 420 210, 440 300, 470 360" fill="none" stroke="#F2A93B" stroke-width="20" stroke-linecap="round"/>
                        <path class="draw d3" pathLength="1" d="M160 128 C 210 70, 330 70, 382 150 C 420 210, 440 300, 470 360" fill="none" stroke="#121735" stroke-width="7" stroke-dasharray="2 10" opacity=".6"/>
                        <circle class="fill-in f2" cx="470" cy="372" r="26" fill="#F2A93B" stroke="#121735" stroke-width="3"/>
                        <circle class="fill-in f2" cx="470" cy="372" r="8" fill="#121735"/>
                    </g>

                    {{-- the seam being sewn, and the needle doing it --}}
                    <path class="seam" mask="url(#sew)" d="M30 560 C 120 520, 170 590, 260 560 S 420 520, 500 570" fill="none" stroke="#F6F7FB" stroke-width="3" stroke-linecap="round"/>
                    <g class="needle" opacity="0">
                        <path d="M-16 0 L16 0" stroke="#E7EAF6" stroke-width="3" stroke-linecap="round"/>
                        <ellipse cx="12" cy="0" rx="3.4" ry="1.6" fill="none" stroke="#121735" stroke-width="1.2"/>
                        <animate attributeName="opacity" from="0" to="1" begin="2.2s" dur=".2s" fill="freeze"/>
                        <animateMotion begin="2.2s" dur="2.4s" fill="freeze" rotate="auto" path="M30 560 C 120 520, 170 590, 260 560 S 420 520, 500 570"/>
                    </g>
                </svg>
            </div>
        </div>
    </div>
</header>

<main>
    <section id="how">
        <div class="wrap">
            <div class="flow-head">
                <h2>Every garment, from the first measurement to handover.</h2>
                <p class="lead">Each step picks up where the last one left off, so nothing is written on scraps of paper and nobody asks “where is Mrs Eze’s gown?”.</p>
            </div>
            <ol class="flow">
                <li>
                    <div class="ico"><span class="step">1</span>
                        <svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="26" cy="32" r="18" fill="#F2A93B"/><circle cx="26" cy="32" r="6" fill="#1B2559"/><path d="M26 50 H58 V40 H40" fill="#F2A93B" stroke="#1B2559" stroke-width="2.5" stroke-linejoin="round"/><path d="M46 50v-4M51 50v-6M56 50v-4" stroke="#1B2559" stroke-width="2"/></svg></div>
                    <div><h3>Measure</h3><p>Inches or centimetres, saved per customer and compared with last time. Customers can send their own through a private link.</p></div>
                </li>
                <li>
                    <div class="ico"><span class="step">2</span>
                        <svg viewBox="0 0 64 64" aria-hidden="true"><path d="M16 8 H48 V56 L42 52 L36 56 L30 52 L24 56 L16 52 Z" fill="#fff" stroke="#1B2559" stroke-width="2.5" stroke-linejoin="round"/><path d="M23 20h18M23 28h18M23 36h10" stroke="#C9D0EA" stroke-width="3" stroke-linecap="round"/><circle cx="44" cy="40" r="10" fill="#F2A93B" stroke="#1B2559" stroke-width="2"/><path d="M40 44 V36 L48 44 V36 M38.5 39 h11 M38.5 41.5 h11" stroke="#1B2559" stroke-width="1.6" fill="none"/></svg></div>
                    <div><h3>Quote &amp; deposit</h3><p>Turn a quotation into an order, take the deposit at the till, by transfer or online with Paystack, and always know the balance.</p></div>
                </li>
                <li>
                    <div class="ico"><span class="step">3</span>
                        <svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="18" cy="46" r="8" fill="none" stroke="#1B2559" stroke-width="3"/><circle cx="36" cy="50" r="8" fill="none" stroke="#1B2559" stroke-width="3"/><path d="M23 40 L52 10 M31 43 L56 20" stroke="#1B2559" stroke-width="3" stroke-linecap="round"/><path d="M8 24 C 20 18, 30 30, 44 22" stroke="#D9466F" stroke-width="2.5" stroke-dasharray="4 4" fill="none"/></svg></div>
                    <div><h3>Cut &amp; sew</h3><p>The workroom board shows each tailor their next task, due dates worked back from the promise date, and the fabric issued for it.</p></div>
                </li>
                <li>
                    <div class="ico"><span class="step">4</span>
                        <svg viewBox="0 0 64 64" aria-hidden="true"><path d="M22 10 C26 14 38 14 42 10 L46 22 C44 30 42 32 41 36 C46 44 50 50 52 56 H12 C14 50 18 44 23 36 C22 32 20 30 18 22 Z" fill="#FBEFF3" stroke="#D9466F" stroke-width="2.5" stroke-linejoin="round"/><circle cx="26" cy="26" r="2.6" fill="#F2A93B"/><circle cx="38" cy="26" r="2.6" fill="#F2A93B"/><path d="M23 36 C29 38 35 38 41 36" stroke="#F2A93B" stroke-width="3" stroke-linecap="round"/></svg></div>
                    <div><h3>Fit</h3><p>Book fittings with reminders the day before. Alterations found at the fitting go straight back to the workroom.</p></div>
                </li>
                <li>
                    <div class="ico"><span class="step">5</span>
                        <svg viewBox="0 0 64 64" aria-hidden="true"><path d="M32 6 a5 5 0 1 1 0 10 V20" stroke="#1B2559" stroke-width="2.5" fill="none" stroke-linecap="round"/><path d="M32 20 L10 32 H54 Z" fill="none" stroke="#1B2559" stroke-width="2.5" stroke-linejoin="round"/><rect x="16" y="32" width="32" height="26" rx="4" fill="#1B2559"/><path d="M32 32 V58" stroke="#F2A93B" stroke-width="2" stroke-dasharray="3 3"/><circle cx="32" cy="45" r="4" fill="#F2A93B"/></svg></div>
                    <div><h3>Hand over</h3><p>Quality-checked, paid for and collected, with who picked it up and when. The order’s full history stays on file.</p></div>
                </li>
            </ol>
        </div>
    </section>

    <section id="weddings" class="wedding">
        <div class="wrap wedding-grid">
            <div class="trio" aria-hidden="true">
                <svg viewBox="0 0 560 470">
                    <defs>
                        <path id="gown" d="M-70 0 C-55 20 -30 30 0 30 C30 30 55 20 70 0 L78 58 C74 93 66 118 62 140 C92 218 132 318 150 428 Q120 446 90 428 Q60 446 30 428 Q0 446 -30 428 Q-60 446 -90 428 Q-120 446 -150 428 C-132 318 -92 218 -62 140 C-66 118 -74 93 -78 58 Z"/>
                        <pattern id="lace2" width="20" height="20" patternUnits="userSpaceOnUse"><rect width="20" height="20" fill="#FFFFFF"/><path d="M10 3 C13.5 6.5 13.5 13.5 10 17 C6.5 13.5 6.5 6.5 10 3Z" fill="none" stroke="#E2C9A5" stroke-width="1.1"/></pattern>
                        <pattern id="maid" width="14" height="14" patternUnits="userSpaceOnUse"><rect width="14" height="14" fill="#D9466F"/><circle cx="7" cy="7" r="2" fill="#E4708F"/></pattern>
                    </defs>
                    <circle cx="280" cy="250" r="210" fill="#F6F7FB"/>
                    <circle cx="280" cy="250" r="210" fill="none" stroke="#C9D0EA" stroke-width="2" stroke-dasharray="6 10"/>
                    <g transform="translate(130 70) scale(.72)"><use href="#gown" fill="url(#maid)" stroke="#9E2649" stroke-width="3"/><path d="M-62 140 C-30 150 30 150 62 140" stroke="#F2A93B" stroke-width="7" fill="none"/><ellipse cx="0" cy="-34" rx="22" ry="26" fill="#6B3E2E"/></g>
                    <g transform="translate(430 70) scale(.72)"><use href="#gown" fill="url(#maid)" stroke="#9E2649" stroke-width="3"/><path d="M-62 140 C-30 150 30 150 62 140" stroke="#F2A93B" stroke-width="7" fill="none"/><ellipse cx="0" cy="-34" rx="22" ry="26" fill="#4A2A20"/></g>
                    <g transform="translate(280 40) scale(.9)">
                        <path d="M0 -60 C60 -40 120 120 160 420 L-160 420 C-120 120 -60 -40 0 -60Z" fill="#FFFFFF" opacity=".55"/>
                        <use href="#gown" fill="url(#lace2)" stroke="#C9A16A" stroke-width="3"/>
                        <path d="M-62 140 C-30 150 30 150 62 140" stroke="#F2A93B" stroke-width="6" fill="none"/>
                        <ellipse cx="0" cy="-34" rx="22" ry="26" fill="#5A3324"/>
                        <path d="M-24 -54 C-10 -66 10 -66 24 -54" stroke="#F2A93B" stroke-width="5" fill="none" stroke-linecap="round"/>
                    </g>
                </svg>
                <div class="tracker">
                    <small>What the bride sees</small>
                    <strong>Funmi &amp; Dayo’s wedding</strong>
                    <div class="bar"><i></i></div>
                    <div class="ms"><span class="dot on"></span>Lace bought and cut</div>
                    <div class="ms"><span class="dot on"></span>First fittings done</div>
                    <div class="ms"><span class="dot"></span>Final fittings, Sat 14 Nov</div>
                </div>
            </div>
            <div>
                <h2>Weddings, aso-ebi and uniforms, without the spreadsheet.</h2>
                <p class="lead" style="margin-top:1.2rem">Add the whole party at once, give every bridesmaid the same gown in one step, and let guests who pay for their own aso-ebi have their own bill.</p>
                <ul>
                    <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12 C7 9 10 15 13 12 S19 9 21 12" stroke="#D9466F" stroke-width="2.5" fill="none" stroke-dasharray="3 3"/></svg>
                        <div><strong>Everyone’s own measurements</strong><span>Measure each person once and link it to their garment.</span></div></li>
                    <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12 C7 9 10 15 13 12 S19 9 21 12" stroke="#D9466F" stroke-width="2.5" fill="none" stroke-dasharray="3 3"/></svg>
                        <div><strong>A private progress page for the client</strong><span>They see the milestones and photos you choose to publish, never your prices or notes.</span></div></li>
                    <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12 C7 9 10 15 13 12 S19 9 21 12" stroke="#D9466F" stroke-width="2.5" fill="none" stroke-dasharray="3 3"/></svg>
                        <div><strong>Budget against what you really spent</strong><span>Fabric taken from stock and outside costs, next to what the client has paid.</span></div></li>
                </ul>
            </div>
        </div>
    </section>

    <section>
        <div class="wrap more">
            <div>
                <h2>The rest of the house, too.</h2>
                <p class="lead" style="margin-top:1.2rem">Use it for one shop or many branches. Each person sees only what their role needs.</p>
            </div>
            <dl>
                <div><dt><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="none" stroke="#1B2559" stroke-width="2"/><circle cx="12" cy="12" r="3" fill="#F2A93B"/></svg>Fabric &amp; stock</dt>
                    <dd>Lace by the yard, rolls and dye lots, remnants, purchase orders from your suppliers, and a warning before you run out.</dd></div>
                <div><dt><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="13" rx="2" fill="none" stroke="#1B2559" stroke-width="2"/><path d="M3 10h18" stroke="#F2A93B" stroke-width="3"/></svg>Shop till</dt>
                    <dd>Scan ready-made pieces, split cash and card, print receipts, and close each cashier’s drawer with a blind count.</dd></div>
                <div><dt><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="4" fill="none" stroke="#1B2559" stroke-width="2"/><path d="M2 20c1-4 4-6 7-6s6 2 7 6" fill="none" stroke="#1B2559" stroke-width="2"/><path d="M17 4l1.2 2.4L21 7l-2 2 .5 2.8L17 10.5 14.5 11.8 15 9 13 7l2.8-.6z" fill="#F2A93B"/></svg>Customers</dt>
                    <dd>Style preferences, birthdays, follow-ups and every order in one timeline, with duplicates caught as you type.</dd></div>
                <div><dt><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z" fill="none" stroke="#1B2559" stroke-width="2"/><path d="M8.5 12l2.5 2.5 4.5-5" stroke="#F2A93B" stroke-width="2.5" fill="none"/></svg>Who did what</dt>
                    <dd>Approvals for big discounts, refunds and stock changes, and a record of every change that nobody can edit.</dd></div>
            </dl>
        </div>
    </section>


    @if($plans->isNotEmpty())
    <section id="pricing">
        <div class="wrap">
            <div class="pricing-head">
                <div>
                    <h2>Start free. Pay when you grow.</h2>
                    <p class="lead" style="margin-top:1rem">Every plan includes customers, measurements, styles, quotes, orders, invoices and the workroom board. Change plan any time; nothing you add is ever deleted.</p>
                </div>
                <div class="period" role="group" aria-label="Billing period">
                    <button type="button" data-period="monthly" aria-pressed="true">Monthly</button>
                    <button type="button" data-period="yearly" aria-pressed="false">Yearly, 2 months free</button>
                </div>
            </div>
            <div class="plans" style="--n: {{ min(4, $plans->count()) }}">
                @foreach($plans as $p)
                    @php $prev = $loop->index ? $plans[$loop->index - 1] : null; $extra = $prev ? collect($p->featureLabels())->diff($prev->featureLabels()) : collect($p->featureLabels()); @endphp
                    <div class="plan {{ $p->highlight ? 'pick' : '' }}">
                        @if($p->highlight)<span class="tag">Most chosen</span>@endif
                        <h3>{{ $p->name }}</h3>
                        <p class="muted">{{ $p->tagline }}</p>
                        <div class="price" data-monthly="{{ $p->price_monthly > 0 ? \App\Support\Money::format($p->price_monthly) : 'Free' }}" data-yearly="{{ $p->price_yearly > 0 ? \App\Support\Money::format($p->price_yearly) : 'Free' }}">{{ $p->price_monthly > 0 ? \App\Support\Money::format($p->price_monthly) : 'Free' }}</div>
                        <div class="per" data-monthly="{{ $p->price_monthly > 0 ? 'a month' : 'for ever' }}" data-yearly="{{ $p->price_yearly > 0 ? 'a year' : 'for ever' }}">{{ $p->price_monthly > 0 ? 'a month' : 'for ever' }}</div>
                        <ul>
                            @foreach(\App\Support\Billing\Features::LIMITS as $k => $l)
                                <li class="limit"><svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6" fill="none" stroke="#F2A93B" stroke-width="2"/></svg>
                                    {{ $p->limit($k) === null ? 'Unlimited ' . strtolower($l[2]) : number_format($p->limit($k)) . ' ' . ($p->limit($k) === 1 ? $l[1] : $l[2]) }}</li>
                            @endforeach
                            @if($prev && $extra->isNotEmpty())<li class="plus" style="display:block">Everything in {{ $prev->name }}, plus:</li>@endif
                            @foreach($extra as $f)
                                <li><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2 8.5l4 4 8-9" fill="none" stroke="{{ $p->highlight ? '#F2A93B' : '#1B2559' }}" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>{{ $f }}</li>
                            @endforeach
                        </ul>
                        @if($signedIn)
                            <a href="{{ route('billing.index') }}" class="btn {{ $p->highlight ? 'btn-gold' : 'btn-ghost btn-line' }}">See your plan</a>
                        @elseif($canSignUp)
                            <a href="{{ route('register') }}" class="btn {{ $p->highlight ? 'btn-gold' : 'btn-ghost btn-line' }}">{{ $p->isPaid() ? 'Start your free trial' : 'Start free' }}</a>
                        @else
                            <a href="{{ route('login') }}" class="btn {{ $p->highlight ? 'btn-gold' : 'btn-ghost btn-line' }}">Sign in</a>
                        @endif
                    </div>
                @endforeach
            </div>
            <p class="pricing-note">New houses get {{ config('tenancy.trial_days') }} days of {{ optional($plans->firstWhere('code', config('billing.trial_plan')))->name ?? 'the full product' }} free, with no card. Afterwards you stay on Free unless you choose a plan. Prices are in naira and paid securely with Paystack.</p>
        </div>
    </section>
    @endif

    <section id="app" class="app">
        <div class="wrap app-grid">
            <div>
                @if($mobile['is_demo'])<span class="demo-tag">Demo app available</span>@endif
                <h2>Your workroom in your pocket.</h2>
                <p class="lead">The staff app shows each tailor their tasks for the day, lets designers take measurements beside the customer, and tells the manager when an order is at risk of running late.</p>
                <ul class="app-points">
                    <li>Start and finish tasks, add a photo as proof</li>
                    <li>Measure a customer in inches or centimetres</li>
                    <li>Book fittings and record what changed</li>
                </ul>
                <div class="stores">
                    @php $android = $mobile['android_url'] ?? null; $ios = $mobile['ios_url'] ?? null; @endphp
                    <a class="store {{ $android ? '' : 'soon' }}" @if($android) href="{{ $android }}" target="_blank" rel="noopener" @else aria-disabled="true" @endif>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 3.5v17l9.3-8.5z" fill="#2ECC71"/><path d="M4 3.5l12.5 7-3.2 2z" fill="#4285F4"/><path d="M4 20.5l12.5-7-3.2-2z" fill="#EA4335"/><path d="M16.5 10.5L20 12.5l-3.5 2-3.2-2.5z" fill="#F2A93B"/></svg>
                        <span><small>{{ $android ? 'Download the demo for' : 'Coming soon on' }}</small><b>Android</b></span>
                    </a>
                    <a class="store {{ $ios ? '' : 'soon' }}" @if($ios) href="{{ $ios }}" target="_blank" rel="noopener" @else aria-disabled="true" @endif>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16.4 12.6c0-2.4 2-3.5 2-3.6-1.1-1.6-2.8-1.8-3.4-1.8-1.4-.2-2.8.9-3.5.9-.7 0-1.9-.8-3.1-.8-1.6 0-3.1.9-3.9 2.4-1.7 2.9-.4 7.2 1.2 9.5.8 1.1 1.7 2.4 2.9 2.4 1.2 0 1.6-.8 3-.8s1.8.8 3.1.7c1.3 0 2.1-1.2 2.8-2.3.9-1.3 1.3-2.6 1.3-2.6s-2.4-1-2.4-4zM14.2 5.5c.6-.8 1.1-1.9 1-3-.9 0-2.1.6-2.7 1.4-.6.7-1.1 1.8-1 2.9 1 .1 2.1-.5 2.7-1.3z" fill="currentColor"/></svg>
                        <span><small>{{ $ios ? 'Download the demo on' : 'Coming soon on' }}</small><b>iPhone</b></span>
                    </a>
                </div>
            </div>
            <div class="phone" aria-hidden="true">
                <div class="screen">
                    <header><small>Thursday, my work</small><b>Good morning, Bisi</b></header>
                    <div class="task now"><b>Sewing</b><span>Mermaid lace gown, for Temi Cole</span><div class="row"><span class="chip go">In progress</span><span>Due today</span></div></div>
                    <div class="task rush"><b>Rework: hem uneven</b><span>Senator kaftan, ORD-00418</span><div class="row"><span class="chip">Rush</span><span>Due today</span></div></div>
                    <div class="task"><b>Cutting</b><span>Agbada set, for Dayo Adeyemi</span><div class="row"><span class="chip">To do</span><span>Fri 13 Nov</span></div></div>
                    <div class="task"><b>Fitting</b><span>Bridesmaid gowns, 3 people</span><div class="row"><span class="chip">Booked</span><span>Sat 10:00</span></div></div>
                </div>
            </div>
        </div>
    </section>

    <section class="close">
        <div class="wrap">
            <h2>Bring your next order in here.</h2>
            <p class="lead" style="margin:1.2rem auto 0">Set up your house in a few minutes. Your staff join with their own logins and see only what they need.</p>
            <div class="hero-ctas">
                @if($signedIn)
                    <a href="{{ route('dashboard') }}" class="btn btn-ink">Open your dashboard</a>
                @else
                    @if($canSignUp)<a href="{{ route('register') }}" class="btn btn-ink">Set up your fashion house</a>@endif
                    <a href="{{ route('login') }}" class="btn {{ $canSignUp ? 'btn-ghost' : 'btn-ink' }}">Sign in</a>
                @endif
            </div>
        </div>
    </section>
</main>

<footer>
    <div class="wrap">
        <span>© {{ now()->year }} {{ $siteName }}@if(config('landing.contact_email')) · <a href="mailto:{{ config('landing.contact_email') }}">{{ config('landing.contact_email') }}</a>@endif @if(config('landing.contact_phone')) · {{ config('landing.contact_phone') }}@endif</span>
        <span>Built by <a href="https://qudroids.com" target="_blank" rel="noopener">Qudroid Systems</a></span>
    </div>
</footer>

<script>
(() => {
    const btns = document.querySelectorAll('[data-period]');
    btns.forEach(b => b.addEventListener('click', () => {
        btns.forEach(x => x.setAttribute('aria-pressed', x === b ? 'true' : 'false'));
        document.querySelectorAll('.plan [data-monthly]').forEach(el => el.textContent = el.dataset[b.dataset.period]);
    }));
})();
</script>
</body>
</html>
