@extends('sites.layout')

@push('css')
    .hero { padding: 56px 0 72px; }
    .hero .wrap { display: grid; grid-template-columns: 1fr 1fr; gap: 3.5rem; align-items: center; }
    .hero .lead { font-size: 1.15rem; color: var(--muted); margin: 1.4rem 0 2rem; max-width: 44ch; }
    .hero .cta { display: flex; gap: .8rem; flex-wrap: wrap; }
    .hero .art { aspect-ratio: 4 / 5; border-radius: 28px; overflow: hidden; background: var(--soft); position: relative; }
    .hero .art img { width: 100%; height: 100%; object-fit: cover; }
    .hero .art::after { content: ""; position: absolute; inset: 14px; border: 1.5px solid rgba(255,255,255,.65); border-radius: 18px; pointer-events: none; }
    .head { display: flex; justify-content: space-between; align-items: end; gap: 1rem; margin-bottom: 36px; flex-wrap: wrap; }
    .head a { color: var(--accent); font-weight: 600; text-decoration: none; }
    .about { background: var(--soft); }
    .about .wrap { display: grid; grid-template-columns: .9fr 1.1fr; gap: 3rem; }
    .about p { font-size: 1.1rem; white-space: pre-line; }
    .steps { list-style: none; padding: 0; margin: 2rem 0 0; display: grid; gap: .9rem; counter-reset: s; }
    .steps li { display: grid; grid-template-columns: 34px 1fr; gap: .8rem; align-items: start; }
    .steps li::before { counter-increment: s; content: counter(s); width: 30px; height: 30px; border-radius: 50%; border: 1.5px solid var(--accent); color: var(--accent); display: grid; place-items: center; font-weight: 700; font-size: .9rem; }
    @media (max-width: 900px) { .hero .wrap, .about .wrap { grid-template-columns: 1fr; } .hero .art { max-width: 460px; } }
@endpush

@section('body')
    @php $hero = ! empty($site['hero_path']) ? asset('storage/' . $site['hero_path']) : $featured->first()?->currentVersion?->cover()?->url; @endphp
    <section class="hero">
        <div class="wrap">
            <div>
                <h1>{{ $site['headline'] ?? $house->name }}</h1>
                <p class="lead">{{ $site['tagline'] ?? 'Made-to-measure clothing, cut and sewn for you.' }}</p>
                <div class="cta">
                    <a href="{{ $base }}/collection" class="btn">See the collection</a>
                    <a href="{{ $base }}/contact{{ $full ? '?type=booking' : '' }}" class="btn ghost">{{ $full ? 'Book a fitting' : 'Make an enquiry' }}</a>
                </div>
            </div>
            <div class="art">@include('sites.partials-pic', ['src' => $hero, 'alt' => $house->name])</div>
        </div>
    </section>

    @if($featured->isNotEmpty())
    <section style="padding-top:24px">
        <div class="wrap">
            <div class="head"><h2>From the collection</h2><a href="{{ $base }}/collection">See everything</a></div>
            <div class="grid">
                @foreach($featured as $s)
                    <a class="card" href="{{ $base }}/collection/{{ $s->public_id }}">
                        <div class="pic">@include('sites.partials-pic', ['src' => $s->currentVersion?->cover()?->url, 'alt' => $s->name])</div>
                        <h3>{{ $s->name }}</h3>
                        @if(($site['show_prices'] ?? true) && $s->currentVersion?->base_price > 0)<div class="price">From {{ \App\Support\Money::format($s->currentVersion->base_price) }}</div>@endif
                    </a>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    <section class="about">
        <div class="wrap">
            <h2>{{ ! empty($site['about']) ? 'About ' . $house->name : 'How we work' }}</h2>
            <div>
                @if(! empty($site['about']))<p>{{ $site['about'] }}</p>@endif
                <ol class="steps">
                    <li><span>Tell us what you have in mind, or pick a style from the collection.</span></li>
                    <li><span>We take your measurements{{ $full ? ' — in the studio, or you send them to us' : '' }}.</span></li>
                    <li><span>Your garment is cut and sewn, with fittings until it is right.</span></li>
                    <li><span>Collect it, or we deliver it to you.</span></li>
                </ol>
            </div>
        </div>
    </section>

    @if($products->isNotEmpty())
    <section>
        <div class="wrap">
            <div class="head"><h2>Ready to wear</h2><a href="{{ $base }}/shop">See all pieces</a></div>
            <div class="grid small">
                @foreach($products as $p)
                    <a class="card" href="{{ $base }}/contact?product={{ $p->public_id }}">
                        <div class="pic">@include('sites.partials-pic', ['src' => $p->image_url, 'alt' => $p->name])</div>
                        <h3>{{ $p->name }}</h3>
                        @if($site['show_prices'] ?? true)<div class="price">{{ \App\Support\Money::format($p->variants->min('price')) }}</div>@endif
                    </a>
                @endforeach
            </div>
        </div>
    </section>
    @endif
@endsection
